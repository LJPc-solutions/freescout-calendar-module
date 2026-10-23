<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\CalDav;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\RedirectMiddleware;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\CalendarProviders\ResponseTooLarge;
use Psr\Http\Message\ResponseInterface;
use Sabre\Uri;
use Sabre\Uri\InvalidUriException;
use Sabre\Xml\ParseException;
use Sabre\Xml\Service;

/**
 * WebDAV and CalDAV requests to one account, through the HTTP client factory: every address (the first request and
 * every redirect hop) passes FreeScout's host check. Basic auth, and digest when the server asks for it. Multistatus
 * answers become [absolute href => ['status' => int, 'props' => [Clark name => value]]], with the hrefs normalized
 * (Sabre\Uri\normalize) so the same resource always has the same address. A property value is a string (text), an
 * array of child elements (['name', 'value', 'attributes'], as sabre/xml reads them) or null (empty).
 */
class CalDavClient
{
    public const NS_DAV = 'DAV:';
    public const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';
    public const NS_CALSERVER = 'http://calendarserver.org/ns/';
    public const NS_APPLE = 'http://apple.com/ns/ical/';

    /** The largest answer read: a multiget of 50 objects stays far below it unless they carry big attachments. */
    public const MAX_BYTES = 50 * 1024 * 1024;

    private const PREFIXES = [self::NS_DAV => 'd', self::NS_CALDAV => 'c', self::NS_CALSERVER => 'cs', self::NS_APPLE => 'ic'];

    private HttpClientFactory $http;

    private string $username;

    private string $password;

    private array $logContext;

    /** Set once a server answered basic auth with a digest challenge; later requests use digest right away. */
    private bool $digest = false;

    private ?Client $client = null;

    /** $logContext names the calendar or the connection in log lines (calendar_id, connection_id). */
    public function __construct(HttpClientFactory $http, string $username, string $password, array $logContext = [])
    {
        $this->http = $http;
        $this->username = $username;
        $this->password = $password;
        $this->logContext = $logContext + ['provider' => 'caldav'];
    }

    /**
     * @param string[] $props Clark names, such as "{DAV:}getetag"
     *
     * @return array<string, array{status: int, props: array}> keyed by absolute href
     */
    public function propfind(string $url, int $depth, array $props): array
    {
        [$response, $base] = $this->send('PROPFIND', $url, self::xmlRequest($depth, self::propfindBody($props)));
        if (!in_array($response->getStatusCode(), [200, 207], true)) {
            throw $this->failure($base, $response);
        }

        return $this->multiStatus($response, $base)['responses'];
    }

    /** @return array{responses: array<string, array{status: int, props: array}>, sync_token: ?string} */
    public function report(string $url, string $xml, int $depth = 1): array
    {
        [$response, $base] = $this->send('REPORT', $url, self::xmlRequest($depth, $xml));
        if (!in_array($response->getStatusCode(), [200, 207], true)) {
            throw $this->failure($base, $response);
        }

        return $this->multiStatus($response, $base);
    }

    /**
     * A calendar object's iCalendar text and ETag, or null when it is gone (404 or 410).
     *
     * @return array{ics: string, etag: ?string}|null
     */
    public function get(string $url): ?array
    {
        [$response, $base] = $this->send('GET', $url, ['headers' => ['Accept' => 'text/calendar']]);
        $status = $response->getStatusCode();
        if ($status === 404 || $status === 410) {
            return null;
        }
        if ($status !== 200) {
            throw $this->failure($base, $response);
        }

        return ['ics' => (string) $response->getBody(), 'etag' => self::strongEtag($response)];
    }

    /**
     * Writes a calendar object. $create sends If-None-Match: *, so an existing object is never overwritten; otherwise
     * a known $etag is sent as If-Match, and without one If-Match: *, so an object deleted elsewhere is never created
     * again. A failed precondition is a ProviderConflict. Returns the new ETag when the server sent a strong one (a
     * server that changed the data sends none, RFC 4791 section 5.3.4).
     */
    public function put(string $url, string $ics, ?string $etag, bool $create): ?string
    {
        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
        if ($create) {
            $headers['If-None-Match'] = '*';
        } else {
            $headers['If-Match'] = $etag !== null && $etag !== '' ? $etag : '*';
        }
        [$response, $base] = $this->send('PUT', $url, ['headers' => $headers, 'body' => $ics]);
        if (!in_array($response->getStatusCode(), [200, 201, 204], true)) {
            throw $this->failure($base, $response, true);
        }

        return self::strongEtag($response);
    }

