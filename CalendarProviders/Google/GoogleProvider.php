<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Google;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;
use Modules\LJPcCalendarModule\CalendarProviders\CalendarProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteCalendar;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\ReadableBlock;
use Modules\LJPcCalendarModule\Services\VTimezone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

/**
 * Two-way Google Calendar (API v3). calendars.remote_id is the Google calendar id; an event's remote id is its Google
 * event id (the series' id for a series) and its etag the event's etag. There is no href.
 *
 * Pulling: events.list with singleEvents=false and showDeleted=true, one page (250 events) per round. Without a sync
 * token that is a full listing; its last page hands out the sync token the next rounds use (incremental sync guide).
 * Reading series again takes requests of their own, so a round stops before the next one once the budget is spent,
 * and the next round lists the same page and skips the events the stopped one finished (page_done).
 * A 410 means Google forgot the token: the calendar is listed in full again. A series' exceptions are items of their
 * own (recurringEventId, originalStartTime) that Google lists only when they change. So a stored series whose master
 * changed is read again whole: its exceptions come from its instances in the occurrence window. Exceptions that change
 * on their own are merged into the stored series.
 *
 * Writing: events.insert with FreeScout's UID as iCalUID; a change reads the event and writes it back whole
 * (events.update, as Google advises over patch) under If-Match; an occurrence is written to its instance after
 * checking that Google still has it as FreeScout stored it, and a deleted occurrence cancels its instance. A meeting
 * (an event with guests at Google) gets its description without FreeScout's readable block: Google shows the
 * organizer's description to every guest, and the fields and the ticket link stay in FreeScout.
 *
 * Sync state: sync_token, and while a listing is unfinished page_token (plus listed, the events a full listing found)
 * and page_done (event id => fingerprint of what a round that stopped inside that page finished).
 */
class GoogleProvider implements CalendarProvider
{
    public const PAGE_SIZE = 250;
    /** calendarList.list allows at most 250 per page. */
    public const CALENDAR_PAGE_SIZE = 250;
    /** events.instances allows at most 2500 per page; a series is read in at most this many pages. */
    public const INSTANCE_PAGE_SIZE = 2500;
    public const MAX_INSTANCE_PAGES = 4;
    /** Access roles that may change events (calendarList and events.list reference). */
    public const WRITE_ROLES = ['owner', 'writer', 'writerWithoutPrivateAccess'];

    /** Asked with every update: the event comes back whole, with its Meet link and attachments. */
    private const WRITE_QUERY = ['conferenceDataVersion' => 1, 'supportsAttachments' => 'true'];
    /** Requests someone waits for keep their rate-limit waits short. */
    private const INTERACTIVE = ['max_wait' => GoogleApi::INTERACTIVE_MAX_WAIT_SECONDS];

    private GoogleApi $api;

    private OccurrenceService $occurrences;

    /** The budget of the round pull() runs, and how many events read beyond its page; null outside pull(). */
    private ?SyncBudget $roundBudget = null;

    private int $roundReads = 0;

    /** Whether the event changes() handles now has started reading: it finishes, whatever the budget says. */
    private bool $reading = false;

    public function __construct(GoogleApi $api, OccurrenceService $occurrences)
    {
        $this->api = $api;
        $this->occurrences = $occurrences;
    }

    public function canWrite(): bool
    {
        return true;
    }

    /** Google keeps any RFC 5545 rule. */
    public function writableRecurrence(VEvent $master): bool
    {
        return true;
    }

    public function listCalendars(CalendarConnection $connection, ?string $owner = null): array
    {
        $calendars = [];
        $pageToken = null;
        $seen = [];
        do {
            $query = ['maxResults' => self::CALENDAR_PAGE_SIZE] + ($pageToken !== null ? ['pageToken' => $pageToken] : []);
            [, $page] = $this->api->request($connection, 'GET', 'users/me/calendarList', ['query' => $query] + self::INTERACTIVE);
            foreach ((array) ($page['items'] ?? []) as $item) {
                $id = is_array($item) ? self::text($item['id'] ?? null) : null;
                if ($id === null) {
                    continue;
                }
                $color = self::text($item['backgroundColor'] ?? null);
                $timezone = self::text($item['timeZone'] ?? null);
                $calendars[] = new RemoteCalendar(
                    $id,
                    self::text($item['summaryOverride'] ?? null) ?? self::text($item['summary'] ?? null) ?? $id,
                    $color !== null && preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : null,
                    $timezone !== null && VTimezone::isNamedZone($timezone) ? $timezone : null,
                    in_array($item['accessRole'] ?? null, self::WRITE_ROLES, true),
                    !empty($item['primary'])
                );
            }
            $pageToken = self::text($page['nextPageToken'] ?? null);
            // A token handed out twice would page forever.
            if ($pageToken !== null && isset($seen[$pageToken])) {
                break;
            }
            $seen[(string) $pageToken] = true;
        } while ($pageToken !== null);

        return $calendars;
    }

