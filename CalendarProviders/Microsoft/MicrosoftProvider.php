<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Microsoft;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Modules\LJPcCalendarModule\CalendarProviders\CalendarProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\ConfirmsDeletions;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\ExplainsRecurrenceRefusal;
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
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;
use UnexpectedValueException;

/**
 * Two-way Microsoft 365 calendars through Graph v1.0, the account's own and those of shared mailboxes it may open.
 * calendars.remote_id is the calendar's Graph path ("me/calendars/{id}" or "users/{address}/calendars/{id}"); an
 * event's remote id is its immutable Graph id (the series master's for a series) and its etag its changeKey.
 *
 * Pulling (research question 1): Graph documents delta only for the default calendar, so every listing lists the
 * calendar's events with their id and changeKey (singles and series masters only), and only new and changed ones are
 * read. A series is read with its instances in the occurrence window (events/{id}/instances, question 2): exceptions
 * become overrides, and occurrences Graph no longer lists were deleted. Every round is part of a full listing, which the
 * engine sweeps once it completes. Graph pages these lists by offset ($skip), so an event deleted while a listing runs
 * shifts another one out of it: before the sweep deletes the events a listing left out, confirmGone() asks Graph about
 * each (ConfirmsDeletions), and only the ones that are no longer in this calendar are deleted.
 *
 * The instances an /instances answer lists carry their master's changeKey (event-list-instances example); that editing
 * one occurrence changes the master's changeKey is UNVERIFIED for Graph. So once a week a listing reads every series
 * again, which also checks the occurrences that came into the moving window.
 *
 * Writing (question 3): Graph documents no If-Match for events. Before a write the changeKey is read again and compared
 * with the stored one (another one is a conflict); the race between that read and the write is accepted, and a 412
 * ErrorIrresolvableConflict from Graph is a conflict as well. An update sends only the fields that change. An occurrence
 * is written to its instance, found by its originalStart; a deleted occurrence deletes its instance. After a series write
 * the stored changeKey is marked (REREAD), so the next sync reads the series again, while writes still compare it. A
 * meeting (an event with attendees at Graph) gets its body without FreeScout's readable block: Graph mails every update
 * to the attendees, who see the organizer's body, and the fields and the ticket link stay in FreeScout.
 *
 * Sync state: next_link (the page the next round lists, as Graph gave it; none: the first), rereading and page_read
 * (event id => changeKey of the events a round that stopped inside that page already read) during a listing;
 * reread_from (the window start of the last weekly pass) and skipped (event id => changeKey of events that could not be
 * read, which are not read again until they change).
 */
class MicrosoftProvider implements CalendarProvider, ConfirmsDeletions, ExplainsRecurrenceRefusal
{
    public const PAGE_SIZE = 100;
    public const CALENDAR_PAGE_SIZE = 100;
    /** Instances are read in pages of 100, at most 50 of them: the 5000 occurrences OccurrenceService stores per event. */
    public const INSTANCE_PAGE_SIZE = 100;
    public const MAX_INSTANCE_PAGES = 50;
    /** Every series is read again when the occurrence window moved this many days since the last pass. */
    public const REREAD_DAYS = 7;
    /** Marks a stored changeKey after a series write: it never equals a listed one, so the series is read again. */
    public const REREAD = 'reread:';
    public const MAX_SKIPPED = 500;
    /** Events left out of a listing that confirmGone() asks Graph about even when the budget is used up. */
    public const MIN_CONFIRMATIONS = 5;

    /** What FreeScout reads of an event (event resource, v1.0). */
    private const EVENT_FIELDS = 'id,changeKey,iCalUId,type,subject,body,location,start,end,originalStartTimeZone,originalEndTimeZone,isAllDay,isCancelled,isOrganizer,sensitivity,showAs,organizer,attendees,recurrence,lastModifiedDateTime';
    private const INSTANCE_FIELDS = 'id,changeKey,type,originalStart,seriesMasterId,subject,body,location,start,end,isAllDay,isCancelled,sensitivity,showAs,lastModifiedDateTime';
    /** What a series write compares to send only what changes. */
    private const WRITE_FIELDS = 'id,changeKey,subject,body,location,sensitivity,start,end,isAllDay,recurrence,originalStartTimeZone,attendees';
    /** What finding an instance reads: enough to match it, to compare an override with it, and its attendees. */
    private const LOOKUP_FIELDS = 'id,type,originalStart,isCancelled,subject,body,location,sensitivity,start,end,isAllDay,attendees';
    /** Requests someone waits for keep their throttling waits short. */
    private const INTERACTIVE = ['max_wait' => GraphApi::INTERACTIVE_MAX_WAIT_SECONDS];

