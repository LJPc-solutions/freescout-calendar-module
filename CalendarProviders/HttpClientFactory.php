<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Log;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Psr\Http\Message\RequestInterface;

/**
 * Builds the HTTP client of every provider: core's Guzzle with FreeScout's timeout, proxy and TLS options. A client
 * for an address an administrator typed checks the host of every request against FreeScout's SSRF rules, the first
 * one and every redirect. A singleton; tests replace it with Tests\Support\FakeHttp.
 */
class HttpClientFactory
{
    public const MAX_REDIRECTS = 5;
    public const LOG_BODY_LENGTH = 500;

    private const SECRETS = 'access_token|refresh_token|id_token|client_secret|password';

    /** While set, the longest an API may wait out throttling per request (OAuthApi): someone waits for the requests. */
    private ?float $waitLimit = null;

    /**
     * A Guzzle client with core's defaults. With $checkHosts, the URL and every redirect hop pass \Helper::checkUrlHost().
     * With $maxBodyBytes, every response body (each redirect hop and digest leg) is cut off once curl has decoded that
     * many bytes of it, and the request throws ResponseTooLarge.
     */
    public function client(array $options = [], bool $checkHosts = false, ?int $maxBodyBytes = null): Client
    {
        $options = \Helper::setGuzzleDefaultOptions($options);
        foreach (['timeout', 'connect_timeout'] as $key) {
            // .env values arrive as strings, and Guzzle 8 only accepts numbers here.
            if (isset($options[$key]) && is_numeric($options[$key])) {
                $options[$key] = (float) $options[$key];
            }
        }
        $options['http_errors'] = false;
        $stack = $this->handlerStack();
        if ($checkHosts && ($options['allow_redirects'] ?? null) !== false) {
            // A caller may ask for strict redirects (a PROPFIND stays a PROPFIND) or for their history, never for more
            // hops, other schemes or a Referer.
            $asked = is_array($options['allow_redirects'] ?? null) ? $options['allow_redirects'] : [];
            $options['allow_redirects'] = ['max' => self::MAX_REDIRECTS, 'protocols' => ['http', 'https'], 'referer' => false] + $asked;
        }
        if ($maxBodyBytes !== null) {
            // Below the redirect and auth middleware, so every leg gets a sink of its own: Guzzle's digest login would
            // otherwise swap a caller's sink for an unlimited one while the body arrives.
            $stack->push(function (callable $handler) use ($maxBodyBytes): callable {
                return function (RequestInterface $request, array $requestOptions) use ($handler, $maxBodyBytes) {
                    $sink = new CappedSink($maxBodyBytes);
                    $requestOptions['sink'] = $sink;

                    return $handler($request, $requestOptions)->then(function ($response) use ($sink) {
                        if ($sink->exceeded()) {
                            throw new ResponseTooLarge('A response body passed ' . $sink->limit() . ' bytes');
                        }

                        return $response;
                    }, function ($reason) use ($sink) {
                        if ($sink->exceeded()) {
                            throw new ResponseTooLarge('A response body passed ' . $sink->limit() . ' bytes', 0, $reason instanceof \Throwable ? $reason : null);
                        }

                        return Create::rejectionFor($reason);
                    });
                };
            }, 'ljpc_body_cap');
        }
        if ($checkHosts) {
            // Pushed last, so it wraps the handler itself: it sees the first request and every redirect before they are sent.
            $stack->push(function (callable $handler): callable {
                return function (RequestInterface $request, array $requestOptions) use ($handler) {
                    $this->assertAllowed((string) $request->getUri());

                    return $handler($request, $requestOptions);
                };
            }, 'ljpc_host_check');
        }
        $options['handler'] = $stack;

        return new Client($options);
    }