    public function pull(Calendar $calendar, array $state, SyncBudget $budget): ChangeSet
    {
        $this->roundBudget = $budget;
        $this->roundReads = 0;
        try {
            return $this->pullPage($calendar, $state, $budget);
        } finally {
            $this->roundBudget = null;
        }
    }

    private function pullPage(Calendar $calendar, array $state, SyncBudget $budget): ChangeSet
    {
        $connection = self::connectionOf($calendar);
        $syncToken = self::text($state['sync_token'] ?? null);
        $pageToken = self::text($state['page_token'] ?? null);
        $query = ['singleEvents' => 'false', 'showDeleted' => 'true', 'maxResults' => self::PAGE_SIZE]
            + ($syncToken !== null ? ['syncToken' => $syncToken] : [])
            + ($pageToken !== null ? ['pageToken' => $pageToken] : []);
        // A request without a token can't start over: a 410 or 400 there is an error.
        $accept = array_merge($syncToken !== null || $pageToken !== null ? [410] : [], $pageToken !== null ? [400] : []);
        [$status, $page] = $this->api->request($connection, 'GET', self::eventsPath($calendar), ['query' => $query], $accept, self::context($calendar));
        if ($status === 410) {
            Log::info('Google no longer accepts the sync token of a calendar; it is listed in full', ['calendar_id' => $calendar->id, 'provider' => 'google', 'status' => 410]);

            return $this->pullPage($calendar, [], $budget);
        }
        if ($status === 400) {
            // UNVERIFIED how Google answers a page token that expired (a long outage, a paused connection): the listing
            // starts over from its first page, with the same sync token.
            Log::info('Google refused the saved page token of a calendar; its listing starts over', ['calendar_id' => $calendar->id, 'provider' => 'google', 'status' => 400]);

            return $this->pullPage($calendar, $syncToken !== null ? ['sync_token' => $syncToken] : [], $budget);
        }

        $full = $syncToken === null;
        $done = is_array($state['page_done'] ?? null) ? $state['page_done'] : [];
        [$upserts, $deletes, $unchanged, $stopped] = $this->changes($calendar, $connection, (array) ($page['items'] ?? []), (string) ($page['accessRole'] ?? 'owner'), $full, $done);
        $listed = (int) ($state['listed'] ?? 0) + count($upserts) + count($unchanged);
        if ($stopped !== null) {
            // The budget ran out inside this page: the next round lists it again and skips what this one finished.
            $state = ['sync_token' => $syncToken, 'page_token' => $pageToken, 'listed' => $full ? $listed : null, 'page_done' => $stopped];

            return new ChangeSet($upserts, $deletes, self::withoutNulls($state), false, $full, $unchanged);
        }
        $next = self::text($page['nextPageToken'] ?? null);
        if ($next !== null) {
            $state = ['sync_token' => $syncToken, 'page_token' => $next, 'listed' => $full ? $listed : null];

            return new ChangeSet($upserts, $deletes, self::withoutNulls($state), false, $full, $unchanged);
        }
        // A full listing that found nothing keeps no token, so the next sync lists again: the engine only empties a
        // calendar after two empty listings in a row.
        $token = $full && $listed === 0 ? null : self::text($page['nextSyncToken'] ?? null);

        return new ChangeSet($upserts, $deletes, self::withoutNulls(['sync_token' => $token]), true, $full, $unchanged);
    }

