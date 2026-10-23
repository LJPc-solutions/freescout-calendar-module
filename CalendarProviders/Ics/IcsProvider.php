<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Ics;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use LogicException;
use Modules\LJPcCalendarModule\CalendarProviders\CalendarProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\CalendarProviders\ResponseTooLarge;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Psr\Http\Message\ResponseInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

/**
 * A read-only ICS feed (calendars.remote_id is its URL). Every pull reads the whole feed and splits it by UID; an
 * unchanged feed (304, or the same content apart from DTSTAMPs) is skipped, except an empty one, which is read in full
 * again. The URL may hold a secret token, so it is never logged, and neither are the feed's error pages.
 */
class IcsProvider implements CalendarProvider
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const TZID_PROPERTIES = ['DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE'];

    private HttpClientFactory $http;

    public function __construct(HttpClientFactory $http)
    {
        $this->http = $http;
    }

    public function canWrite(): bool
    {
        return false;
    }

    public function writableRecurrence(VEvent $master): bool
    {
        return false;
    }

    public function listCalendars(CalendarConnection $connection, ?string $owner = null): array
    {
        return [];
    }

    public function pull(Calendar $calendar, array $state, SyncBudget $budget): ChangeSet
    {
        $url = self::feedUrl((string) $calendar->remote_id);
        $this->http->assertAllowed($url); // also refuses an empty or malformed address before anything is sent
        $response = $this->fetch($calendar, $url, $state);

        $status = $response->getStatusCode();
        if ($status === 304) {
            return new ChangeSet([], [], $state, true, false);
        }
        if ($status < 200 || $status >= 300) {
            $this->refuse($calendar, $url, $response);
        }
        $body = $this->body($calendar, $url, $response);
        $newState = [
            'etag'          => $response->getHeaderLine('ETag') ?: null,
            'last_modified' => $response->getHeaderLine('Last-Modified') ?: null,
            'hash'          => self::contentHash($body),
        ];
        if (($state['hash'] ?? null) === $newState['hash']) {
            return new ChangeSet([], [], $newState, true, false);
        }
        $events = $this->split($calendar, $url, $body);
        if ($events === []) {
            // Without its validators, so the next pull reads the feed in full again: the engine empties a calendar
            // only at the second empty listing in a row, which a 304 or the same hash would never be.
            return new ChangeSet([], [], [], true, true);
        }

        return new ChangeSet($events, [], $newState, true, true);
    }

    /** Where the feed is read: webcal:// is how calendar apps are told to subscribe, and the feed is served over https. */
    public static function feedUrl(string $address): string
    {
        return (string) preg_replace('~^webcals?://~i', 'https://', $address);
    }

    /** The feed's content without DTSTAMP lines: some feeds (Google's export) stamp every request with the time. */
    public static function contentHash(string $body): string
    {
        return sha1((string) preg_replace('/^DTSTAMP[;:][^\r\n]*\r?\n/m', '', $body));
    }

    public function create(Calendar $calendar, VCalendar $vcal): RemoteRef
    {
        throw new LogicException('ICS feeds are read-only.');
    }

    public function update(Calendar $calendar, CalendarEvent $event, VCalendar $vcal, ?string $occurrenceKey = null): RemoteRef
    {
        throw new LogicException('ICS feeds are read-only.');
    }

    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        throw new LogicException('ICS feeds are read-only.');
    }

    private function fetch(Calendar $calendar, string $url, array $state): ResponseInterface
    {
        $headers = [];
        if (!empty($state['etag'])) {
            $headers['If-None-Match'] = $state['etag'];
        }
        if (!empty($state['last_modified'])) {
            $headers['If-Modified-Since'] = $state['last_modified'];
        }
        $tooLarge = false;
        try {
            // The body as curl decoded it stops at the limit too: a compressed feed is far larger than its transfer.
            return $this->http->client([], true, self::MAX_BYTES)->request('GET', $url, [
                'headers'  => $headers,
                // Stops the download as soon as it passes the limit; a true return aborts the transfer.
                'progress' => function ($downloadTotal, $downloaded) use (&$tooLarge) {
                    $tooLarge = $downloadTotal > self::MAX_BYTES || $downloaded > self::MAX_BYTES;

                    return $tooLarge;
                },
            ]);
        } catch (ResponseTooLarge $e) {
            $this->tooLarge($calendar, $url);
        } catch (MalformedUriException $e) {
            // An address Guzzle can't build a request for (a host with a space in it), which the host check let through.
            HttpClientFactory::logFailure('A calendar feed address is not valid', ['calendar_id' => $calendar->id, 'provider' => Calendar::PROVIDER_ICS, 'url' => $url, 'error' => $e->getMessage()]);
            throw new ProviderUnavailable(__('This is not a valid http or https address.'));
        } catch (GuzzleException $e) {
            if ($tooLarge) {
                $this->tooLarge($calendar, $url);
            }
            HttpClientFactory::logFailure('A calendar feed could not be fetched', ['calendar_id' => $calendar->id, 'provider' => Calendar::PROVIDER_ICS, 'url' => $url, 'error' => $e->getMessage()]);
            // Not kept as the previous exception: Guzzle's message ends with the full URL.
            throw new ProviderUnavailable();
        }
    }

    private function refuse(Calendar $calendar, string $url, ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        // Without the body: error pages repeat the requested path ("The requested URL /private/<token>/... was not found").
        HttpClientFactory::logFailure('A calendar feed answered with an error', ['calendar_id' => $calendar->id, 'provider' => Calendar::PROVIDER_ICS, 'status' => $status, 'url' => $url]);
        if ($status === 401 || $status === 403) {
            throw new ProviderUnavailable(__('The calendar feed refused access (HTTP :status).', ['status' => $status]), $status);
        }
        if ($status === 404 || $status === 410) {
            throw new ProviderUnavailable(__('The calendar feed was not found (HTTP :status).', ['status' => $status]), $status);
        }
        throw new ProviderUnavailable(null, $status);
    }

    private function body(Calendar $calendar, string $url, ResponseInterface $response): string
    {
        $length = $response->getHeaderLine('Content-Length');
        $size = $response->getBody()->getSize();
        if ((ctype_digit($length) && (int) $length > self::MAX_BYTES) || ($size !== null && $size > self::MAX_BYTES)) {
            $this->tooLarge($calendar, $url);
        }
        $body = (string) $response->getBody();
        if (strlen($body) > self::MAX_BYTES) {
            $this->tooLarge($calendar, $url);
        }

        return $body;
    }

    private function tooLarge(Calendar $calendar, string $url): void
    {
        HttpClientFactory::logFailure('A calendar feed is over the size limit', ['calendar_id' => $calendar->id, 'provider' => Calendar::PROVIDER_ICS, 'url' => $url]);
        throw new ProviderUnavailable(__('The calendar feed is too large (over 20 MB).'));
    }

    /**
     * One VCALENDAR per UID: every VEVENT of that UID (master and overrides) and the VTIMEZONEs they use. To-dos and
     * journals are dropped. An event without a UID gets sha1(DTSTART . SUMMARY . its position in the feed), which
     * stays the same from pull to pull as long as the feed keeps its order.
     *
     * @return RemoteEvent[]
     */
    private function split(Calendar $calendar, string $url, string $body): array
    {
        $feed = null;
        $error = 'The document is not a VCALENDAR';
        try {
            $feed = Reader::read($body, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (ParseException $e) {
            $error = $e->getMessage();
        }
        if (!$feed instanceof VCalendar) {
            HttpClientFactory::logFailure('A calendar feed is not iCalendar', ['calendar_id' => $calendar->id, 'provider' => Calendar::PROVIDER_ICS, 'url' => $url, 'error' => $error]);
            throw new ProviderUnavailable(__('The calendar feed is not a valid iCalendar file.'));
        }

        $zones = [];
        foreach ($feed->select('VTIMEZONE') as $zone) {
            $zones[(string) $zone->TZID] = $zone;
        }
        $byUid = [];
        foreach ($feed->select('VEVENT') as $index => $vevent) {
            $uid = (string) ($vevent->UID ?? '');
            if (trim($uid) === '') {
                $uid = sha1((string) ($vevent->DTSTART ?? '') . (string) ($vevent->SUMMARY ?? '') . ($index + 1));
                $vevent->UID = $uid;
            }
            $byUid[$uid][] = $vevent;
        }

        $events = [];
        foreach ($byUid as $uid => $vevents) {
            $vcal = new VCalendar(['PRODID' => (string) ($feed->PRODID ?? 'ICS feed')]);
            foreach (self::timezoneIds($vevents) as $tzid) {
                if (isset($zones[$tzid])) {
                    $vcal->add(clone $zones[$tzid]);
                }
            }
            foreach ($vevents as $vevent) {
                $vcal->add(clone $vevent);
            }
            $events[] = new RemoteEvent((string) $uid, $vcal->serialize(), null, null, true);
        }

        return $events;
    }

    /** @param VEvent[] $vevents */
    private static function timezoneIds(array $vevents): array
    {
        $ids = [];
        foreach ($vevents as $vevent) {
            foreach (self::TZID_PROPERTIES as $name) {
                foreach ($vevent->select($name) as $property) {
                    if (isset($property['TZID'])) {
                        $ids[(string) $property['TZID']] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }
}