    private GraphApi $api;

    private OccurrenceService $occurrences;

    public function __construct(GraphApi $api, OccurrenceService $occurrences)
    {
        $this->api = $api;
        $this->occurrences = $occurrences;
    }

    public function canWrite(): bool
    {
        return true;
    }

    /**
     * Whether Graph's patternedRecurrence can hold the rule (GraphRecurrence::fromRrule()); otherwise the series is
     * changed one occurrence at a time.
     */
    public function writableRecurrence(VEvent $master): bool
    {
        return GraphEventMapper::canWriteRule($master);
    }

    /**
     * A monthly rule on day 29, 30 or 31, which Outlook would move to the last day of shorter months, gets its own
     * reason.
     */
    public function recurrenceRefusal(VEvent $master): ?string
    {
        if (!isset($master->RRULE) || !GraphRecurrence::onLateMonthDay((string) $master->RRULE, $master->DTSTART->getDateTime(new DateTimeZone('UTC')))) {
            return null;
        }

        return __("Microsoft calendars can't repeat on day 29, 30 or 31 of the month.");
    }

    /**
     * Asks Graph about each event a completed listing left out, in a shuffled order (events that are never listed must
     * not always go first): one that is no longer in this calendar is gone. At least MIN_CONFIRMATIONS are asked, then
     * more while the budget lasts; the rest stay for the next sweep. A check Graph can't answer now (throttling, a server
     * error) ends the checks, so the round is still saved; a connection that needs reauthorization fails it as usual.
     */
    public function confirmGone(Calendar $calendar, array $remoteIds, SyncBudget $budget): array
    {
        $connection = self::connectionOf($calendar);
        $remoteIds = array_values(array_unique($remoteIds));
        shuffle($remoteIds);
        $uids = [];
        foreach (array_chunk($remoteIds, 500) as $chunk) {
            // Only the uids: the stored events themselves (with their calendar data) aren't needed here.
            $uids += DB::table('calendar_events')->where('calendar_id', $calendar->id)->whereIn('remote_id_hash', array_map('sha1', $chunk))->pluck('uid', 'remote_id')->all();
        }
        $gone = [];
        foreach ($remoteIds as $index => $id) {
            if ($index >= self::MIN_CONFIRMATIONS && $budget->exhausted()) {
                Log::info('Microsoft events left out of a listing are checked at the next sweep', ['calendar_id' => $calendar->id, 'provider' => 'microsoft', 'count' => count($remoteIds) - $index]);
                break;
            }
            try {
                if (!$this->inCalendar($connection, $calendar, (string) $id, $uids[$id] ?? null)) {
                    $gone[] = $id;
                }
            } catch (ConnectionNeedsReauth $e) {
                throw $e;
            } catch (ProviderUnavailable $e) {
                Log::warning('Microsoft could not say whether events left out of a listing still exist; they are checked at the next sweep', ['calendar_id' => $calendar->id, 'provider' => 'microsoft', 'status' => $e->httpStatus()]);
                break;
            }
        }

        return $gone;
    }

    /**
     * Whether Graph still has the event in this calendar. An event deleted in Outlook moves to Deleted Items and keeps
     * its immutable id, and Graph doesn't check the calendar in an event's path, so a GET by id still answers. The
     * calendar's own list, filtered by the event's iCalUId, does not list it there (UNVERIFIED that Graph filters this
     * list on iCalUId; Task 15's checklist). Without an iCalUId (the mapper fell back to the id) only a 404 or 410 on
     * the event says it is gone. An answer that says nothing (400, 403, 404 on the list) keeps the event.
     */
    private function inCalendar(CalendarConnection $connection, Calendar $calendar, string $id, ?string $uid): bool
    {
        if ($uid === null || $uid === $id) {
            [$status] = $this->api->request($connection, 'GET', self::eventPath($calendar, $id), ['query' => ['$select' => 'id']], [400, 403, 404, 410], self::context($calendar));

            return !in_array($status, [404, 410], true);
        }
        $filter = "iCalUId eq '" . str_replace("'", "''", $uid) . "'";
        [$status, $page] = $this->api->request($connection, 'GET', self::eventsPath($calendar), ['query' => ['$filter' => $filter, '$select' => 'id', '$top' => 1]], [400, 403, 404], self::context($calendar));

        return $status !== 200 || !empty($page['value']);
    }