    /** Deletes a calendar object, with If-Match when the ETag is known. An object that is already gone counts as deleted. */
    public function delete(string $url, ?string $etag): void
    {
        [$response, $base] = $this->send('DELETE', $url, ['headers' => $etag !== null && $etag !== '' ? ['If-Match' => $etag] : []]);
        $status = $response->getStatusCode();
        if (in_array($status, [200, 202, 204, 404, 410], true)) {
            return;
        }
        // If-Match fails on an object that no longer exists too (RFC 9110 section 13.1.1); that delete has succeeded.
        if ($status === 412 && !$this->exists($url)) {
            return;
        }
        throw $this->failure($base, $response, true);
    }

    /** Whether the server still has something at this address. */
    public function exists(string $url): bool
    {
        try {
            $responses = $this->propfind($url, 0, ['{DAV:}getetag']);
        } catch (CalDavRequestFailed $e) {
            if (in_array($e->httpStatus(), [404, 410], true)) {
                return false;
            }
            throw $e;
        }
        // A 207 can still say 404 for the resource itself.
        $first = reset($responses);

        return $first === false || !in_array($first['status'], [404, 410], true);
    }

    /** @param string[] $props */
    public static function propfindBody(array $props): string
    {
        return self::document('d:propfind', '<d:prop>' . self::elements($props) . '</d:prop>');
    }

    /** An RFC 6578 sync-collection report: changes since $token (the empty token lists everything). */
    public static function syncCollectionBody(string $token, array $props): string
    {
        return self::document('d:sync-collection', '<d:sync-token>' . self::escape($token) . '</d:sync-token>'
            . '<d:sync-level>1</d:sync-level><d:prop>' . self::elements($props) . '</d:prop>');
    }

    /** A calendar-multiget report for these absolute addresses; each is sent as its path. */
    public static function multigetBody(array $urls, array $props): string
    {
        $hrefs = '';
        foreach ($urls as $url) {
            $hrefs .= '<d:href>' . self::escape((string) (Uri\parse($url)['path'] ?? $url)) . '</d:href>';
        }

        return self::document('c:calendar-multiget', '<d:prop>' . self::elements($props) . '</d:prop>' . $hrefs);
    }