    /**
     * Runs $call while an API waits out throttling for at most $seconds per request, as for a request someone waits for
     * (the sync after a conflict). The limit before it applies again afterwards.
     *
     * @return mixed what $call returns
     */
    public function waitingAtMost(float $seconds, callable $call)
    {
        $previous = $this->waitLimit;
        $this->waitLimit = $previous === null ? $seconds : min($previous, $seconds);
        try {
            return $call();
        } finally {
            $this->waitLimit = $previous;
        }
    }

    /** The longest throttling wait per request while waitingAtMost() runs; null otherwise. */
    public function waitLimit(): ?float
    {
        return $this->waitLimit;
    }

    /** Throws ProviderUnavailable with "This address is not allowed..." when the host is blocked. */
    public function assertAllowed(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host) || $host === '') {
            throw new ProviderUnavailable(__('This is not a valid http or https address.'));
        }
        // Only the scheme and the host are checked: core reads "host:port" and "user:password@host" as an IPv6
        // address and blocks it, and neither the port nor credentials change which machine is asked.
        try {
            $allowed = \Helper::checkUrlHost($scheme . '://' . $host . '/', true);
        } catch (\Exception $e) {
            throw new ProviderUnavailable(__('This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST.'), null, $e);
        }
        // Core answers an empty string, without an exception, for any other scheme.
        if ($allowed === '') {
            throw new ProviderUnavailable(__('This is not a valid http or https address.'));
        }
    }

    /** At most 500 characters of a response body, with tokens, secrets and passwords replaced by ***. */
    public static function scrub(string $body): string
    {
        // Cut first: replacing only shortens the text, so nothing past the cut can move into view. A value cut off
        // at the end is still matched, because its closing quote is optional.
        $text = mb_substr($body, 0, self::LOG_BODY_LENGTH);
        $patterns = [
            '/(Authorization\s*:\s*)[^\r\n]*/i'                                   => '$1***',
            '/\b(Bearer\s+)[A-Za-z0-9\-._~+\/]+=*/i'                              => '$1***',
            '/("(?:' . self::SECRETS . ')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"?/i'       => '$1"***"',
            '/\b((?:' . self::SECRETS . ')=)[^&\s"]*/i'                          => '$1***',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * The context a provider failure is logged with: calendar_id, connection_id, provider and status as given, the
     * host of `host` or `url`, and `body` and `error` scrubbed, with addresses in `error` reduced to their host.
     * Everything else is dropped. A body can echo the request's path, so callers whose URL is secret leave it out.
     */
    public static function logContext(array $context): array
    {
        $clean = [];
        foreach (['calendar_id', 'connection_id', 'provider', 'status'] as $key) {
            if (array_key_exists($key, $context)) {
                $clean[$key] = $context[$key];
            }
        }
        if (isset($context['host'])) {
            $clean['host'] = (string) (parse_url((string) $context['host'], PHP_URL_HOST) ?: $context['host']);
        } elseif (isset($context['url'])) {
            // A URL without a recognizable host is never logged as it is: it may be a secret feed address.
            $clean['host'] = (string) (parse_url((string) $context['url'], PHP_URL_HOST) ?: '[address]');
        }
        if (isset($context['body'])) {
            $clean['body'] = self::scrub((string) $context['body']);
        }
        if (isset($context['error'])) {
            $clean['error'] = self::scrub(self::withoutUrls((string) $context['error']));
        }

        return $clean;
    }

    public static function logFailure(string $message, array $context): void
    {
        Log::warning($message, self::logContext($context));
    }

    /** The handler stack of every client; FakeHttp puts its mock handler here. */
    protected function handlerStack(): HandlerStack
    {
        return HandlerStack::create();
    }

    /** Guzzle's error messages end with the full URL, which may be secret (an ICS feed); only the host is kept. */
    private static function withoutUrls(string $text): string
    {
        return (string) preg_replace_callback('~\b[a-z][a-z0-9+.\-]*://[^\s"\'<>]+~i', function (array $match) {
            return (string) (parse_url($match[0], PHP_URL_HOST) ?: '[address]');
        }, $text);
    }
}