    /**
     * The account's calendars, or with $owner those of a shared mailbox (research question 6). A mailbox the account has
     * no rights on answers 403 (no Shared permission) or 404 (no Exchange right); both say whose calendars they are.
     */
    public function listCalendars(CalendarConnection $connection, ?string $owner = null): array
    {
        $owner = $owner !== null && trim($owner) !== '' ? mb_strtolower(trim($owner)) : null;
        if ($owner !== null && filter_var($owner, FILTER_VALIDATE_EMAIL) === false) {
            throw new ProviderUnavailable(__('Enter the email address of the shared mailbox.'));
        }
        $base = $owner === null ? 'me' : 'users/' . $owner;
        $calendars = [];
        $link = null;
        $seen = [];
        do {
            $options = $link === null ? ['query' => ['$select' => 'id,name,hexColor,canEdit,isDefaultCalendar', '$top' => self::CALENDAR_PAGE_SIZE]] : [];
            [$status, $page] = $this->api->request($connection, 'GET', $link ?? self::encode($base . '/calendars'), $options + self::INTERACTIVE, $owner !== null ? [403, 404] : []);
            if ($status === 403 || $status === 404) {
                throw new ProviderUnavailable(__("You don't have access to the calendars of :address.", ['address' => $owner]), $status);
            }
            foreach ((array) ($page['value'] ?? []) as $item) {
                $id = is_array($item) ? self::text($item['id'] ?? null) : null;
                if ($id === null || strpos($id, '/') !== false) {
                    continue;
                }
                $color = self::text($item['hexColor'] ?? null);
                $calendars[] = new RemoteCalendar(
                    $base . '/calendars/' . $id,
                    self::text($item['name'] ?? null) ?? $id,
                    $color !== null && preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : null,
                    null, // Graph's calendars have no time zone of their own
                    !empty($item['canEdit']),
                    !empty($item['isDefaultCalendar'])
                );
            }
            $link = self::text($page['@odata.nextLink'] ?? null);
            // A link handed out twice would page forever.
            if ($link !== null && isset($seen[$link])) {
                break;
            }
            $seen[(string) $link] = true;
        } while ($link !== null);

        return $calendars;
    }