    /**
     * Inserts the master under FreeScout's UID (events.insert with iCalUID). A series that brings overrides (an event
     * moved here from another calendar) gets each of them written to its instance; an override Google has no instance
     * for is left out. When anything fails after the insert, the series is deleted again, so nothing half-made stays.
     */
    public function create(Calendar $calendar, VCalendar $vcal): RemoteRef
    {
        $connection = self::connectionOf($calendar);
        $zone = $calendar->timezoneName();
        $master = self::masterOf($vcal);
        $body = self::withoutNulls(GoogleEventMapper::fromVEvent($master, true, $zone));
        try {
            [, $created] = $this->api->request($connection, 'POST', self::eventsPath($calendar), ['json' => $body] + self::INTERACTIVE, [], self::context($calendar));
        } catch (ProviderUnavailable $e) {
            if ($e->httpStatus() !== 409) {
                throw $e;
            }
            // Google keeps a deleted event and its iCalUID for 30 days (an event moved out of Google and back), and
            // refuses that UID. Google then files the event under a UID of its own, which FreeScout takes over.
            unset($body['iCalUID']);
            [, $created] = $this->api->request($connection, 'POST', self::eventsPath($calendar), ['json' => $body] + self::INTERACTIVE, [], self::context($calendar));
        }
        $id = self::text($created['id'] ?? null);
        if ($id === null) {
            throw new ProviderUnavailable();
        }
        $tz = new DateTimeZone($zone);
        try {
            foreach ($vcal->select('VEVENT') as $vevent) {
                if ($vevent === $master || (string) $vevent->UID !== (string) $master->UID || !isset($vevent->{'RECURRENCE-ID'})) {
                    continue;
                }
                $key = EventIcs::recurrenceKey($vevent->{'RECURRENCE-ID'}, $tz);
                if (!$this->writeOverride($connection, $calendar, $id, $vevent, $key, null)) {
                    Log::info('An edited occurrence that is no occurrence of the series in Google was left out', ['calendar_id' => $calendar->id, 'provider' => 'google', 'key' => $key]);
                }
            }
        } catch (\Throwable $e) {
            $this->discard($connection, $calendar, $id);
            throw $e;
        }
        $uid = self::text($created['iCalUID'] ?? null);

        return new RemoteRef($id, null, self::text($created['etag'] ?? null), $uid !== null && $uid !== (string) $master->UID ? $uid : null);
    }

