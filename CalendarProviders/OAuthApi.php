<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

use GuzzleHttp\Exception\GuzzleException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Psr\Http\Message\ResponseInterface;

/**
 * Authenticated JSON requests to the API of an OAuth provider (Google Calendar, Microsoft Graph), as the connection's
 * account. A refused token (401) is renewed once; throttling is retried at most MAX_RETRIES times, after Retry-After or
 * with exponential backoff and jitter. Every other failure becomes a ProviderException with our own message for the
 * provider (failure()), never the provider's text, and is logged with a scrubbed body. Requests are sent one at a time
 * and never follow a redirect, which would carry the token to another address. Never call it inside a database
 * transaction: it may refresh the access token under a row lock.
 */
abstract class OAuthApi
{
    public const MAX_RETRIES = 3;
    /** All waits of one request together; a longer Retry-After ends it at once, and the next sync tries again. */
    public const MAX_WAIT_SECONDS = 15;
    /** The same for a request someone waits for (an agent's change, the pick screen): pass ['max_wait' => this]. */
    public const INTERACTIVE_MAX_WAIT_SECONDS = 4;

    private HttpClientFactory $http;

    private ConnectionService $connections;

    /** @var callable(float): void */
    private $sleep;

    /** $sleep waits the given number of seconds; tests pass one that only records them. */
    public function __construct(HttpClientFactory $http, ConnectionService $connections, ?callable $sleep = null)
    {
        $this->http = $http;
        $this->connections = $connections;
        $this->sleep = $sleep ?? function (float $seconds): void {
            usleep((int) round($seconds * 1000000));
        };
    }

    /**
     * One request; $path is relative to the API's address (url()). Returns the status and the decoded JSON for a 2xx
     * answer and for the statuses in $accept, unless that answer is throttling, which a caller can't handle on its own;
     * every other answer throws what failure() makes of it. $options are Guzzle's, plus `max_wait` (seconds, default
     * MAX_WAIT_SECONDS) for the throttling waits, which HttpClientFactory::waitingAtMost() lowers. $logContext adds
     * calendar_id to what a failure logs.
     *
     * @return array{0: int, 1: array}
     */
    public function request(CalendarConnection $connection, string $method, string $path, array $options = [], array $accept = [], array $logContext = []): array
    {
        $context = $logContext + ['connection_id' => $connection->id, 'provider' => $this->provider()];
        $maxWait = (float) ($options['max_wait'] ?? self::MAX_WAIT_SECONDS);
        unset($options['max_wait']);
        if ($this->http->waitLimit() !== null) {
            $maxWait = min($maxWait, $this->http->waitLimit()); // someone waits for this request (HttpClientFactory::waitingAtMost())
        }
        $url = $this->url($path);
        $token = $this->connections->accessToken($connection);
        $renewed = false;
        $retries = 0;
        $waited = 0.0;
        while (true) {
            $response = $this->send($method, $url, $options, $token, $context);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $json = json_decode($body, true);
            $json = is_array($json) ? $json : [];
            if ($status === 401 && !$renewed) {
                $renewed = true;
                $token = $this->connections->renewAccessToken($connection, $token);
                continue;
            }
            if ($this->isRateLimit($status, $json) && $retries < self::MAX_RETRIES) {
                $wait = self::retryAfter($response) ?? 2 ** $retries + random_int(0, 1000) / 1000;
                if ($waited + $wait <= $maxWait) {
                    ($this->sleep)($wait);
                    $waited += $wait;
                    $retries++;
                    continue;
                }
            }
            if (($status >= 200 && $status < 300) || (in_array($status, $accept, true) && !$this->isLimited($status, $json))) {
                return [$status, $json];
            }
            HttpClientFactory::logFailure($this->failureLog(), $context + ['status' => $status, 'body' => $body]);

            throw $this->failure($status, $json);
        }
    }

    /** The provider's name in logs: google or microsoft. */
    abstract protected function provider(): string;

    /** The message a failed request is logged with. */
    abstract protected function failureLog(): string;

    /** The absolute address of $path. */
    abstract protected function url(string $path): string;

    /** Throttling the provider wants retried after a wait. */
    abstract protected function isRateLimit(int $status, array $json): bool;

    /** Our own exception for a failed answer: its message is translated and never the provider's text. */
    abstract protected function failure(int $status, array $json): ProviderException;

    /** Answers that are never handed to a caller, also when it accepts their status: throttling, unless more is meant. */
    protected function isLimited(int $status, array $json): bool
    {
        return $this->isRateLimit($status, $json);
    }

    /** Headers the provider wants on every request, next to the token and Accept. */
    protected function headers(): array
    {
        return [];
    }

    private function send(string $method, string $url, array $options, string $token, array $context): ResponseInterface
    {
        $options['headers'] = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'] + $this->headers() + ($options['headers'] ?? []);
        try {
            // The provider's API never redirects; following one would carry the token to another address.
            return $this->http->client(['allow_redirects' => false])->request($method, $url, $options);
        } catch (GuzzleException $e) {
            HttpClientFactory::logFailure($this->failureLog(), $context + ['error' => $e->getMessage()]);
            throw new ProviderUnavailable();
        }
    }

    /** Seconds from a Retry-After header (seconds or an HTTP date); null without one. */
    private static function retryAfter(ResponseInterface $response): ?float
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $time = strtotime($value);

        return $time === false ? null : (float) max(0, $time - time());
    }
}
