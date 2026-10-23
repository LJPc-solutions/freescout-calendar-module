<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\CalDav;

use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\LJPcCalendarModule\CalendarProviders\CalendarProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\VTimezone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Two-way CalDAV. calendars.remote_id is the collection's address; an event's remote id and href are the address of
 * its calendar object, and its etag is the object's ETag.
 *
 * Pulling: once a first listing is done, a server with sync-collection (RFC 6578) reports what changed since the
 * token; a server without it is listed again whenever its getctag changed. A listing reads every member's ETag
 * (PROPFIND, depth 1) and downloads only new and changed objects (calendar-multiget, 50 at a time). It walks the
 * members in href order and remembers where the budget stopped it, so the next run continues there. The first listing
 * never uses sync-collection, because Zoho answers an empty token with an empty list (DAVx5's notes on Zoho); the
 * token is read from the collection (RFC 6578 section 4) before the listing starts, so nothing changed during the
 * listing is missed. Changes reported by a token are downloaded within the budget too; the rest waits in "pending".
 * An object the server refuses to hand out keeps its stored copy and is asked for again at the next sync ("retry").
 *
 * Sync state: sync_token, supports_sync, ctag, pending (hrefs still to download), pending_deletes (deletes held back
 * until pending is empty, so a renamed object is found by its UID before its old href goes), retry (hrefs that could
 * not be read), more (the server holds more changes), and while a listing is unfinished, listing {after, sync_token,
 * ctag, supports_sync, unreadable}.
 *
 * A login the server refuses (a final 401) sets the connection to needs_reauth, like a refused OAuth grant: the account's
 * calendars stop syncing and refuse writes until an administrator logs in again.
 */
class CalDavProvider implements CalendarProvider
{
    public const MULTIGET_BATCH = 50;

    private const ETAG = '{DAV:}getetag';
    private const CONTENT_TYPE = '{DAV:}getcontenttype';
    private const RESOURCE_TYPE = '{DAV:}resourcetype';
    private const SYNC_TOKEN = '{DAV:}sync-token';
    private const REPORTS = '{DAV:}supported-report-set';
    private const DATA = '{' . CalDavClient::NS_CALDAV . '}calendar-data';
    private const CTAG = '{' . CalDavClient::NS_CALSERVER . '}getctag';
    private const TZID_PROPERTIES = ['DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE'];

    private HttpClientFactory $http;

    private CalDavDiscovery $discovery;

    public function __construct(HttpClientFactory $http, CalDavDiscovery $discovery)
    {
        $this->http = $http;
        $this->discovery = $discovery;
    }

    public function canWrite(): bool
    {
        return true;
    }

    public function writableRecurrence(VEvent $master): bool
    {
        return true;
    }

    public function listCalendars(CalendarConnection $connection, ?string $owner = null): array
    {
        return $this->discovery->discover($this->client($connection, ['connection_id' => $connection->id]), (string) $connection->server_url);
    }

    public function pull(Calendar $calendar, array $state, SyncBudget $budget): ChangeSet
    {
        return $this->asAccount($calendar, function () use ($calendar, $state, $budget) {
            $client = $this->clientFor($calendar);
            $collection = self::collection($calendar);
            if (isset($state['listing']) && is_array($state['listing'])) {
                return $this->listingRound($client, $calendar, $collection, $state, $budget);
            }
            if (!empty($state['sync_token']) && ($state['supports_sync'] ?? true) !== false) {
                return $this->changesSince($client, $calendar, $collection, $state, $budget);
            }

            return $this->startListing($client, $calendar, $collection, $state, $budget);
        });
    }

    /**
     * Puts a new object at <collection>/<sha1(uid)>.ics. A server that holds the UID in another calendar of the account
     * already (Cyrus, as at Fastmail, allows a UID once per account: the CalDAV no-uid-conflict precondition) gets the
     * event under a UID of its own, which the returned RemoteRef carries; that is what a move within one account needs.
     */
    public function create(Calendar $calendar, VCalendar $vcal): RemoteRef
    {
        return $this->asAccount($calendar, function () use ($calendar, $vcal) {
            $client = $this->clientFor($calendar);
            $uid = self::uid($vcal);
            $assigned = null;
            try {
                [$url, $etag] = $this->putNew($client, $calendar, $vcal, $uid);
            } catch (CalDavRequestFailed $e) {
                if (!in_array('no-uid-conflict', $e->preconditions(), true)) {
                    throw $e;
                }
                $assigned = EventService::newUid();
                [$url, $etag] = $this->putNew($client, $calendar, self::withUid($vcal, $uid, $assigned), $assigned);
            }

            return new RemoteRef($url, $url, $etag ?? $this->currentEtag($client, $url), $assigned);
        });
    }

    /** Writes the whole VCALENDAR: CalDAV stores a series with its overrides as one object, so the occurrence key is not needed. */
    public function update(Calendar $calendar, CalendarEvent $event, VCalendar $vcal, ?string $occurrenceKey = null): RemoteRef
    {
        $url = (string) ($event->remote_href ?: $event->remote_id);
        if ($url === '') {
            return $this->create($calendar, $vcal); // never stored at the server, so it is created there now
        }

        return $this->asAccount($calendar, function () use ($calendar, $vcal, $event, $url) {
            $client = $this->clientFor($calendar);
            $etag = $client->put($url, self::body($vcal), $event->etag, false);

            return new RemoteRef($url, $url, $etag ?? $this->currentEtag($client, $url));
        });
    }

    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        $url = (string) ($event->remote_href ?: $event->remote_id);
        if ($url !== '') {
            $this->asAccount($calendar, function () use ($calendar, $event, $url) {
                $this->clientFor($calendar)->delete($url, $event->etag);
            });
        }
    }

    /** Reads the collection's sync token and ctag, then lists it, unless an unchanged ctag says nothing changed. */
    private function startListing(CalDavClient $client, Calendar $calendar, string $collection, array $state, SyncBudget $budget): ChangeSet
    {
        $info = [];
        foreach ($client->propfind($collection, 0, [self::CTAG, self::SYNC_TOKEN, self::REPORTS]) as $response) {
            if ($response['status'] === 200) {
                $info = $response['props'];
                break;
            }
        }
        $token = CalDavClient::text($info[self::SYNC_TOKEN] ?? null);
        // A server that doesn't list its reports but hands out a sync token is taken to support sync-collection.
        $supportsSync = ($state['supports_sync'] ?? null) !== false && $token !== null
            && (!array_key_exists(self::REPORTS, $info) || in_array('{DAV:}sync-collection', CalDavClient::names($info[self::REPORTS]), true));
        $ctag = CalDavClient::text($info[self::CTAG] ?? null);
        if (!$supportsSync && $ctag !== null && $ctag === ($state['ctag'] ?? null)) {
            return new ChangeSet([], [], $state, true, false);
        }
        $state['listing'] = ['after' => '', 'sync_token' => $supportsSync ? $token : null, 'ctag' => $ctag, 'supports_sync' => $supportsSync, 'unreadable' => []];

        return $this->listingRound($client, $calendar, $collection, $state, $budget);
    }

    /**
     * One round of a full listing: from where the last round stopped, until the members end or the budget does. An
     * object that can't be read counts as unchanged, so its stored copy is not swept.
     */
    private function listingRound(CalDavClient $client, Calendar $calendar, string $collection, array $state, SyncBudget $budget): ChangeSet
    {
        $listing = $state['listing'];
        $after = (string) ($listing['after'] ?? '');
        $unreadable = (array) ($listing['unreadable'] ?? []);
        $stored = CalendarEvent::where('calendar_id', $calendar->id)->whereNotNull('remote_id_hash')->pluck('etag', 'remote_id_hash')->all();
        $members = $this->members($client, $collection);
        $upserts = [];
        $unchanged = [];
        $batch = [];
        foreach ($members as $href => $etag) {
            if ($after !== '' && strcmp($href, $after) <= 0) {
                continue;
            }
            if ($etag !== null && ($stored[sha1($href)] ?? null) === $etag) {
                $unchanged[] = $href;
            } else {
                $batch[] = $href;
            }
            $after = $href;
            if (count($batch) >= self::MULTIGET_BATCH) {
                [$events, $failed] = $this->fetch($client, $collection, $batch);
                array_push($upserts, ...$events);
                array_push($unchanged, ...$failed);
                array_push($unreadable, ...$failed);
                $batch = [];
                if ($budget->exhausted()) {
                    $state['listing']['after'] = $after;
                    $state['listing']['unreadable'] = $unreadable;

                    return new ChangeSet($upserts, [], $state, false, true, $unchanged);
                }
            }
        }
        [$events, $failed] = $this->fetch($client, $collection, $batch);
        array_push($upserts, ...$events);
        array_push($unchanged, ...$failed);
        array_push($unreadable, ...$failed);
        unset($state['listing']);
        // A listing that found nothing keeps no token or ctag: the next sync lists again, and the engine only empties
        // the calendar after two empty listings in a row.
        // Without sync-collection an object that could not be read is only asked for again by another listing, so
        // no ctag is kept then either.
        $state['sync_token'] = $members ? ($listing['sync_token'] ?? null) : null;
        $state['ctag'] = $members && !$unreadable ? ($listing['ctag'] ?? null) : null;
        $state['supports_sync'] = (bool) ($listing['supports_sync'] ?? false);
        $state['pending'] = [];
        $state['pending_deletes'] = [];
        $state['retry'] = $state['supports_sync'] ? array_values(array_unique($unreadable)) : [];
        $state['more'] = false;

        return new ChangeSet($upserts, [], $state, true, true, $unchanged);
    }

    /**
     * What changed since the saved sync token, downloaded within the budget: what doesn't fit waits in "pending" and is
     * downloaded before the server is asked again. A forgotten token, or a server without the report, starts a listing.
     */
    private function changesSince(CalDavClient $client, Calendar $calendar, string $collection, array $state, SyncBudget $budget): ChangeSet
    {
        $pending = array_values((array) ($state['pending'] ?? []));
        $deletes = (array) ($state['pending_deletes'] ?? []);
        if (!$pending) {
            $report = $this->reportChanges($client, $calendar, $collection, $state, $budget);
            if ($report instanceof ChangeSet) {
                return $report;
            }
            [$changed, $reported, $truncated, $token] = $report;
            $deletes = array_values(array_unique(array_merge($deletes, $reported)));
            // 507 on the collection: the server sent part of the changes, and the new token continues from there. One
            // that answers 507 without a new token would be asked the same forever, so then this round is the last.
            $state['more'] = $truncated && $token !== null && $token !== $state['sync_token'];
            $state['sync_token'] = $token ?? $state['sync_token'];
            $pending = array_values(array_unique(array_merge($changed, (array) ($state['retry'] ?? []))));
            $state['retry'] = [];
        }
        $upserts = [];
        $retry = (array) ($state['retry'] ?? []);
        while ($pending) {
            [$events, $failed] = $this->fetch($client, $collection, array_splice($pending, 0, self::MULTIGET_BATCH));
            array_push($upserts, ...$events);
            array_push($retry, ...$failed);
            if ($budget->exhausted()) {
                break;
            }
        }
        $state['pending'] = $pending;
        $state['retry'] = array_values(array_unique($retry));
        // A renamed object comes as a delete of its old href and a change of its new one: the deletes wait for the round
        // that brings in the last change, so the engine finds the event by its UID before the old href is removed.
        $state['pending_deletes'] = $pending ? $deletes : [];

        return new ChangeSet($upserts, $pending ? [] : $deletes, $state, !$pending && !($state['more'] ?? false), false);
    }

    /**
     * Asks the server what changed since the token. Returns [changed hrefs, deleted hrefs, truncated, new token], or the
     * ChangeSet of the listing that replaces it when the token is no longer known or the report is not supported.
     *
     * @return array{0: string[], 1: string[], 2: bool, 3: ?string}|ChangeSet
     */
    private function reportChanges(CalDavClient $client, Calendar $calendar, string $collection, array $state, SyncBudget $budget)
    {
        try {
            $result = $client->report($collection, CalDavClient::syncCollectionBody((string) $state['sync_token'], [self::ETAG, self::CONTENT_TYPE, self::RESOURCE_TYPE]), 0);
        } catch (CalDavRequestFailed $e) {
            // The status of an invalid token differs per server (403 for sabre/dav and Radicale, 409 for older Radicale).
            $invalidToken = in_array('valid-sync-token', $e->preconditions(), true);
            if (!$invalidToken && !in_array($e->httpStatus(), [403, 405, 501], true)) {
                throw $e;
            }
            Log::info($invalidToken ? 'A CalDAV server no longer knows the sync token; the calendar is listed again' : 'A CalDAV server does not support sync-collection; the calendar is listed when its ctag changes', ['calendar_id' => $calendar->id, 'status' => $e->httpStatus()]);
            unset($state['sync_token']);
            if (!$invalidToken) {
                $state['supports_sync'] = false;
            }

            return $this->startListing($client, $calendar, $collection, $state, $budget);
        }

        $changed = [];
        $deletes = [];
        $truncated = false;
        foreach ($result['responses'] as $href => $response) {
            if (CalDavClient::sameResource($href, $collection)) {
                $truncated = $truncated || $response['status'] === 507;
            } elseif (in_array($response['status'], [404, 410], true)) {
                $deletes[] = $href;
            } elseif ($response['status'] === 200 && self::mayHoldEvents($href, $collection, $response['props'])) {
                $changed[] = $href;
            }
        }

        return [$changed, $deletes, $truncated, $result['sync_token']];
    }

    /**
     * @return array<string, ?string> href => ETag of every object in the collection that may hold events, in href order;
     *                                null when the server sent none, so the object is always downloaded
     */
    private function members(CalDavClient $client, string $collection): array
    {
        $members = [];
        foreach ($client->propfind($collection, 1, [self::ETAG, self::CONTENT_TYPE, self::RESOURCE_TYPE]) as $href => $response) {
            if ($response['status'] === 200 && self::mayHoldEvents($href, $collection, $response['props'])) {
                $members[$href] = CalDavClient::text($response['props'][self::ETAG] ?? null);
            }
        }
        ksort($members, SORT_STRING);

        return $members;
    }

    /** Not the collection, no sub-collection, and not a to-do or journal (sabre/dav and Radicale name the component). */
    private static function mayHoldEvents(string $href, string $collection, array $props): bool
    {
        if (CalDavClient::sameResource($href, $collection) || in_array('{DAV:}collection', CalDavClient::names($props[self::RESOURCE_TYPE] ?? null), true)) {
            return false;
        }

        return preg_match('/component\s*=\s*"?(vtodo|vjournal)\b/i', (string) CalDavClient::text($props[self::CONTENT_TYPE] ?? null)) !== 1;
    }

    /**
     * Downloads these objects with calendar-multiget. One that is gone by now (404, 410) is skipped: a listing then
     * sweeps it, a sync token reports it. One the answer leaves out, sends without its data or refuses on its own is
     * read with GET; when that is refused too, it is returned as unreadable, so its stored copy stays. A failure of the
     * whole request (the network, the report itself) is thrown, and nothing of the round is applied.
     *
     * @param string[] $hrefs
     *
     * @return array{0: RemoteEvent[], 1: string[]} the events and the hrefs that could not be read
     */
    private function fetch(CalDavClient $client, string $collection, array $hrefs): array
    {
        if (!$hrefs) {
            return [[], []];
        }
        try {
            $responses = $client->report($collection, CalDavClient::multigetBody($hrefs, [self::ETAG, self::DATA]), 1)['responses'];
        } catch (CalDavAnswerTooLarge $e) {
            // Big attachments: half the batch at a time, down to one object, which then can't be read.
            if (count($hrefs) === 1) {
                return [[], $hrefs];
            }
            $half = intdiv(count($hrefs), 2);
            [$first, $firstFailed] = $this->fetch($client, $collection, array_slice($hrefs, 0, $half));
            [$second, $secondFailed] = $this->fetch($client, $collection, array_slice($hrefs, $half));

            return [array_merge($first, $second), array_merge($firstFailed, $secondFailed)];
        }
        $events = [];
        $unreadable = [];
        foreach ($hrefs as $href) {
            $status = $responses[$href]['status'] ?? null;
            if ($status === 404 || $status === 410) {
                continue;
            }
            $props = $status === 200 ? $responses[$href]['props'] : [];
            $ics = CalDavClient::text($props[self::DATA] ?? null);
            $etag = CalDavClient::text($props[self::ETAG] ?? null);
            if ($ics === null) {
                try {
                    $object = $client->get($href);
                } catch (CalDavRequestFailed | CalDavAnswerTooLarge $e) {
                    $unreadable[] = $href; // the client logged why; the next sync asks again
                    continue;
                }
                if ($object === null) {
                    continue;
                }
                $ics = $object['ics'];
                $etag = $object['etag'] ?? $etag;
            }
            // A collection may hold to-dos too; they are no events.
            if (stripos($ics, 'BEGIN:VEVENT') !== false) {
                $events[] = new RemoteEvent($href, $ics, $href, $etag);
            }
        }

        return [$events, $unreadable];
    }

    /** @return array{0: string, 1: ?string} the new object's address and the ETag the server answered with */
    private function putNew(CalDavClient $client, Calendar $calendar, VCalendar $vcal, string $uid): array
    {
        $url = rtrim(self::collection($calendar), '/') . '/' . sha1($uid) . '.ics';

        return [$url, $client->put($url, self::body($vcal), null, true)];
    }

    /**
     * The ETag of an object just written, for a server that answered the PUT without one. The write has succeeded, so
     * a failure here only costs the ETag (the client logged it): the next write then goes without If-Match.
     */
    private function currentEtag(CalDavClient $client, string $url): ?string
    {
        try {
            $responses = $client->propfind($url, 0, [self::ETAG]);
        } catch (ProviderException $e) {
            return null;
        }
        foreach ($responses as $response) {
            if ($response['status'] === 200) {
                return CalDavClient::text($response['props'][self::ETAG] ?? null);
            }
        }

        return null;
    }

    /** A copy of the VCALENDAR with the event's components under another UID. */
    private static function withUid(VCalendar $vcal, string $from, string $to): VCalendar
    {
        $copy = clone $vcal;
        foreach ($copy->select('VEVENT') as $vevent) {
            if ((string) ($vevent->UID ?? '') === $from) {
                $vevent->UID = $to;
            }
        }

        return $copy;
    }

    /**
     * Runs requests of the calendar's account. A final 401 means the server refused the username or password (a digest
     * challenge the client answers itself never ends up here): the connection is marked needs_reauth and
     * ConnectionNeedsReauth is thrown. A 403 stays what it is, a refusal of this one request or a precondition.
     *
     * @return mixed what $requests returns
     */
    private function asAccount(Calendar $calendar, callable $requests)
    {
        try {
            return $requests();
        } catch (CalDavRequestFailed $e) {
            if ($e->httpStatus() !== 401 || $calendar->calendarConnection === null) {
                throw $e;
            }
            throw $this->loginRefused($calendar->calendarConnection, $e);
        }
    }

    /**
     * Marks the connection needs_reauth, as ConnectionService does for a refused OAuth grant, but only while the stored
     * login is still the one the server refused: an administrator may have saved a new password meanwhile, and then the
     * refusal is returned as it is and the next sync tries the new one.
     */
    private function loginRefused(CalendarConnection $connection, CalDavRequestFailed $refused): ProviderException
    {
        $message = __('The account must be connected again.');
        $refusedLogin = CalendarConnection::whereKey($connection->id)->where('credentials', $connection->getAttributes()['credentials'] ?? null);
        (clone $refusedLogin)->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => $message]);
        // Asked separately: MySQL counts only rows an update changed, so a row another request marked counts as none.
        if (!$refusedLogin->exists()) {
            return $refused;
        }
        // The sync engine reads this same instance afterwards, so it keeps the account's message.
        $connection->status = CalendarConnection::STATUS_NEEDS_REAUTH;
        $connection->last_error = $message;
        $connection->syncOriginal();

        return new ConnectionNeedsReauth(401, $refused);
    }

    /** The account's client. One that must log in again sends nothing, so the server never sees a refused login twice. */
    private function clientFor(Calendar $calendar): CalDavClient
    {
        $connection = $calendar->calendarConnection;
        if ($connection === null) {
            throw new ProviderUnavailable(__('This calendar has no CalDAV account.'));
        }
        if ($connection->status !== CalendarConnection::STATUS_OK) {
            throw new ConnectionNeedsReauth();
        }

        return $this->client($connection, ['calendar_id' => $calendar->id, 'connection_id' => $connection->id]);
    }

    private function client(CalendarConnection $connection, array $logContext): CalDavClient
    {
        $credentials = $connection->credentials();

        return new CalDavClient($this->http, (string) ($credentials['username'] ?? ''), (string) ($credentials['password'] ?? ''), $logContext);
    }

    private static function collection(Calendar $calendar): string
    {
        $url = trim((string) $calendar->remote_id);
        if ($url === '') {
            throw new ProviderUnavailable(__('This is not a valid http or https address.'));
        }

        return CalDavClient::absolute($url, $url);
    }

    private static function uid(VCalendar $vcal): string
    {
        $first = null;
        foreach ($vcal->select('VEVENT') as $vevent) {
            $uid = (string) ($vevent->UID ?? '');
            if ($uid !== '' && !isset($vevent->{'RECURRENCE-ID'})) {
                return $uid;
            }
            $first = $first ?? ($uid !== '' ? $uid : null);
        }
        if ($first === null) {
            throw new LogicException('The VCALENDAR holds no event with a UID.');
        }

        return $first;
    }

    /**
     * The VCALENDAR as the server gets it: a VTIMEZONE for every TZID it uses (RFC 4791 section 4.1 wants them) and
     * SCHEDULE-AGENT=CLIENT on attendees and the organizer, so the server never sends invitations of its own.
     */
    private static function body(VCalendar $vcal): string
    {
        $copy = clone $vcal;
        $defined = [];
        foreach ($copy->select('VTIMEZONE') as $zone) {
            $defined[(string) $zone->TZID] = true;
        }
        $firstYears = [];
        foreach ($copy->select('VEVENT') as $vevent) {
            foreach (self::TZID_PROPERTIES as $name) {
                foreach ($vevent->select($name) as $property) {
                    $tzid = (string) ($property['TZID'] ?? '');
                    if ($tzid === '' || strtoupper($tzid) === 'UTC' || isset($defined[$tzid])) {
                        continue;
                    }
                    $year = $name === 'DTSTART' ? (int) substr((string) $property, 0, 4) : 0;
                    $firstYears[$tzid] = min($firstYears[$tzid] ?? PHP_INT_MAX, $year > 0 ? $year : PHP_INT_MAX);
                }
            }
            foreach (['ATTENDEE', 'ORGANIZER'] as $name) {
                foreach ($vevent->select($name) as $property) {
                    $property['SCHEDULE-AGENT'] = 'CLIENT';
                }
            }
        }
        $lastYear = (int) gmdate('Y') + 2;
        foreach ($firstYears as $tzid => $year) {
            $zone = VTimezone::resolve($tzid, $copy);
            if ($zone === null) {
                continue; // sabre can't tell which zone it is; the server keeps reading the TZID as it is
            }
            $from = ($year === PHP_INT_MAX ? (int) gmdate('Y') : $year) - 1;
            VTimezone::addTo($copy, $tzid, $from, max($lastYear, $from), $zone);
        }

        return $copy->serialize();
    }
}