    /** A property's text, trimmed; null when it is empty or holds elements. */
    public static function text($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return string[] the absolute hrefs inside a property such as current-user-principal or calendar-home-set */
    public static function hrefs($value, string $base): array
    {
        $hrefs = [];
        foreach (is_array($value) ? $value : [] as $element) {
            if (($element['name'] ?? null) === '{DAV:}href' && self::text($element['value'] ?? null) !== null) {
                $hrefs[] = self::absolute($base, (string) self::text($element['value']));
            }
        }

        return $hrefs;
    }

    /** @return string[] the Clark names of every element inside a property, at any depth (resourcetype, privilege and report sets) */
    public static function names($value): array
    {
        $names = [];
        foreach (is_array($value) ? $value : [] as $element) {
            if (isset($element['name'])) {
                $names[] = (string) $element['name'];
                array_push($names, ...self::names($element['value'] ?? null));
            }
        }

        return $names;
    }

    /** @return string[] the component names (VEVENT, VTODO) a supported-calendar-component-set lists */
    public static function components($value): array
    {
        $components = [];
        foreach (is_array($value) ? $value : [] as $element) {
            if (($element['name'] ?? null) === '{' . self::NS_CALDAV . '}comp' && isset($element['attributes']['name'])) {
                $components[] = strtoupper((string) $element['attributes']['name']);
            }
        }

        return $components;
    }

    /** An href resolved against the address that answered, normalized, so one resource has one address. */
    public static function absolute(string $base, string $href): string
    {
        try {
            return Uri\normalize(Uri\resolve($base, $href));
        } catch (InvalidUriException $e) {
            return $href;
        }
    }

    /** Whether two addresses name the same resource, with or without a trailing slash. */
    public static function sameResource(string $a, string $b): bool
    {
        return rtrim(self::absolute($a, $a), '/') === rtrim(self::absolute($b, $b), '/');
    }

    /** @return array{0: ResponseInterface, 1: string} the response and the address that answered it (after redirects) */
    private function send(string $method, string $url, array $options): array
    {
        $options['auth'] = $this->auth();
        $tooLarge = false;
        // Stops a download as soon as it passes the limit; a true return aborts the transfer.
        $options['progress'] = function ($downloadTotal, $downloaded) use (&$tooLarge) {
            $tooLarge = $downloadTotal > self::MAX_BYTES || $downloaded > self::MAX_BYTES;

            return $tooLarge;
        };
        try {
            $response = $this->client()->request($method, $url, $options);
            if ($response->getStatusCode() === 401 && !$this->digest && self::asksForDigest($response)) {
                $this->digest = true;
                $options['auth'] = $this->auth();
                $response = $this->client()->request($method, $url, $options);
            }
            $base = self::finalUrl($url, $response);
            // Guzzle drops the credentials when a redirect leads to another host; that host is asked again, with them.
            if ($response->getStatusCode() === 401 && self::mayResendCredentials($url, $base)) {
                Log::info('A CalDAV address redirected to another host, which is asked again with the credentials', $this->logContext + [
                    'from' => (string) parse_url($url, PHP_URL_HOST), 'to' => (string) parse_url($base, PHP_URL_HOST),
                ]);
                $url = $base;
                $response = $this->client()->request($method, $url, $options);
                $base = self::finalUrl($url, $response);
            }
        } catch (ResponseTooLarge $e) {
            $this->tooLarge($url);
        } catch (GuzzleException $e) {
            if ($tooLarge) {
                $this->tooLarge($url);
            }
            HttpClientFactory::logFailure('A CalDAV request failed', $this->logContext + ['url' => $url, 'error' => $e->getMessage()]);
            // Not kept as the previous exception: Guzzle's message holds the full address.
            throw new ProviderUnavailable();
        } catch (MalformedUriException $e) {
            // An address no request can be made to (a port past 65535, a space in the host), typed by an administrator
            // or named by the server; Guzzle refuses it before sending anything.
            HttpClientFactory::logFailure('A CalDAV address is not valid', $this->logContext + ['url' => $url, 'error' => $e->getMessage()]);
            throw new ProviderUnavailable(__('This is not a valid http or https address.'));
        } catch (InvalidArgumentException $e) {
            // Guzzle refused to send the request at all, such as a username that basic authentication can't carry.
            HttpClientFactory::logFailure('A CalDAV request could not be sent', $this->logContext + ['url' => $url, 'error' => $e->getMessage()]);
            throw new ProviderUnavailable();
        }
        $length = $response->getHeaderLine('Content-Length');
        $size = $response->getBody()->getSize();
        if ((ctype_digit($length) && (int) $length > self::MAX_BYTES) || ($size !== null && $size > self::MAX_BYTES)) {
            $this->tooLarge($base);
        }

        return [$response, $base];
    }

    private function tooLarge(string $url): void
    {
        HttpClientFactory::logFailure('A CalDAV answer is over the size limit', $this->logContext + ['url' => $url]);
        throw new CalDavAnswerTooLarge();
    }

    private function client(): Client
    {
        if ($this->client === null) {
            // Strict redirects keep a PROPFIND a PROPFIND (/.well-known/caldav answers with a redirect), and their
            // history says which address answered, which relative hrefs are resolved against. The body as curl decoded
            // it stops at the limit (a compressed answer is far larger than its transfer).
            $this->client = $this->http->client(['allow_redirects' => ['strict' => true, 'track_redirects' => true]], true, self::MAX_BYTES);
        }

        return $this->client;
    }

    private function auth(): array
    {
        return $this->digest ? [$this->username, $this->password, 'digest'] : [$this->username, $this->password];
    }

    /**
     * A 403 without a precondition names the login when reading (a server that hides a calendar from this login); a
     * write it refuses is refused by rights or a rule of the server, which a login that reads fine says nothing about.
     */
    private function failure(string $url, ResponseInterface $response, bool $write = false): ProviderException
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        HttpClientFactory::logFailure('A CalDAV server answered with an error', $this->logContext + ['status' => $status, 'url' => $url, 'body' => $body]);
        if ($status === 412) {
            return new ProviderConflict($status);
        }
        $preconditions = self::preconditions($body);
        if ($status === 401 || ($status === 403 && !$preconditions && !$write)) {
            $message = __('The CalDAV server refused the username or password.');
        } elseif ($status === 403) {
            $message = __('The CalDAV server refused this request (HTTP :status).', ['status' => $status]);
        } elseif ($status === 404 || $status === 410) {
            $message = __('The CalDAV server has nothing at this address (HTTP :status).', ['status' => $status]);
        } else {
            $message = null; // the generic "could not be reached"; the engine adds the status
        }

        return new CalDavRequestFailed($message, $status, $preconditions);
    }

    /** @return array{responses: array<string, array{status: int, props: array}>, sync_token: ?string} */
    private function multiStatus(ResponseInterface $response, string $base): array
    {
        $root = null;
        $tree = null;
        try {
            $tree = (new Service())->parse((string) $response->getBody(), null, $root);
        } catch (ParseException $e) {
            $root = null;
        }
        if ($root !== '{DAV:}multistatus') {
            HttpClientFactory::logFailure('A CalDAV server sent an answer that is not a multistatus', $this->logContext + ['status' => $response->getStatusCode(), 'url' => $base, 'body' => (string) $response->getBody()]);
            throw new ProviderUnavailable(__('The CalDAV server sent an answer FreeScout can\'t read.'), $response->getStatusCode());
        }

        $responses = [];
        $token = null;
        foreach (is_array($tree) ? $tree : [] as $element) {
            if ($element['name'] === '{DAV:}sync-token') {
                $token = self::text($element['value']);
            } elseif ($element['name'] === '{DAV:}response' && is_array($element['value'])) {
                [$hrefs, $entry] = self::response($element['value']);
                foreach ($hrefs as $href) {
                    $responses[self::absolute($base, $href)] = $entry;
                }
            }
        }

        return ['responses' => $responses, 'sync_token' => $token];
    }