    /**
     * The series: read it, put FreeScout's fields on it and write it back whole under the stored etag. An occurrence:
     * its override is written to its instance, an EXDATE cancels it. Instances are events of their own at Google, so
     * after an occurrence write the series keeps the etag the last sync saw.
     */
    public function update(Calendar $calendar, CalendarEvent $event, VCalendar $vcal, ?string $occurrenceKey = null): RemoteRef
    {
        $id = (string) $event->remote_id;
        if ($id === '') {
            return $this->create($calendar, $vcal); // never stored at Google, so it is created there now
        }
        $connection = self::connectionOf($calendar);
        if ($occurrenceKey === null) {
            return $this->updateSeries($connection, $calendar, $event, $vcal);
        }
        $tz = new DateTimeZone($event->timezone ?: $calendar->timezoneName());
        $override = EventIcs::findOverride($vcal, $event->uid, $occurrenceKey, $tz);
        if ($override !== null) {
            // The event as stored before this change: what Google must still have, or someone changed it meanwhile.
            if (!$this->writeOverride($connection, $calendar, $id, $override, $occurrenceKey, ['stored' => Reader::read($event->ics), 'uid' => $event->uid, 'zone' => $tz->getName()])) {
                throw new ProviderConflict(); // Google no longer has this occurrence
            }
        } elseif (in_array($occurrenceKey, EventIcs::exdateKeys(EventIcs::master($vcal, $event->uid), $tz), true)) {
            $this->cancelInstance($connection, $calendar, $id, $occurrenceKey);
        } else {
            throw new LogicException('Occurrence ' . $occurrenceKey . ' of event ' . $event->id . ' is neither an override nor an EXDATE');
        }

        return new RemoteRef($id, null, $event->etag);
    }

    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        $id = (string) $event->remote_id;
        if ($id === '') {
            return;
        }
        $headers = $event->etag ? ['If-Match' => $event->etag] : [];
        $this->api->request(self::connectionOf($calendar), 'DELETE', self::eventPath($calendar, $id), ['headers' => $headers] + self::INTERACTIVE, [404, 410], self::context($calendar));
    }

    /**
     * One page of events as upserts, deletes and the ids kept as they are stored (unchanged in a full listing, or
     * unreadable). An event (a master with its exceptions, or exceptions whose master is not on the page) that $done
     * lists with the same fingerprint was finished by a round that stopped in this page, so it counts as unchanged.
     * When the budget is spent before a read, the page ends there; the fourth element is then what is finished, for the
     * next round (null when the page was read to its end).
     *
     * @return array{0: RemoteEvent[], 1: string[], 2: string[], 3: ?array}
     */
    private function changes(Calendar $calendar, CalendarConnection $connection, array $items, string $role, bool $full, array $done): array
    {
        $readOnly = !in_array($role, self::WRITE_ROLES, true);
        // A free/busy reader can't read a series' instances, and its page has no exceptions to merge.
        $rebuild = $role !== 'freeBusyReader';
        $masters = [];
        $exceptions = [];
        $deletes = [];
        foreach ($items as $item) {
            if (!is_array($item) || self::text($item['id'] ?? null) === null) {
                continue;
            }
            if ($role === 'freeBusyReader') {
                $item = self::busyBlock($item);
            }
            $parent = self::text($item['recurringEventId'] ?? null);
            if ($parent !== null) {
                $exceptions[$parent][] = $item;
            } elseif (($item['status'] ?? null) === 'cancelled') {
                $deletes[] = $item['id']; // a deleted event or series: Google only guarantees its id
            } else {
                $masters[$item['id']] = $item;
            }
        }
        $series = array_keys(array_filter($masters, function (array $master) {
            return !empty($master['recurrence']);
        }));
        $stored = CalendarEvent::byRemoteIds($calendar->id, array_merge($series, array_keys($exceptions)));

        $upserts = [];
        $unchanged = [];
        $finished = $done;
        foreach ($masters as $id => $master) {
            $own = $exceptions[$id] ?? [];
            unset($exceptions[$id]);
            $fingerprint = self::fingerprint(array_merge([$master], $own));
            if (($done[$id] ?? null) === $fingerprint) {
                $unchanged[] = (string) $id;
                continue;
            }
            $event = $stored[$id] ?? null;
            // A full listing (the first sync, a 410, a relist) of a series that didn't change since the last sync: its
            // exceptions come in this listing like its master, so they are merged, and nothing is read again (one
            // request per series would outlast the sync's lease on a large calendar).
            // A changed access role (Google answers it with a 410) changes no etag, so the stored read-only flag must
            // also be what this page gives.
            $unchangedSeries = $full && $event !== null && !empty($master['recurrence']) && $event->etag !== null && $event->etag === self::text($master['etag'] ?? null)
                && (bool) $event->read_only === ($readOnly || self::lockedAtGoogle($master) || in_array($master['visibility'] ?? null, ['private', 'confidential'], true));
            if ($unchangedSeries && !$own) {
                $unchanged[] = (string) $id;
                continue;
            }
            $this->reading = false;
            try {
                $this->collect($calendar, (string) $id, $upserts, $unchanged, function () use ($calendar, $connection, $master, $own, $event, $readOnly, $rebuild, $unchangedSeries) {
                    if ($unchangedSeries && $this->mergeable($calendar, $event, $own)) {
                        return $this->overrides($calendar, $event, $own, $readOnly);
                    }

                    return $this->series($calendar, $connection, $master, $own, $rebuild && $event !== null, $readOnly);
                });
            } catch (RoundBudgetSpent $e) {
                return [$upserts, $deletes, $unchanged, $finished];
            }
            $finished[$id] = $fingerprint;
        }
        foreach ($exceptions as $parent => $own) {
            if (in_array((string) $parent, $deletes, true)) {
                continue;
            }
            $fingerprint = self::fingerprint($own);
            if (($done[$parent] ?? null) === $fingerprint) {
                $unchanged[] = (string) $parent;
                continue;
            }
            $this->reading = false;
            try {
                $this->collect($calendar, (string) $parent, $upserts, $unchanged, function () use ($calendar, $connection, $parent, $own, $stored, $readOnly, $rebuild, $full) {
                    $event = $stored[$parent] ?? null;
                    if ($event !== null && $this->mergeable($calendar, $event, $own)) {
                        return $this->overrides($calendar, $event, $own, $readOnly);
                    }
                    // In a full listing the series' other exceptions still come; an incremental round lists them no more.
                    return $this->fetchSeries($calendar, $connection, (string) $parent, $own, $rebuild && ($event !== null || !$full), $readOnly);
                });
            } catch (RoundBudgetSpent $e) {
                return [$upserts, $deletes, $unchanged, $finished];
            }
            $finished[$parent] = $fingerprint;
        }

        return [$upserts, $deletes, $unchanged, null];
    }

    /** What a page says of an event, so a round that lists the page again sees whether it changed meanwhile. */
    private static function fingerprint(array $items): string
    {
        return sha1(implode("\n", array_map(function (array $item) {
            return (string) ($item['etag'] ?? '') . ' ' . (string) ($item['updated'] ?? '') . ' ' . (string) ($item['status'] ?? '');
        }, $items)));
    }

    /**
     * Before a read beyond the page (a series' instances, a series its exceptions need): once the budget is spent the
     * round stops before the next event that reads, but the first such event of a round always reads, so every round
     * gets on, and an event that has started reading finishes.
     */
    private function beforeRead(): void
    {
        if ($this->reading) {
            return;
        }
        if ($this->roundBudget !== null && $this->roundReads > 0 && $this->roundBudget->exhausted()) {
            throw new RoundBudgetSpent();
        }
        $this->roundReads++;
        $this->reading = true;
    }

    /** Maps one event; one that can't be read is logged and counts as unchanged, so its stored copy stays. */
    private function collect(Calendar $calendar, string $id, array &$upserts, array &$unchanged, callable $map): void
    {
        try {
            $remote = $map();
        } catch (InvalidArgumentException | UnexpectedValueException | ParseException | InvalidDataException $e) {
            Log::warning('A pulled calendar event was skipped', ['calendar_id' => $calendar->id, 'provider' => 'google', 'remote_id_hash' => sha1($id), 'reason' => $e->getMessage()]);
            $unchanged[] = $id;

            return;
        }
        if ($remote !== null) {
            $upserts[] = $remote;
        }
    }

    /**
     * A single event, or a series with its exceptions, as a full upsert. A series FreeScout stored already ($reread) is
     * read again whole: Google lists only the exceptions that changed, and the stored ones may no longer fit the
     * changed series (another time, all-day, a new rule).
     */
    private function series(Calendar $calendar, CalendarConnection $connection, array $master, array $own, bool $reread, bool $readOnly): RemoteEvent
    {
        $zone = $calendar->timezoneName();
        if ($reread && !empty($master['recurrence'])) {
            $own = $this->currentExceptions($connection, $calendar, $master, $own);
        }

        return new RemoteEvent($master['id'], GoogleEventMapper::toVCalendar($master, $own, $zone), null, self::text($master['etag'] ?? null), $readOnly || self::lockedAtGoogle($master));
    }

    /**
     * The exceptions Google keeps for a series now: its instances in the occurrence window (events.instances) that are
     * cancelled or differ from the series, plus the listed ones outside the window, in occurrence order. Exceptions
     * outside the window that were not listed are not kept. Google refusing the instances of this one series (gone
     * meanwhile, or refused) throws UnexpectedValueException: the series keeps its stored copy, the calendar syncs on.
     */
    private function currentExceptions(CalendarConnection $connection, Calendar $calendar, array $master, array $listed): array
    {
        $this->beforeRead();
        $zone = $calendar->timezoneName();
        $byKey = [];
        foreach ($listed as $exception) {
            $byKey[GoogleEventMapper::exceptionKey($exception, $zone)] = $exception;
        }
        [$from, $to] = $this->occurrences->window();
        $pageToken = null;
        $pages = 0;
        do {
            $query = ['timeMin' => $from->format(DATE_RFC3339), 'timeMax' => $to->format(DATE_RFC3339), 'showDeleted' => 'true', 'maxResults' => self::INSTANCE_PAGE_SIZE]
                + ($pageToken !== null ? ['pageToken' => $pageToken] : []);
            [$status, $page] = $this->api->request($connection, 'GET', self::eventPath($calendar, $master['id']) . '/instances', ['query' => $query], [400, 403, 404, 410], self::context($calendar));
            if ($status !== 200) {
                throw new UnexpectedValueException('Google answered HTTP ' . $status . ' for the occurrences of this series');
            }
            foreach ((array) ($page['items'] ?? []) as $instance) {
                if (!is_array($instance) || !isset($instance['originalStartTime'])) {
                    continue;
                }
                $key = GoogleEventMapper::exceptionKey($instance, $zone);
                if (GoogleEventMapper::isException($instance, $master, $zone)) {
                    $byKey[$key] = $instance;
                } else {
                    unset($byKey[$key]);
                }
            }
            $pageToken = self::text($page['nextPageToken'] ?? null);
        } while ($pageToken !== null && ++$pages < self::MAX_INSTANCE_PAGES);
        if ($pageToken !== null) {
            Log::warning('A Google series has more occurrences than FreeScout reads; later exceptions are left out', ['calendar_id' => $calendar->id, 'provider' => 'google', 'remote_id_hash' => sha1((string) $master['id'])]);
        }
        ksort($byKey, SORT_STRING);

        return array_values($byKey);
    }

    /** Exceptions of a stored series whose master did not change: merged into it, cancelled ones as EXDATEs. */
    private function overrides(Calendar $calendar, CalendarEvent $stored, array $own, bool $readOnly): RemoteEvent
    {
        $zone = $stored->timezone ?: $calendar->timezoneName();
        $cancelled = [];
        $locks = [];
        foreach ($own as $exception) {
            if (($exception['status'] ?? null) === 'cancelled') {
                $cancelled[] = GoogleEventMapper::exceptionKey($exception, $zone);
            } else {
                $locks[] = self::lockedAtGoogle($exception);
            }
        }
        // Cancelled instances carry no organizer: then the stored series says whether Google lets it be changed.
        $locked = $locks ? in_array(true, $locks, true) : (bool) $stored->read_only;

        return new RemoteEvent((string) $stored->remote_id, GoogleEventMapper::overridesToVCalendar($own, $stored->uid, $zone), null, null, $readOnly || $locked, true, $cancelled);
    }

    /**
     * Whether these exceptions can be merged into the stored series: they have its form (dates for an all-day series,
     * times for a timed one), and none brings back an occurrence the stored series has as an EXDATE (the merge can't
     * remove one). Otherwise the series is read again whole.
     */
    private function mergeable(Calendar $calendar, CalendarEvent $stored, array $own): bool
    {
        $tz = new DateTimeZone($stored->timezone ?: $calendar->timezoneName());
        $master = EventIcs::master(Reader::read($stored->ics), $stored->uid);
        $exdates = EventIcs::exdateKeys($master, $tz);
        foreach ($own as $exception) {
            $key = GoogleEventMapper::exceptionKey($exception, $tz->getName());
            if ((strlen($key) === 8) === $master->DTSTART->hasTime()) {
                return false;
            }
            if (($exception['status'] ?? null) !== 'cancelled' && in_array($key, $exdates, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reads a series for exceptions of it that can't be merged: its master is neither on this page nor stored (a
     * listing can reach exceptions first), or the stored copy no longer fits them. A series that is gone takes its
     * exceptions with it.
     */
    private function fetchSeries(Calendar $calendar, CalendarConnection $connection, string $id, array $own, bool $reread, bool $readOnly): ?RemoteEvent
    {
        $this->beforeRead();
        [$status, $master] = $this->api->request($connection, 'GET', self::eventPath($calendar, $id), [], [404, 410], self::context($calendar));
        if ($status !== 200 || ($master['status'] ?? null) === 'cancelled' || self::text($master['id'] ?? null) === null) {
            Log::info('Changed occurrences of a Google series that no longer exists were skipped', ['calendar_id' => $calendar->id, 'provider' => 'google', 'remote_id_hash' => sha1($id)]);

            return null;
        }

        return $this->series($calendar, $connection, $master, $own, $reread, $readOnly);
    }

    /**
     * Free/busy access lists events with their times only (calendar sharing guide), and occurrences of a series as
     * events of their own (recurring events guide: it "behaves as if singleEvents is true"; UNVERIFIED in 2026). They
     * become busy events; an occurrence gets its own id as UID, so occurrences of one series don't overwrite each other.
     * A series that does come with its rule keeps it.
     */
    private static function busyBlock(array $item): array
    {
        if (empty($item['recurrence'])) {
            unset($item['recurringEventId'], $item['originalStartTime']);
            $item['iCalUID'] = $item['id'];
        }
        $item['visibility'] = 'private';

        return $item;
    }

    /**
     * Events Google doesn't let this account change: an invitation from someone else (only the organizer's calendar
     * may change it, unless guests may modify it), and the special event types (working location, out of office, focus
     * time, birthday, from Gmail), most of whose fields can't be changed.
     */
    private static function lockedAtGoogle(array $item): bool
    {
        $organizer = $item['organizer'] ?? null;
        $invited = is_array($organizer) && empty($organizer['self']) && empty($item['guestsCanModify']);

        return $invited || ($item['eventType'] ?? 'default') !== 'default';
    }

    private function updateSeries(CalendarConnection $connection, Calendar $calendar, CalendarEvent $event, VCalendar $vcal): RemoteRef
    {
        $path = self::eventPath($calendar, (string) $event->remote_id);
        [$status, $current] = $this->api->request($connection, 'GET', $path, self::INTERACTIVE, [404, 410], self::context($calendar));
        if ($status !== 200 || ($current['status'] ?? null) === 'cancelled') {
            throw new ProviderConflict($status === 200 ? null : $status); // deleted in Google meanwhile
        }
        $etag = $event->etag ?: self::text($current['etag'] ?? null);
        if ($etag !== ($current['etag'] ?? null)) {
            throw new ProviderConflict(); // changed in Google since the last sync: the PUT would answer 412
        }
        $changes = self::keepRichText(self::forGuests(GoogleEventMapper::fromVEvent(EventIcs::master($vcal, $event->uid), true, $calendar->timezoneName()), $current), $current);
        unset($changes['iCalUID']);
        [, $saved] = $this->api->request($connection, 'PUT', $path, [
            'query'   => self::WRITE_QUERY,
            'json'    => self::withoutNulls(array_merge($current, $changes)),
            'headers' => $etag !== null ? ['If-Match' => $etag] : [],
        ] + self::INTERACTIVE, [], self::context($calendar));

        return new RemoteRef((string) $event->remote_id, null, self::text($saved['etag'] ?? null));
    }

    /**
     * Writes an override onto its instance: read it, put the override's fields on it, write it back under its etag.
     * With $before (the stored event: ['stored' => VCalendar, 'uid' => string, 'zone' => string]) the instance must
     * still be the occurrence FreeScout stored, or it is a conflict. Returns false when Google has no such instance.
     */
    private function writeOverride(CalendarConnection $connection, Calendar $calendar, string $masterId, VEvent $override, string $key, ?array $before): bool
    {
        [$status, $instance] = $this->api->request($connection, 'GET', self::eventPath($calendar, GoogleEventMapper::instanceId($masterId, $key)), self::INTERACTIVE, [404, 410], self::context($calendar));
        if ($status === 404) {
            $instance = $this->findInstance($connection, $calendar, $masterId, $key);
        } elseif ($status !== 200) {
            $instance = null;
        }
        if ($instance === null || ($instance['status'] ?? null) === 'cancelled' || self::text($instance['id'] ?? null) === null) {
            return false;
        }
        if ($before !== null && !GoogleEventMapper::sameOccurrence($instance, $before['stored'], $before['uid'], $key, $before['zone'])) {
            throw new ProviderConflict();
        }
        $etag = self::text($instance['etag'] ?? null);
        $this->api->request($connection, 'PUT', self::eventPath($calendar, $instance['id']), [
            'query'   => self::WRITE_QUERY,
            'json'    => self::withoutNulls(array_merge($instance, self::keepRichText(self::forGuests(GoogleEventMapper::fromVEvent($override, false, $calendar->timezoneName()), $instance), $instance))),
            'headers' => $etag !== null ? ['If-Match' => $etag] : [],
        ] + self::INTERACTIVE, [], self::context($calendar));

        return true;
    }

    /** Cancels one occurrence by deleting its instance; one that is cancelled or gone already needs nothing. */
    private function cancelInstance(CalendarConnection $connection, Calendar $calendar, string $masterId, string $key): void
    {
        [$status] = $this->api->request($connection, 'DELETE', self::eventPath($calendar, GoogleEventMapper::instanceId($masterId, $key)), self::INTERACTIVE, [404, 410], self::context($calendar));
        if ($status !== 404) {
            return;
        }
        $instance = $this->findInstance($connection, $calendar, $masterId, $key);
        if ($instance === null || ($instance['status'] ?? null) === 'cancelled' || self::text($instance['id'] ?? null) === null) {
            return;
        }
        $etag = self::text($instance['etag'] ?? null);
        $this->api->request($connection, 'DELETE', self::eventPath($calendar, $instance['id']), ['headers' => $etag !== null ? ['If-Match' => $etag] : []] + self::INTERACTIVE, [404, 410], self::context($calendar));
    }

    /**
     * The instance of one occurrence, asked for by its original start (events.instances, the documented way), for a
     * series whose instance ids don't have the usual form. Only an instance whose originalStartTime is that occurrence
     * counts.
     */
    private function findInstance(CalendarConnection $connection, Calendar $calendar, string $masterId, string $key): ?array
    {
        $utc = new DateTimeZone('UTC');
        $originalStart = strlen($key) === 8
            ? DateTimeImmutable::createFromFormat('!Ymd', $key, $utc)->format('Y-m-d')
            : DateTimeImmutable::createFromFormat('Ymd\THis\Z', $key, $utc)->format('Y-m-d\TH:i:s\Z');
        [, $page] = $this->api->request($connection, 'GET', self::eventPath($calendar, $masterId) . '/instances', [
            'query' => ['originalStart' => $originalStart, 'showDeleted' => 'true'],
        ] + self::INTERACTIVE, [], self::context($calendar));
        foreach ((array) ($page['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['originalStartTime']) && GoogleEventMapper::exceptionKey($item, $calendar->timezoneName()) === $key) {
                return $item;
            }
        }

        return null;
    }

    /** Deletes a series inserted a moment ago; when that fails too, the failure is logged and the first error stands. */
    private function discard(CalendarConnection $connection, Calendar $calendar, string $id): void
    {
        try {
            $this->api->request($connection, 'DELETE', self::eventPath($calendar, $id), self::INTERACTIVE, [404, 410], self::context($calendar));
        } catch (ProviderException $e) {
            Log::warning('A half-written Google series could not be removed again', ['calendar_id' => $calendar->id, 'provider' => 'google', 'remote_id_hash' => sha1($id), 'status' => $e->httpStatus(), 'error' => $e->userMessage()]);
        }
    }

    private static function masterOf(VCalendar $vcal): VEvent
    {
        foreach ($vcal->select('VEVENT') as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $vevent;
            }
        }

        throw new LogicException('The VCALENDAR holds no master event.');
    }

    /**
     * The description without FreeScout's readable block when the event at Google has guests (attendees other than this
     * account): they all see it (decision 2026-10-09).
     */
    private static function forGuests(array $changes, array $current): array
    {
        $guests = array_filter((array) ($current['attendees'] ?? []), function ($attendee) {
            return is_array($attendee) && empty($attendee['self']);
        });
        if ($guests && is_string($changes['description'] ?? null)) {
            $description = ReadableBlock::strip($changes['description']);
            $changes['description'] = $description === '' ? null : $description;
        }

        return $changes;
    }

    /**
     * Without the description while Google's reads as the same text: Google's editor stores HTML (bold, links), which
     * FreeScout reads as plain text, so only a change of the text writes it back as plain text (decision log, rich text).
     */
    private static function keepRichText(array $changes, array $current): array
    {
        if (is_string($changes['description'] ?? null) && is_string($current['description'] ?? null)
            && GoogleEventMapper::plainText($current['description']) === $changes['description']) {
            unset($changes['description']);
        }

        return $changes;
    }

    /** What Google gets: fields without a value are left out, which clears them in an update. */
    private static function withoutNulls(array $json): array
    {
        return array_filter($json, function ($value) {
            return $value !== null;
        });
    }

    private static function connectionOf(Calendar $calendar): CalendarConnection
    {
        $connection = $calendar->calendarConnection;
        if ($connection === null) {
            throw new ProviderUnavailable(__('This calendar has no Google account.'));
        }

        return $connection;
    }

    private static function eventsPath(Calendar $calendar): string
    {
        $id = trim((string) $calendar->remote_id);
        if ($id === '') {
            throw new ProviderUnavailable(__('This calendar is not linked to a Google calendar.'));
        }

        return 'calendars/' . rawurlencode($id) . '/events';
    }

    private static function eventPath(Calendar $calendar, string $id): string
    {
        return self::eventsPath($calendar) . '/' . rawurlencode($id);
    }

    private static function context(Calendar $calendar): array
    {
        return ['calendar_id' => $calendar->id];
    }

    private static function text($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