    /**
     * Pages of the listing, while the budget lasts. Unchanged events (same changeKey) and events skipped before with the
     * same changeKey are passed on as unchanged; new and changed ones are read, at least one per round: when the budget
     * is used up inside a page, the next round lists that page again, and what this round read there counts as
     * unchanged while its changeKey is the same (page_read; in a pass that reads every series, the stored changeKey
     * alone would not say so).
     * An event listed twice in one round (Graph's offset paging after an insertion) is handled once. A saved next link
     * Graph refuses (400, 410) starts the listing over; a next link that repeats the page it came with is an error,
     * never an endless listing. A 404 on the listing usually means private events the account may not read
     * (calendar-list-events note).
     */
    public function pull(Calendar $calendar, array $state, SyncBudget $budget): ChangeSet
    {
        $connection = self::connectionOf($calendar);
        $link = self::text($state['next_link'] ?? null);
        $skipped = is_array($state['skipped'] ?? null) ? $state['skipped'] : [];
        $rereadFrom = self::text($state['reread_from'] ?? null);
        $windowFrom = $this->occurrences->window()[0]->format('Y-m-d');
        if ($link === null) {
            $rereading = $rereadFrom === null || (new DateTimeImmutable($rereadFrom))->diff(new DateTimeImmutable($windowFrom))->days >= self::REREAD_DAYS || $rereadFrom > $windowFrom;
            if ($rereading) {
                $skipped = []; // a weekly pass tries again what could not be read
            }
        } else {
            $rereading = !empty($state['rereading']);
        }
        // Only for the page the last round stopped in, which is the first page this round lists.
        $pageRead = is_array($state['page_read'] ?? null) ? $state['page_read'] : [];
        $upserts = [];
        $unchanged = [];
        $handled = [];
        $read = 0;
        $first = true;
        while (true) {
            $options = $link === null ? ['query' => ['$select' => 'id,changeKey,type', '$top' => self::PAGE_SIZE]] : [];
            [$status, $page] = $this->api->request($connection, 'GET', $link ?? self::eventsPath($calendar), $options, $first && $link !== null ? [400, 404, 410] : [404], self::context($calendar));
            if ($status === 400 || $status === 410) {
                // UNVERIFIED how Graph answers a next link that expired (a long outage, a paused connection).
                Log::info('Microsoft refused the saved page link of a calendar; its listing starts over', ['calendar_id' => $calendar->id, 'provider' => 'microsoft', 'status' => $status]);

                return $this->pull($calendar, array_diff_key($state, ['next_link' => true, 'rereading' => true, 'page_read' => true]), $budget);
            }
            if ($status === 404) {
                throw new ProviderUnavailable(__('Microsoft could not list the events of this calendar. It may have been deleted, or it holds private events this account may not see.'), 404);
            }
            $first = false;
            $items = array_values(array_filter((array) ($page['value'] ?? []), function ($item) {
                // The list holds single events and series masters (calendar-list-events reference); anything else is skipped.
                return is_array($item) && self::text($item['id'] ?? null) !== null && !in_array($item['type'] ?? null, ['occurrence', 'exception'], true);
            }));
            $stored = CalendarEvent::byRemoteIds($calendar->id, array_column($items, 'id'));
            foreach ($items as $item) {
                $id = (string) $item['id'];
                if (isset($handled[$id])) {
                    continue;
                }
                $handled[$id] = true;
                $changeKey = self::text($item['changeKey'] ?? null);
                $event = $stored[$id] ?? null;
                $series = ($item['type'] ?? null) === 'seriesMaster';
                if (($event !== null && $event->etag !== null && $event->etag === $changeKey && !($rereading && $series))
                    || ($changeKey !== null && (($skipped[$id] ?? null) === $changeKey || ($pageRead[$id] ?? null) === $changeKey))) {
                    $unchanged[] = $id;
                    continue;
                }
                if ($read > 0 && $budget->exhausted()) {
                    return $this->unfinished($upserts, $unchanged, $link, $rereading, $rereadFrom, $skipped, $pageRead);
                }
                $read++;
                if ($changeKey !== null) {
                    $pageRead[$id] = $changeKey;
                }
                try {
                    $remote = $this->event($connection, $calendar, $id);
                } catch (InvalidArgumentException | UnexpectedValueException | ParseException | InvalidDataException $e) {
                    Log::warning('A pulled calendar event was skipped', ['calendar_id' => $calendar->id, 'provider' => 'microsoft', 'remote_id_hash' => sha1($id), 'reason' => $e->getMessage()]);
                    $unchanged[] = $id; // its stored copy stays
                    if ($changeKey !== null) {
                        $skipped[$id] = $changeKey;
                    }
                    continue;
                }
                if ($remote === null) {
                    $unchanged[] = $id; // gone after the listing: the next listing decides
                    continue;
                }
                $upserts[] = $remote;
            }
            $next = self::text($page['@odata.nextLink'] ?? null);
            if ($next === null) {
                $state = ['reread_from' => $rereading ? $windowFrom : $rereadFrom, 'skipped' => array_slice($skipped, -self::MAX_SKIPPED, null, true) ?: null];

                return new ChangeSet($upserts, [], self::withoutNulls($state), true, true, $unchanged);
            }
            if ($next === $link) {
                throw new ProviderUnavailable(__('Microsoft returned the same page of events again, so the calendar could not be listed to its end.'));
            }
            $link = $next;
            $pageRead = [];
            if ($budget->exhausted()) {
                return $this->unfinished($upserts, $unchanged, $link, $rereading, $rereadFrom, $skipped, $pageRead);
            }
        }
    }

    /**
     * Creates the master (with a transactionId, so a retried POST makes no second event) and returns Graph's id,
     * changeKey and iCalUId: Graph assigns the UID. A 412 is sent once more (research question 3: Exchange answers it
     * to a create now and then). A series that brings exceptions (moved here from another calendar) gets each override
     * written to its instance and each EXDATE's instance deleted; when one has no instance its changeKey is marked, so the
     * next sync reads the series as Graph has it. When anything fails after the create, the series is deleted again.
     */
    public function create(Calendar $calendar, VCalendar $vcal): RemoteRef
    {
        $connection = self::connectionOf($calendar);
        $zone = $calendar->timezoneName();
        $master = EventIcs::master($vcal, (string) $vcal->VEVENT->UID);
        $request = ['json' => GraphEventMapper::fromVEvent($master, true, $zone) + ['transactionId' => bin2hex(random_bytes(16))]] + self::INTERACTIVE;
        try {
            [, $created] = $this->api->request($connection, 'POST', self::eventsPath($calendar), $request, [], self::context($calendar));
        } catch (ProviderConflict $e) {
            [, $created] = $this->api->request($connection, 'POST', self::eventsPath($calendar), $request, [], self::context($calendar));
        }
        $id = self::text($created['id'] ?? null);
        if ($id === null) {
            throw new ProviderUnavailable();
        }
        $etag = self::text($created['changeKey'] ?? null);
        try {
            [$written, $leftOut] = $this->writeExceptions($connection, $calendar, $id, $vcal, $master);
            if ($written) {
                $etag = $this->changeKey($connection, $calendar, $id);
            }
            if ($leftOut && $etag !== null) {
                $etag = self::REREAD . $etag;
            }
        } catch (\Throwable $e) {
            $this->discard($connection, $calendar, $id);
            throw $e;
        }
        $uid = self::text($created['iCalUId'] ?? null);

        return new RemoteRef($id, null, $etag, $uid !== null && $uid !== (string) $master->UID ? $uid : null);
    }