    /** @return array{0: string[], 1: array{status: int, props: array}} the hrefs of one response and what it says about them */
    private static function response(array $children): array
    {
        $hrefs = [];
        $status = null;
        $props = [];
        $propstatStatus = null;
        foreach ($children as $child) {
            if ($child['name'] === '{DAV:}href' && self::text($child['value']) !== null) {
                $hrefs[] = (string) self::text($child['value']);
            } elseif ($child['name'] === '{DAV:}status') {
                $status = self::statusCode($child['value']);
            } elseif ($child['name'] === '{DAV:}propstat' && is_array($child['value'])) {
                $code = null;
                $found = [];
                foreach ($child['value'] as $part) {
                    if ($part['name'] === '{DAV:}status') {
                        $code = self::statusCode($part['value']);
                    } elseif ($part['name'] === '{DAV:}prop' && is_array($part['value'])) {
                        foreach ($part['value'] as $property) {
                            $found[$property['name']] = $property['value'];
                        }
                    }
                }
                // Properties the server does not have come back in a 404 propstat; only the found ones count.
                if ($code !== null && $code >= 200 && $code < 300) {
                    $props = $found + $props;
                    $propstatStatus = 200;
                } else {
                    $propstatStatus = $propstatStatus ?? $code;
                }
            }
        }

        return [$hrefs, ['status' => $status ?? $propstatStatus ?? 200, 'props' => $props]];
    }

    private static function statusCode($value): ?int
    {
        return is_string($value) && preg_match('~^\s*HTTP/\S+\s+(\d{3})~i', $value, $match) ? (int) $match[1] : null;
    }

    /** @return string[] the local names of the preconditions in an error body (<d:error><d:valid-sync-token/></d:error>) */
    private static function preconditions(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }
        $root = null;
        try {
            $tree = (new Service())->parse($body, null, $root);
        } catch (ParseException $e) {
            return [];
        }
        if ($root !== '{DAV:}error') {
            return [];
        }

        return array_values(array_unique(array_map(function (string $name) {
            return (string) preg_replace('/^\{[^}]*\}/', '', $name);
        }, self::names($tree))));
    }

    private static function asksForDigest(ResponseInterface $response): bool
    {
        $challenges = implode(',', $response->getHeader('WWW-Authenticate'));

        return preg_match('/(?:^|,)\s*Digest\s/i', $challenges) === 1 && preg_match('/(?:^|,)\s*Basic\b/i', $challenges) !== 1;
    }

    private static function finalUrl(string $url, ResponseInterface $response): string
    {
        $history = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);

        return $history ? (string) end($history) : $url;
    }

    /** After a redirect to another host, never from https down to http. */
    private static function mayResendCredentials(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }
        $a = Uri\parse($from);
        $b = Uri\parse($to);
        $crossOrigin = [strtolower((string) $a['scheme']), strtolower((string) $a['host']), $a['port']]
            !== [strtolower((string) $b['scheme']), strtolower((string) $b['host']), $b['port']];

        return $crossOrigin && !(strtolower((string) $a['scheme']) === 'https' && strtolower((string) $b['scheme']) !== 'https');
    }

    private static function strongEtag(ResponseInterface $response): ?string
    {
        $etag = trim($response->getHeaderLine('ETag'));

        return $etag !== '' && stripos($etag, 'W/') !== 0 ? $etag : null;
    }

    private static function xmlRequest(int $depth, string $body): array
    {
        return ['headers' => ['Depth' => (string) $depth, 'Content-Type' => 'application/xml; charset=utf-8'], 'body' => $body];
    }

    private static function document(string $root, string $content): string
    {
        $namespaces = '';
        foreach (self::PREFIXES as $namespace => $prefix) {
            $namespaces .= ' xmlns:' . $prefix . '="' . $namespace . '"';
        }

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<' . $root . $namespaces . '>' . $content . '</' . $root . '>';
    }

    /** @param string[] $props Clark names in one of the four namespaces above */
    private static function elements(array $props): string
    {
        $xml = '';
        foreach ($props as $clark) {
            if (!preg_match('/^\{([^}]+)\}([A-Za-z0-9._-]+)$/', $clark, $match) || !isset(self::PREFIXES[$match[1]])) {
                throw new InvalidArgumentException('Unsupported property ' . $clark);
            }
            $xml .= '<' . self::PREFIXES[$match[1]] . ':' . $match[2] . '/>';
        }

        return $xml;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