    /**
     * The series: its changeKey must still be the stored one, then the fields that change are written (PATCH); when none
     * does, nothing is written. A written series keeps a marked changeKey, so the next sync reads it again with what
     * Graph made of it (Outlook may have reset its exceptions). An occurrence: the same check on the series, then the
     * fields of its override that change are written to its instance, or an EXDATE deletes it. Instances share the
     * master's changeKey, which is read again after a write and returned (marked again when it was marked).
     */
    public function update(Calendar $calendar, CalendarEvent $event, VCalendar $vcal, ?string $occurrenceKey = null): RemoteRef
    {
        $id = (string) $event->remote_id;
        if ($id === '') {
            return $this->create($calendar, $vcal); // never stored at Microsoft, so it is created there now
        }
        $connection = self::connectionOf($calendar);
        if ($occurrenceKey === null) {
            return $this->updateSeries($connection, $calendar, $event, $vcal);
        }
        $tz = new DateTimeZone($event->timezone ?: $calendar->timezoneName());
        $master = EventIcs::master($vcal, $event->uid);
        $override = EventIcs::findOverride($vcal, $event->uid, $occurrenceKey, $tz);
        if ($override === null && !in_array($occurrenceKey, EventIcs::exdateKeys($master, $tz), true)) {
            throw new LogicException('Occurrence ' . $occurrenceKey . ' of event ' . $event->id . ' is neither an override nor an EXDATE');
        }
        $this->assertUnchanged($connection, $calendar, $event);
        // Where Graph has the occurrence now: at its original start, or where FreeScout last saw it moved to.
        $before = EventIcs::findOverride(Reader::read($event->ics), $event->uid, $occurrenceKey, $tz);
        $movedTo = $before !== null ? $before->DTSTART->getDateTime($tz) : null;
        $instance = $this->findInstance($connection, $calendar, $id, $occurrenceKey, !$master->DTSTART->hasTime(), $tz->getName(), $movedTo);
        $written = false;
        if ($override !== null) {
            if ($instance === null || !empty($instance['isCancelled'])) {
                throw new ProviderConflict(); // Microsoft no longer has this occurrence
            }
            $written = $this->patchChanges($connection, $calendar, (string) $instance['id'], $instance, GraphEventMapper::fromVEvent($override, false, $calendar->timezoneName()));
        } elseif ($instance !== null) {
            $this->api->request($connection, 'DELETE', self::eventPath($calendar, (string) $instance['id']), self::INTERACTIVE, [404, 410], self::context($calendar));
            $written = true;
        } else {
            Log::info('A deleted occurrence has no instance at Microsoft; nothing was deleted there', ['calendar_id' => $calendar->id, 'provider' => 'microsoft', 'remote_id_hash' => sha1($id), 'key' => $occurrenceKey]);
        }
        if (!$written) {
            return new RemoteRef($id, null, $event->etag);
        }
        $etag = $this->changeKey($connection, $calendar, $id);

        return new RemoteRef($id, null, $etag !== null && self::isMarked($event->etag) ? self::REREAD . $etag : $etag);
    }

    /** Deletes the event once its changeKey is still the stored one; an event that is gone already needs nothing. */
    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        $id = (string) $event->remote_id;
        if ($id === '') {
            return;
        }
        $connection = self::connectionOf($calendar);
        if (!$this->assertUnchanged($connection, $calendar, $event, true)) {
            return;
        }
        try {
            $this->api->request($connection, 'DELETE', self::eventPath($calendar, $id), self::INTERACTIVE, [404, 410], self::context($calendar));
        } catch (ProviderConflict $e) {
            // Exchange answers ErrorIrresolvableConflict to a delete now and then (research question 3): once more.
            $this->api->request($connection, 'DELETE', self::eventPath($calendar, $id), self::INTERACTIVE, [404, 410], self::context($calendar));
        }
    }

    /**
     * A single event, or a series with its instances in the occurrence window, as an upsert; null when Graph no longer
     * has it (deleted after the listing). Graph refusing it (400, 403) throws UnexpectedValueException: it is skipped. An
     * invitation from someone else is read-only.
     */
    private function event(CalendarConnection $connection, Calendar $calendar, string $id): ?RemoteEvent
    {
        [$status, $event] = $this->api->request($connection, 'GET', self::eventPath($calendar, $id), ['query' => ['$select' => self::EVENT_FIELDS]], [400, 403, 404], self::context($calendar));
        if ($status === 404) {
            Log::info('A Microsoft event was deleted before it was read', ['calendar_id' => $calendar->id, 'provider' => 'microsoft', 'remote_id_hash' => sha1($id)]);

            return null;
        }
        if ($status !== 200) {
            throw new UnexpectedValueException('Microsoft answered HTTP ' . $status . ' for this event');
        }
        [$instances, $window] = ($event['type'] ?? null) === 'seriesMaster' ? $this->instances($connection, $calendar, $id) : [[], null];

        return new RemoteEvent(
            $id,
            GraphEventMapper::toVCalendar($event, $instances, $window, $calendar->timezoneName(), ['calendar_id' => $calendar->id]),
            null,
            self::text($event['changeKey'] ?? null),
            ($event['isOrganizer'] ?? true) === false
        );
    }

    /**
     * A series' instances in the occurrence window, and that window; without a window when there were more pages than
     * FreeScout reads (then no occurrence is taken as deleted). An instance listed twice counts once. Graph refusing
     * them (400, 403, 404) throws UnexpectedValueException: the series keeps its stored copy.
     *
     * @return array{0: array[], 1: ?array}
     */
    private function instances(CalendarConnection $connection, Calendar $calendar, string $id): array
    {
        [$from, $to] = $this->occurrences->window();
        $query = [
            'startDateTime' => $from->format('Y-m-d\TH:i:s'), 'endDateTime' => $to->format('Y-m-d\TH:i:s'),
            '$select' => self::INSTANCE_FIELDS, '$top' => self::INSTANCE_PAGE_SIZE,
        ];
        $items = [];
        $link = null;
        $pages = 0;
        do {
            [$status, $page] = $this->api->request($connection, 'GET', $link ?? self::eventPath($calendar, $id) . '/instances', $link === null ? ['query' => $query] : [], [400, 403, 404], self::context($calendar));
            if ($status !== 200) {
                throw new UnexpectedValueException('Microsoft answered HTTP ' . $status . ' for the occurrences of this series');
            }
            foreach ((array) ($page['value'] ?? []) as $instance) {
                $key = is_array($instance) ? self::text($instance['id'] ?? null) : null;
                if ($key !== null) {
                    $items[$key] = $instance;
                }
            }
            $link = self::text($page['@odata.nextLink'] ?? null);
        } while ($link !== null && ++$pages < self::MAX_INSTANCE_PAGES);
        if ($link !== null) {
            Log::warning('A Microsoft series has more occurrences than FreeScout reads; none of them is taken as deleted', ['calendar_id' => $calendar->id, 'provider' => 'microsoft', 'remote_id_hash' => sha1($id)]);

            return [array_values($items), null];
        }

        return [array_values($items), [$from, $to]];
    }

    /**
     * A round that ends before the listing does: the next one continues with $link (null: the first page), where the
     * events in $pageRead were read already.
     */
    private function unfinished(array $upserts, array $unchanged, ?string $link, bool $rereading, ?string $rereadFrom, array $skipped, array $pageRead): ChangeSet
    {
        $state = [
            'next_link' => $link, 'rereading' => $rereading ?: null, 'reread_from' => $rereadFrom,
            'skipped'   => array_slice($skipped, -self::MAX_SKIPPED, null, true) ?: null, 'page_read' => $pageRead ?: null,
        ];

        return new ChangeSet($upserts, [], self::withoutNulls($state), false, true, $unchanged);
    }

    private function updateSeries(CalendarConnection $connection, Calendar $calendar, CalendarEvent $event, VCalendar $vcal): RemoteRef
    {
        $id = (string) $event->remote_id;
        $path = self::eventPath($calendar, $id);
        [$status, $current] = $this->api->request($connection, 'GET', $path, ['query' => ['$select' => self::WRITE_FIELDS]] + self::INTERACTIVE, [404], self::context($calendar));
        $stored = self::storedKey($event->etag);
        if ($status === 404) {
            throw new ProviderConflict(404); // deleted in Outlook meanwhile
        }
        if ($stored !== null && $stored !== self::text($current['changeKey'] ?? null)) {
            throw new ProviderConflict(); // changed in Outlook since the last sync
        }
        $changes = self::forAttendees(GraphEventMapper::fromVEvent(EventIcs::master($vcal, $event->uid), true, $calendar->timezoneName()), $current);
        $send = array_intersect_key($changes, array_flip(GraphEventMapper::changedFields($current, $changes, $calendar->timezoneName())));
        if ($send === []) {
            return new RemoteRef($id, null, $event->etag);
        }
        [, $saved] = $this->api->request($connection, 'PATCH', $path, ['json' => $send] + self::INTERACTIVE, [], self::context($calendar));
        $etag = self::text($saved['changeKey'] ?? null);
        $series = ($changes['recurrence'] ?? null) !== null || !empty($current['recurrence']);

        return new RemoteRef($id, null, $series && $etag !== null ? self::REREAD . $etag : $etag);
    }

    /** PATCHes the fields of $changes that differ from $current (an instance as Graph has it); whether it wrote anything. */
    private function patchChanges(CalendarConnection $connection, Calendar $calendar, string $id, array $current, array $changes): bool
    {
        $changes = self::forAttendees($changes, $current);
        $send = array_intersect_key($changes, array_flip(GraphEventMapper::changedFields($current, $changes, $calendar->timezoneName())));
        if ($send === []) {
            return false;
        }
        $this->api->request($connection, 'PATCH', self::eventPath($calendar, $id), ['json' => $send] + self::INTERACTIVE, [], self::context($calendar));

        return true;
    }

    /**
     * The event must still have the stored changeKey (a marked one compares without its mark); another one is a
     * conflict. One that is gone is a conflict too, unless $goneIsFine (a delete): then this returns false.
     */
    private function assertUnchanged(CalendarConnection $connection, Calendar $calendar, CalendarEvent $event, bool $goneIsFine = false): bool
    {
        [$status, $current] = $this->api->request($connection, 'GET', self::eventPath($calendar, (string) $event->remote_id), ['query' => ['$select' => 'id,changeKey']] + self::INTERACTIVE, [404], self::context($calendar));
        if ($status === 404) {
            if ($goneIsFine) {
                return false;
            }
            throw new ProviderConflict(404);
        }
        $stored = self::storedKey($event->etag);
        if ($stored !== null && $stored !== self::text($current['changeKey'] ?? null)) {
            throw new ProviderConflict();
        }

        return true;
    }

    private function changeKey(CalendarConnection $connection, Calendar $calendar, string $id): ?string
    {
        [, $current] = $this->api->request($connection, 'GET', self::eventPath($calendar, $id), ['query' => ['$select' => 'id,changeKey']] + self::INTERACTIVE, [], self::context($calendar));

        return self::text($current['changeKey'] ?? null);
    }

    /**
     * Writes the exceptions a series brings to the series just created: overrides onto their instances, EXDATEs as
     * deleted instances. Returns whether anything was written, and whether an override had no instance to go to.
     *
     * @return array{0: bool, 1: bool}
     */
    private function writeExceptions(CalendarConnection $connection, Calendar $calendar, string $id, VCalendar $vcal, VEvent $master): array
    {
        $tz = $master->DTSTART->hasTime() && !$master->DTSTART->isFloating() ? $master->DTSTART->getDateTime()->getTimezone() : new DateTimeZone($calendar->timezoneName());
        $allDay = !$master->DTSTART->hasTime();
        $written = false;
        $leftOut = false;
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ($vevent === $master || (string) $vevent->UID !== (string) $master->UID || !isset($vevent->{'RECURRENCE-ID'})) {
                continue;
            }
            $key = EventIcs::recurrenceKey($vevent->{'RECURRENCE-ID'}, $tz);
            $instance = $this->findInstance($connection, $calendar, $id, $key, $allDay, $tz->getName(), null);
            if ($instance === null) {
                Log::info('An edited occurrence that is no occurrence of the series in Microsoft was left out', ['calendar_id' => $calendar->id, 'provider' => 'microsoft', 'key' => $key]);
                $leftOut = true;
                continue;
            }
            $written = $this->patchChanges($connection, $calendar, (string) $instance['id'], $instance, GraphEventMapper::fromVEvent($vevent, false, $calendar->timezoneName())) || $written;
        }
        foreach (EventIcs::exdateKeys($master, $tz) as $key) {
            $instance = $this->findInstance($connection, $calendar, $id, $key, $allDay, $tz->getName(), null);
            if ($instance !== null) {
                $this->api->request($connection, 'DELETE', self::eventPath($calendar, (string) $instance['id']), self::INTERACTIVE, [404, 410], self::context($calendar));
                $written = true;
            }
        }

        return [$written, $leftOut];
    }

    /**
     * The instance of one occurrence: listed around its original start (a day before to two days after), or around
     * $movedTo, where FreeScout last saw it moved; only an instance whose originalStart is that occurrence counts.
     * Null when Graph has none (or the series is gone). An all-day key is read in $zone; Graph's original starts of
     * all-day occurrences read as their dates while that zone is within 12 hours of the series' own zone.
     */
    private function findInstance(CalendarConnection $connection, Calendar $calendar, string $masterId, string $key, bool $allDay, string $zone, ?DateTimeImmutable $movedTo): ?array
    {
        $utc = new DateTimeZone('UTC');
        $original = strlen($key) === 8 ? DateTimeImmutable::createFromFormat('!Ymd', $key, $utc) : DateTimeImmutable::createFromFormat('Ymd\THis\Z', $key, $utc);
        $around = [$original];
        if ($movedTo !== null && abs($movedTo->getTimestamp() - $original->getTimestamp()) > 86400) {
            $around[] = $movedTo->setTimezone($utc);
        }
        foreach ($around as $at) {
            [$status, $page] = $this->api->request($connection, 'GET', self::eventPath($calendar, $masterId) . '/instances', ['query' => [
                'startDateTime' => $at->modify('-1 day')->format('Y-m-d\TH:i:s'), 'endDateTime' => $at->modify('+2 days')->format('Y-m-d\TH:i:s'),
                '$select' => self::LOOKUP_FIELDS, '$top' => self::INSTANCE_PAGE_SIZE,
            ]] + self::INTERACTIVE, [404], self::context($calendar));
            if ($status === 404) {
                return null;
            }
            foreach ((array) ($page['value'] ?? []) as $instance) {
                if (is_array($instance) && self::text($instance['id'] ?? null) !== null && isset($instance['originalStart'])
                    && GraphEventMapper::occurrenceKey($instance, $allDay, $zone) === $key) {
                    return $instance;
                }
            }
        }

        return null;
    }

    /** Deletes a series created a moment ago; when that fails too, the failure is logged and the first error stands. */
    private function discard(CalendarConnection $connection, Calendar $calendar, string $id): void
    {
        try {
            $this->api->request($connection, 'DELETE', self::eventPath($calendar, $id), self::INTERACTIVE, [404, 410], self::context($calendar));
        } catch (ProviderException $e) {
            Log::warning('A half-written Microsoft series could not be removed again', ['calendar_id' => $calendar->id, 'provider' => 'microsoft', 'remote_id_hash' => sha1($id), 'status' => $e->httpStatus(), 'error' => $e->userMessage()]);
        }
    }

    /**
     * The body without FreeScout's readable block when the event at Graph has attendees: Graph mails them every update,
     * and they see the organizer's body (decision 2026-10-09). Graph's attendees never include the organizer.
     */
    private static function forAttendees(array $changes, array $current): array
    {
        if (!empty($current['attendees']) && is_array($current['attendees']) && isset($changes['body']['content'])) {
            $changes['body']['content'] = ReadableBlock::strip((string) $changes['body']['content']);
        }

        return $changes;
    }

    /** The stored changeKey without its REREAD mark. */
    private static function storedKey(?string $etag): ?string
    {
        return self::isMarked($etag) ? substr((string) $etag, strlen(self::REREAD)) : $etag;
    }

    private static function isMarked(?string $etag): bool
    {
        return $etag !== null && strncmp($etag, self::REREAD, strlen(self::REREAD)) === 0;
    }

    private static function connectionOf(Calendar $calendar): CalendarConnection
    {
        $connection = $calendar->calendarConnection;
        if ($connection === null) {
            throw new ProviderUnavailable(__('This calendar has no Microsoft account.'));
        }

        return $connection;
    }

    /** The calendar's events, from its Graph path; a remote id of another form is refused, never sent. */
    private static function eventsPath(Calendar $calendar): string
    {
        $remoteId = trim((string) $calendar->remote_id);
        if (!preg_match('~^(me|users/[^/\s]+)/calendars/[^/\s]+$~', $remoteId)) {
            throw new ProviderUnavailable(__('This calendar is not linked to a Microsoft calendar.'));
        }

        return self::encode($remoteId) . '/events';
    }

    private static function eventPath(Calendar $calendar, string $id): string
    {
        return self::eventsPath($calendar) . '/' . rawurlencode($id);
    }

    /** A Graph path with each segment encoded: ids end in "=", an address holds "@". */
    private static function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function withoutNulls(array $state): array
    {
        return array_filter($state, function ($value) {
            return $value !== null;
        });
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
