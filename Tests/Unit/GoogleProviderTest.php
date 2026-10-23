<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\Google\GoogleApi;
use Modules\LJPcCalendarModule\CalendarProviders\Google\GoogleProvider;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteCalendar;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\ExportService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\SyncService;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

class GoogleProviderTest extends TestCase
{
    private const BASE = 'https://www.googleapis.com/calendar/v3/';
    private const EVENTS = 'calendars/agent%40example.com/events';
    private const SERIES = '7r3c9u1v5n2k8m4p6q0s2t4u6v';
    private const SINGLE = '5c1h8k3d9q2m4n6p7r0s1t2u3v';
    private const ZONE = 'Europe/Amsterdam';
    private const SCOPES = 'https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events';

    private FakeHttp $http;

    private GoogleProvider $provider;

    private Calendar $calendar;

    /** @var float[] */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->http = FakeHttp::install();
        app(ConnectionService::class)->saveAppCredentials('google', ['client_id' => 'test-client-id.apps.example.com', 'client_secret' => 'test-google-secret']);
        app()->instance(GoogleApi::class, new GoogleApi($this->http, app(ConnectionService::class), function (float $seconds) {
            $this->sleeps[] = $seconds;
        }));
        $this->provider = app(GoogleProvider::class);
        $connection = new CalendarConnection(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'agent@example.com']);
        $connection->setCredentials(['refresh_token' => 'test-refresh-1', 'access_token' => 'test-access-1', 'expires_at' => time() + 3600, 'scopes' => self::SCOPES]);
        $connection->save();
        $this->calendar = Calendar::create([
            'name' => 'Google ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $connection->id,
            'remote_id' => 'agent@example.com', 'timezone' => self::ZONE, 'sync_interval' => 15,
        ]);
    }

    private static function fixture(string $name, array $changes = []): array
    {
        return array_merge(json_decode((string) file_get_contents(__DIR__ . '/../fixtures/google/' . $name . '.json'), true), $changes);
    }

    private static function answer(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode($body));
    }

    /** One page of events.list, as the events.list reference describes its answer. */
    private static function page(array $items, array $extra = []): Response
    {
        return self::answer(200, $extra + [
            'kind' => 'calendar#events', 'etag' => '"p1"', 'summary' => 'agent@example.com', 'updated' => '2026-10-05T08:00:00.000Z',
            'timeZone' => self::ZONE, 'accessRole' => 'owner', 'defaultReminders' => [], 'items' => $items,
        ]);
    }

    private static function googleError(int $status, string $reason, string $message): Response
    {
        return self::answer($status, ['error' => ['errors' => [['domain' => 'global', 'reason' => $reason, 'message' => $message]], 'code' => $status, 'message' => $message]]);
    }

    /** An unmodified instance of the weekly series as Google returns it for one original start (10:00 in Amsterdam). */
    private static function occurrence(string $date, string $offset, array $changes = [], string $hour = '10'): array
    {
        $start = $date . 'T' . $hour . ':00:00' . $offset;
        $key = (new DateTimeImmutable($start))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

        return array_merge(self::fixture('instance-modified'), [
            'etag' => '"instance-' . $key . '"', 'id' => self::SERIES . '_' . $key, 'summary' => 'Weekly planning', 'location' => null,
            'start' => ['dateTime' => $start, 'timeZone' => self::ZONE], 'end' => ['dateTime' => $date . 'T' . ((int) $hour + 1) . ':00:00' . $offset, 'timeZone' => self::ZONE],
            'originalStartTime' => ['dateTime' => $start, 'timeZone' => self::ZONE],
            'attendees' => [['email' => 'colleague@example.com', 'responseStatus' => 'accepted']],
        ], $changes);
    }

    /**
     * events.instances of the weekly series: its occurrences in the window, unmodified unless $replace gives one by
     * date (null leaves it out). 19 October is an EXDATE of the rule, so Google doesn't list it.
     */
    private static function seriesInstances(array $fields = [], array $replace = [], string $hour = '10', array $dates = ['2026-10-05' => '+02:00', '2026-10-12' => '+02:00', '2026-10-26' => '+01:00', '2026-11-02' => '+01:00', '2026-11-09' => '+01:00']): Response
    {
        $items = [];
        foreach ($dates as $date => $offset) {
            $items[] = array_key_exists($date, $replace) ? $replace[$date] : self::occurrence($date, $offset, $fields, $hour);
        }

        return self::answer(200, ['kind' => 'calendar#events', 'summary' => 'agent@example.com', 'timeZone' => self::ZONE, 'accessRole' => 'owner', 'items' => array_values(array_filter($items))]);
    }

    /** Each request as "METHOD path", relative to the API. */
    private function requests(): array
    {
        return array_map(function (array $request) {
            return $request['method'] . ' ' . substr(strtok($request['url'], '?'), strlen(self::BASE));
        }, $this->http->history());
    }

    private function sent(int $index): array
    {
        return $this->http->history()[$index];
    }

    private static function query(array $request): array
    {
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);

        return $query;
    }

    private static function body(array $request): array
    {
        return json_decode($request['body'], true);
    }

    private function pull(array $state = []): ChangeSet
    {
        return $this->provider->pull($this->calendar, $state, SyncBudget::seconds(30));
    }

    private function sync(float $seconds = 30): void
    {
        app(SyncService::class)->syncCalendar($this->calendar, SyncBudget::seconds($seconds));
        $this->calendar->refresh();
    }

    private function stored(string $remoteId): ?CalendarEvent
    {
        return CalendarEvent::findByRemoteId($this->calendar->id, $remoteId);
    }

    /** The series as a full sync stores it, with these exceptions listed next to it. */
    private function storeSeries(array $exceptions = []): CalendarEvent
    {
        $this->http->queue(self::page(array_merge([self::fixture('recurring')], $exceptions), ['nextSyncToken' => 'sync-1']));
        $this->sync();

        return $this->stored(self::SERIES);
    }

    private static function master(string $ics): VEvent
    {
        foreach (Reader::read($ics)->select('VEVENT') as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $vevent;
            }
        }
        self::fail('No master');
    }

    /** The occurrence keys of the master's EXDATEs and of the overrides. */
    private static function exceptions(string $ics): array
    {
        $vcal = Reader::read($ics);
        $utc = new DateTimeZone('UTC');
        $exdates = [];
        $overrides = [];
        foreach ($vcal->select('VEVENT') as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                $overrides[] = EventIcs::recurrenceKey($vevent->{'RECURRENCE-ID'}, $utc);
            }
            foreach ($vevent->select('EXDATE') as $exdate) {
                foreach ($exdate->getDateTimes() as $dateTime) {
                    $exdates[] = $exdate->hasTime() ? $dateTime->setTimezone($utc)->format('Ymd\THis\Z') : $dateTime->format('Ymd');
                }
            }
        }
        sort($exdates);
        sort($overrides);

        return ['exdates' => $exdates, 'overrides' => $overrides];
    }

    private function failure(callable $call): ProviderException
    {
        try {
            $call();
        } catch (ProviderException $e) {
            return $e;
        }
        $this->fail('The call did not fail');
    }

    public function test_the_calendar_list_maps_access_roles_names_colors_and_zones(): void
    {
        $this->http->queue(
            self::answer(200, ['kind' => 'calendar#calendarList', 'nextPageToken' => 'list-2', 'items' => [
                ['kind' => 'calendar#calendarListEntry', 'id' => 'agent@example.com', 'summary' => 'agent@example.com', 'timeZone' => self::ZONE, 'backgroundColor' => '#9fe1e7', 'foregroundColor' => '#000000', 'accessRole' => 'owner', 'primary' => true],
                ['kind' => 'calendar#calendarListEntry', 'id' => 'c_0123456789abcdef@group.calendar.google.com', 'summary' => 'Support rota', 'summaryOverride' => 'Rota', 'timeZone' => self::ZONE, 'backgroundColor' => '#F83A22', 'accessRole' => 'writer'],
                ['kind' => 'calendar#calendarListEntry', 'id' => 'c_0f1e2d3c4b5a@group.v.calendar.google.com', 'summary' => 'Holidays in the Netherlands', 'timeZone' => self::ZONE, 'backgroundColor' => '#16a765', 'accessRole' => 'reader'],
            ]]),
            self::answer(200, ['kind' => 'calendar#calendarList', 'nextSyncToken' => 'list-sync', 'items' => [
                ['kind' => 'calendar#calendarListEntry', 'id' => 'colleague@example.com', 'summary' => 'colleague@example.com', 'timeZone' => 'America/New_York', 'backgroundColor' => 'blue', 'accessRole' => 'freeBusyReader'],
                ['kind' => 'calendar#calendarListEntry', 'id' => 'c_fedcba9876543210@group.calendar.google.com', 'summary' => 'Team', 'timeZone' => 'GMT+05:30', 'accessRole' => 'writerWithoutPrivateAccess'],
            ]])
        );

        $calendars = $this->provider->listCalendars($this->calendar->calendarConnection);

        $this->assertSame([
            ['agent@example.com', 'agent@example.com', '#9fe1e7', self::ZONE, true, true],
            ['c_0123456789abcdef@group.calendar.google.com', 'Rota', '#F83A22', self::ZONE, true, false],
            ['c_0f1e2d3c4b5a@group.v.calendar.google.com', 'Holidays in the Netherlands', '#16a765', self::ZONE, false, false],
            ['colleague@example.com', 'colleague@example.com', null, 'America/New_York', false, false],
            ['c_fedcba9876543210@group.calendar.google.com', 'Team', null, null, true, false],
        ], array_map(function (RemoteCalendar $calendar) {
            return [$calendar->remoteId, $calendar->name, $calendar->color, $calendar->timezone, $calendar->writable, $calendar->primary];
        }, $calendars));
        $this->assertSame(['GET users/me/calendarList', 'GET users/me/calendarList'], $this->requests());
        $this->assertSame([['maxResults' => '250'], ['maxResults' => '250', 'pageToken' => 'list-2']], [self::query($this->sent(0)), self::query($this->sent(1))]);
        $this->assertTrue($this->provider->canWrite());
        $series = EventIcs::build(['uid' => 'rule@example.com', 'title' => 'x', 'all_day' => true, 'start' => '2026-10-05', 'end' => '2026-10-06', 'rrule' => 'FREQ=MONTHLY;BYDAY=2TU,-1FR;BYSETPOS=1']);
        $this->assertTrue($this->provider->writableRecurrence($series->VEVENT));
    }

    public function test_a_full_sync_pages_through_the_events_and_resumes_after_a_budget_stop(): void
    {
        $this->http->queue(
            self::page([self::fixture('single-timed'), self::fixture('recurring'), self::fixture('instance-cancelled')], ['nextPageToken' => 'page-2']),
            self::page([self::fixture('all-day'), self::fixture('instance-modified')], ['nextSyncToken' => 'sync-1'])
        );

        // No budget left after the first page: the run stops there and the next one continues.
        $this->sync(0);

        $this->assertSame(['page_token' => 'page-2', 'listed' => 2], array_intersect_key($this->calendar->sync_state, ['sync_token' => 1, 'page_token' => 1, 'listed' => 1]));
        $this->assertTrue($this->calendar->sync_state['_listing']);
        $this->assertNull($this->calendar->last_synced_at);
        $this->assertSame(['exdates' => ['20261012T080000Z', '20261019T080000Z'], 'overrides' => []], self::exceptions($this->stored(self::SERIES)->ics));

        $this->sync();

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS], $this->requests());
        $this->assertSame(['singleEvents' => 'false', 'showDeleted' => 'true', 'maxResults' => '250'], self::query($this->sent(0)));
        $this->assertSame(['singleEvents' => 'false', 'showDeleted' => 'true', 'maxResults' => '250', 'pageToken' => 'page-2'], self::query($this->sent(1)));
        $this->assertSame(['sync_token' => 'sync-1'], array_intersect_key($this->calendar->sync_state, ['sync_token' => 1, 'page_token' => 1, 'listed' => 1]));
        $this->assertFalse($this->calendar->sync_state['_listing']);
        $this->assertNotNull($this->calendar->last_synced_at);
        $this->assertSame(3, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
        // The instance moved on page 2 joined the series stored from page 1.
        $series = $this->stored(self::SERIES);
        $this->assertSame(['exdates' => ['20261012T080000Z', '20261019T080000Z'], 'overrides' => ['20261026T090000Z']], self::exceptions($series->ics));
        $this->assertSame(['"3561234567892000"', null, false], [$series->etag, $series->remote_href, $series->read_only]);
        $this->assertSame('Trade fair', $this->stored('0a2b4c6d8e0f1g3h5i7j9k1l3m')->summary);
    }

    public function test_an_incremental_sync_brings_a_changed_and_a_cancelled_instance_only(): void
    {
        $this->storeSeries();
        $changes = self::page([self::fixture('instance-modified'), self::fixture('instance-cancelled')], ['nextSyncToken' => 'sync-2']);
        $this->http->queue($changes, $changes);

        $result = $this->pull(['sync_token' => 'sync-1']);

        $this->assertSame(['singleEvents' => 'false', 'showDeleted' => 'true', 'maxResults' => '250', 'syncToken' => 'sync-1'], self::query($this->sent(1)));
        $this->assertSame([[], ['sync_token' => 'sync-2'], true, false], [$result->deletes, $result->state, $result->complete, $result->fullListing]);
        $this->assertCount(1, $result->upserts);
        $remote = $result->upserts[0];
        $this->assertSame([self::SERIES, null, null, false, true, ['20261012T080000Z']], [$remote->remoteId, $remote->href, $remote->etag, $remote->readOnly, $remote->partial, $remote->cancelledKeys]);
        $this->assertSame(['exdates' => [], 'overrides' => ['20261026T090000Z']], self::exceptions($remote->ics));

        $this->sync();

        $series = $this->stored(self::SERIES);
        $this->assertSame(['exdates' => ['20261012T080000Z', '20261019T080000Z'], 'overrides' => ['20261026T090000Z']], self::exceptions($series->ics));
        $this->assertSame(['"3561234567892000"', 'Weekly planning'], [$series->etag, $series->summary]);
        $this->assertSame('sync-2', $this->calendar->sync_state['sync_token']);
    }

    public function test_a_stored_series_whose_master_changed_is_read_again_whole(): void
    {
        $this->storeSeries([self::fixture('instance-modified'), self::fixture('instance-cancelled')]);
        $renamed = self::fixture('recurring', ['etag' => '"3561234567899000"', 'updated' => '2026-10-06T07:00:00.000Z', 'summary' => 'Weekly planning with Example Corp', 'sequence' => 2]);
        // Google lists only the master; its exceptions come from its instances.
        $this->http->queue(
            self::page([$renamed], ['nextSyncToken' => 'sync-2']),
            self::seriesInstances(['summary' => 'Weekly planning with Example Corp'], ['2026-10-12' => self::fixture('instance-cancelled'), '2026-10-26' => self::fixture('instance-modified')])
        );

        $result = $this->pull(['sync_token' => 'sync-1']);

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES . '/instances'], $this->requests());
        $this->assertSame(['timeMin' => '2025-10-05T00:00:00+00:00', 'timeMax' => '2028-10-05T00:00:00+00:00', 'showDeleted' => 'true', 'maxResults' => '2500'], self::query($this->sent(2)));
        $remote = $result->upserts[0];
        $this->assertSame([false, '"3561234567899000"', []], [$remote->partial, $remote->etag, $remote->cancelledKeys]);
        $this->assertSame(['exdates' => ['20261012T080000Z', '20261019T080000Z'], 'overrides' => ['20261026T090000Z']], self::exceptions($remote->ics));
        $this->assertSame('Weekly planning (moved)', (string) EventIcs::findOverride(Reader::read($remote->ics), self::SERIES . '@google.com', '20261026T090000Z', new DateTimeZone('UTC'))->SUMMARY);
    }

    public function test_a_round_with_many_changed_series_stops_within_its_budget_and_goes_on_where_it_stopped(): void
    {
        $series = [];
        foreach (['a', 'b', 'c'] as $letter) {
            $series[$letter] = self::fixture('recurring', ['id' => 'series' . $letter . 'x9k2m4p6q8r0s2t4', 'iCalUID' => 'series-' . $letter . '@google.com', 'etag' => '"35612345678' . $letter . '1000"']);
        }
        $this->http->queue(self::page(array_values($series), ['nextSyncToken' => 'sync-1']));
        $this->sync();
        // A bulk edit in Google: every series changed, and each is read again with its instances.
        $changed = array_map(function (array $master) {
            return ['etag' => str_replace('1000"', '2000"', $master['etag']), 'summary' => 'Renamed'] + $master;
        }, $series);
        $page = function () use ($changed) {
            return self::page(array_values($changed), ['nextSyncToken' => 'sync-2']);
        };
        $noExceptions = function () {
            return self::answer(200, ['kind' => 'calendar#events', 'items' => []]);
        };
        $this->http->queue($page(), $noExceptions(), $page(), $noExceptions(), $page(), $noExceptions());

        // No budget left: each run reads one series again, and the next run lists the same page and skips what is done.
        foreach ([1, 2, 3] as $run) {
            $this->sync(0);
        }

        $instances = array_values(array_filter($this->requests(), function (string $request) {
            return substr($request, -10) === '/instances';
        }));
        $this->assertSame(['GET ' . self::EVENTS . '/seriesax9k2m4p6q8r0s2t4/instances', 'GET ' . self::EVENTS . '/seriesbx9k2m4p6q8r0s2t4/instances', 'GET ' . self::EVENTS . '/seriescx9k2m4p6q8r0s2t4/instances'], $instances);
        $this->assertSame(0, $this->http->pending());
        $this->assertSame(['sync_token' => 'sync-2'], array_intersect_key($this->calendar->sync_state, ['sync_token' => 1, 'page_token' => 1, 'page_done' => 1]));
        $this->assertSame(['Renamed', 'Renamed', 'Renamed'], CalendarEvent::where('calendar_id', $this->calendar->id)->orderBy('uid')->pluck('summary')->all());
    }

    public function test_an_event_that_started_reading_finishes_before_the_round_stops(): void
    {
        $this->http->queue(self::page([self::fixture('single-timed')], ['nextSyncToken' => 'sync-1']));
        $this->sync();
        // Changed occurrences of two series FreeScout doesn't have: each series is read, then its instances.
        $exceptions = [];
        foreach (['a', 'b'] as $letter) {
            $exceptions[$letter] = self::fixture('instance-modified', ['id' => 'series' . $letter . 'x9k2m4p6q8r0s2t4_20261026T080000Z', 'recurringEventId' => 'series' . $letter . 'x9k2m4p6q8r0s2t4', 'iCalUID' => 'series-' . $letter . '@google.com']);
        }
        $master = function (string $letter) {
            return self::answer(200, self::fixture('recurring', ['id' => 'series' . $letter . 'x9k2m4p6q8r0s2t4', 'iCalUID' => 'series-' . $letter . '@google.com']));
        };
        $page = function () use ($exceptions) {
            return self::page(array_values($exceptions), ['nextSyncToken' => 'sync-2']);
        };
        $noExceptions = function () {
            return self::answer(200, ['kind' => 'calendar#events', 'items' => []]);
        };
        $this->http->queue($page(), $master('a'), $noExceptions(), $page(), $master('b'), $noExceptions());

        $this->sync(0);
        $this->sync(0);

        $this->assertSame([
            'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/seriesax9k2m4p6q8r0s2t4', 'GET ' . self::EVENTS . '/seriesax9k2m4p6q8r0s2t4/instances',
            'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/seriesbx9k2m4p6q8r0s2t4', 'GET ' . self::EVENTS . '/seriesbx9k2m4p6q8r0s2t4/instances',
        ], array_slice($this->requests(), 1));
        $this->assertSame('sync-2', $this->calendar->sync_state['sync_token']);
        $this->assertNotNull($this->stored('seriesbx9k2m4p6q8r0s2t4'));
    }

    public function test_a_stored_series_that_moved_keeps_only_the_exceptions_google_has(): void
    {
        $this->storeSeries([self::fixture('instance-modified'), self::fixture('instance-cancelled')]);
        // Moved to 14:00, and the EXDATE of 19 October removed from the rule; no exception is left.
        $moved = self::fixture('recurring', [
            'etag' => '"3561234567899100"', 'updated' => '2026-10-06T07:00:00.000Z', 'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=6'],
            'start' => ['dateTime' => '2026-10-05T14:00:00+02:00', 'timeZone' => self::ZONE], 'end' => ['dateTime' => '2026-10-05T15:00:00+02:00', 'timeZone' => self::ZONE],
        ]);
        $this->http->queue(
            self::page([$moved], ['nextSyncToken' => 'sync-2']),
            self::seriesInstances([], [], '14', ['2026-10-05' => '+02:00', '2026-10-12' => '+02:00', '2026-10-19' => '+02:00', '2026-10-26' => '+01:00', '2026-11-02' => '+01:00', '2026-11-09' => '+01:00'])
        );

        $this->sync();

        $series = $this->stored(self::SERIES);
        $this->assertSame(['exdates' => [], 'overrides' => []], self::exceptions($series->ics));
        $this->assertSame(6, $series->occurrences()->count());
        $this->assertSame([null, 'sync-2'], [$this->calendar->last_error, $this->calendar->sync_state['sync_token']]);
    }

    public function test_a_stored_series_that_became_all_day_is_stored_all_day(): void
    {
        $this->storeSeries([self::fixture('instance-modified'), self::fixture('instance-cancelled')]);
        $allDay = self::fixture('recurring', ['etag' => '"3561234567899200"', 'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=6'], 'start' => ['date' => '2026-10-05'], 'end' => ['date' => '2026-10-06']]);
        $instances = [];
        foreach (['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02', '2026-11-09'] as $date) {
            $day = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
            $instances[] = ['id' => self::SERIES . '_' . str_replace('-', '', $date), 'status' => $date === '2026-10-12' ? 'cancelled' : 'confirmed', 'summary' => 'Weekly planning', 'description' => 'Plan the support week.',
                'start' => ['date' => $date], 'end' => ['date' => $day], 'recurringEventId' => self::SERIES, 'originalStartTime' => ['date' => $date]];
        }
        $this->http->queue(self::page([$allDay], ['nextSyncToken' => 'sync-2']), self::answer(200, ['kind' => 'calendar#events', 'items' => $instances]));

        $this->sync();

        $series = $this->stored(self::SERIES);
        $this->assertSame([true, '"3561234567899200"', null], [$series->all_day, $series->etag, $this->calendar->last_error]);
        $this->assertSame(['exdates' => ['20261012'], 'overrides' => []], self::exceptions($series->ics));
        $this->assertSame(5, $series->occurrences()->count());
    }

    public function test_exceptions_listed_before_their_series_fetch_the_series(): void
    {
        $orphan = self::fixture('instance-modified', ['id' => 'gone_20261026T090000Z', 'recurringEventId' => 'gone']);
        $this->http->queue(
            self::page([self::fixture('instance-modified'), $orphan], ['nextPageToken' => 'page-2']),
            self::answer(200, self::fixture('recurring')),
            self::googleError(404, 'notFound', 'Not Found'),
            self::page([self::fixture('recurring')], ['nextSyncToken' => 'sync-1'])
        );

        $this->sync();

        // Page 2 lists the series as it was read for page 1: unchanged, so it is not read again.
        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES, 'GET ' . self::EVENTS . '/gone', 'GET ' . self::EVENTS], $this->requests());
        $series = $this->stored(self::SERIES);
        $this->assertSame(['exdates' => ['20261019T080000Z'], 'overrides' => ['20261026T090000Z']], self::exceptions($series->ics));
        $this->assertNull($this->stored('gone'));
        $this->assertSame(1, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
        $this->assertSame([null, 0], [$this->calendar->last_error, $this->calendar->sync_failures]);
    }

    public function test_a_forgotten_sync_token_starts_a_full_listing(): void
    {
        $this->http->queue(
            self::googleError(410, 'fullSyncRequired', 'Sync token is no longer valid, a full sync is required.'),
            self::page([self::fixture('single-timed')], ['nextSyncToken' => 'sync-new'])
        );

        $result = $this->pull(['sync_token' => 'sync-old', 'page_token' => 'page-3']);

        $this->assertSame(['syncToken' => 'sync-old', 'pageToken' => 'page-3'], array_intersect_key(self::query($this->sent(0)), ['syncToken' => 1, 'pageToken' => 1]));
        $this->assertSame(['singleEvents' => 'false', 'showDeleted' => 'true', 'maxResults' => '250'], self::query($this->sent(1)));
        $this->assertSame([true, true, ['sync_token' => 'sync-new']], [$result->fullListing, $result->complete, $result->state]);
        $this->assertSame([self::SINGLE], array_map(function (RemoteEvent $remote) {
            return $remote->remoteId;
        }, $result->upserts));

        // A listing without a token that answers 410 is an error, never a loop.
        $this->http->queue(self::googleError(410, 'fullSyncRequired', 'Sync token is no longer valid, a full sync is required.'));
        $e = $this->failure(function () {
            $this->pull();
        });
        $this->assertSame([ProviderUnavailable::class, 410], [get_class($e), $e->httpStatus()]);
        $this->assertCount(3, $this->http->history());
    }

    public function test_cancelled_series_and_single_events_are_deletes(): void
    {
        $this->http->queue(self::page([self::fixture('single-timed'), self::fixture('recurring')], ['nextSyncToken' => 'sync-1']));
        $this->sync();
        $this->http->queue(self::page([
            ['kind' => 'calendar#event', 'etag' => '"3561234567899100"', 'id' => self::SERIES, 'status' => 'cancelled'],
            ['kind' => 'calendar#event', 'etag' => '"3561234567899200"', 'id' => self::SINGLE, 'status' => 'cancelled'],
            self::fixture('instance-modified'),
        ], ['nextSyncToken' => 'sync-2']));

        $this->sync();

        // The moved instance belongs to the deleted series: it is not looked up.
        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS], $this->requests());
        $this->assertSame(0, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
        $this->assertSame('sync-2', $this->calendar->sync_state['sync_token']);
    }

    public function test_events_of_a_read_only_calendar_are_read_only_and_private_ones_busy(): void
    {
        $this->http->queue(
            self::page([self::fixture('single-timed'), self::fixture('private')], ['accessRole' => 'reader', 'nextSyncToken' => 'sync-1']),
            self::page([self::fixture('single-timed')], ['accessRole' => 'writerWithoutPrivateAccess', 'nextSyncToken' => 'sync-2'])
        );

        $this->sync();

        $single = $this->stored(self::SINGLE);
        $private = $this->stored('9p1r3t5v7a0c2e4g6i8k0m2o4q');
        $this->assertSame([true, 'Intake call with Example Corp', false], [$single->read_only, $single->summary, $single->is_private]);
        $this->assertSame([true, 'Busy', true], [$private->read_only, $private->summary, $private->is_private]);
        $this->assertStringNotContainsString('insurance', $private->ics);
        // A role that may write gives writable events.
        $this->assertFalse($this->pull(['sync_token' => 'sync-1'])->upserts[0]->readOnly);
    }

    public function test_a_free_busy_calendar_stores_every_busy_block_on_its_own(): void
    {
        // Free/busy access gives times only, and occurrences as events of their own (research question 11; UNVERIFIED).
        $block = function (string $id, string $start, string $end) {
            return ['kind' => 'calendar#event', 'etag' => '"fb-' . $id . '"', 'id' => $id, 'status' => 'confirmed', 'updated' => '2026-10-02T12:00:00.000Z',
                'start' => ['dateTime' => $start, 'timeZone' => self::ZONE], 'end' => ['dateTime' => $end, 'timeZone' => self::ZONE],
                'iCalUID' => self::SERIES . '@google.com', 'recurringEventId' => self::SERIES, 'originalStartTime' => ['dateTime' => $start, 'timeZone' => self::ZONE]];
        };
        // A series that does come with its rule keeps it.
        $series = ['kind' => 'calendar#event', 'etag' => '"fb-series"', 'id' => 'busy-series', 'status' => 'confirmed', 'iCalUID' => 'busy-series@google.com', 'recurrence' => ['RRULE:FREQ=DAILY;COUNT=3'],
            'start' => ['dateTime' => '2026-10-07T09:00:00+02:00', 'timeZone' => self::ZONE], 'end' => ['dateTime' => '2026-10-07T09:30:00+02:00', 'timeZone' => self::ZONE]];
        $page = self::page([
            $block(self::SERIES . '_20261005T080000Z', '2026-10-05T10:00:00+02:00', '2026-10-05T11:00:00+02:00'),
            $block(self::SERIES . '_20261026T090000Z', '2026-10-26T10:00:00+01:00', '2026-10-26T11:00:00+01:00'),
            $series,
            ['kind' => 'calendar#event', 'id' => 'removed-block', 'status' => 'cancelled'],
        ], ['accessRole' => 'freeBusyReader', 'nextSyncToken' => 'sync-1']);
        $this->http->queue($page, $page);

        $result = $this->pull();

        $this->assertSame(['removed-block'], $result->deletes);
        $this->assertSame([self::SERIES . '_20261005T080000Z', self::SERIES . '_20261026T090000Z', 'busy-series'], array_map(function (RemoteEvent $remote) {
            return $remote->remoteId;
        }, $result->upserts));
        foreach ($result->upserts as $remote) {
            $vevent = Reader::read($remote->ics)->VEVENT;
            $this->assertSame(['PRIVATE', true, false], [(string) $vevent->CLASS, $remote->readOnly, $remote->partial]);
            $this->assertFalse(isset($vevent->{'RECURRENCE-ID'}));
        }
        [$first, , $rule] = array_map(function (RemoteEvent $remote) {
            return Reader::read($remote->ics)->VEVENT;
        }, $result->upserts);
        $this->assertSame([self::SERIES . '_20261005T080000Z', false], [(string) $first->UID, isset($first->RRULE)]);
        $this->assertSame(['busy-series@google.com', 'FREQ=DAILY;COUNT=3'], [(string) $rule->UID, (string) $rule->RRULE]);

        // Stored, the series is not read again through its instances, which a free/busy reader can't see.
        $this->sync();
        $this->http->queue(self::page([$series], ['accessRole' => 'freeBusyReader', 'nextSyncToken' => 'sync-2']));
        $this->pull(['sync_token' => 'sync-1']);
        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS, 'GET ' . self::EVENTS], $this->requests());
    }

    public function test_a_full_listing_that_finds_nothing_keeps_no_sync_token(): void
    {
        $this->http->queue(self::page([], ['nextSyncToken' => 'sync-1']), self::page([], ['nextSyncToken' => 'sync-2']));

        // The engine empties a calendar only after two empty listings in a row, so the next sync lists again.
        $this->assertSame([], $this->pull()->state);
        // An incremental round without changes keeps its new token.
        $this->assertSame(['sync_token' => 'sync-2'], $this->pull(['sync_token' => 'sync-1'])->state);
    }

    public function test_rate_limits_end_the_sync_with_googles_reason(): void
    {
        foreach (range(1, 4) as $attempt) {
            $this->http->queue(self::googleError(429, 'rateLimitExceeded', 'Rate Limit Exceeded'));
        }

        $this->sync();

        $this->assertSame(['Google is limiting requests. The next sync tries again. (HTTP 429)', 1], [$this->calendar->last_error, $this->calendar->sync_failures]);
        $this->assertCount(3, $this->sleeps);
        $this->assertSame(CalendarConnection::STATUS_OK, $this->calendar->calendarConnection->fresh()->status);
    }

    public function test_a_new_event_is_inserted_under_its_uid(): void
    {
        $vcal = EventIcs::build([
            'uid' => 'new-event@freescout.example.com', 'title' => 'Onboarding call', 'all_day' => false, 'location' => 'Online',
            'start' => new DateTimeImmutable('2026-10-20 09:00', new DateTimeZone(self::ZONE)), 'end' => new DateTimeImmutable('2026-10-20 09:45', new DateTimeZone(self::ZONE)),
            'rrule' => 'FREQ=WEEKLY;COUNT=3',
        ]);
        $this->http->queue(
            self::answer(200, ['kind' => 'calendar#event', 'etag' => '"3561234567900000"', 'id' => 'n3w3v3nt0001', 'iCalUID' => 'new-event@freescout.example.com', 'status' => 'confirmed']),
            self::answer(200, ['kind' => 'calendar#event', 'etag' => '"3561234567900001"', 'id' => 'n3w3v3nt0002', 'iCalUID' => 'n3w3v3nt0002@google.com', 'status' => 'confirmed'])
        );

        $ref = $this->provider->create($this->calendar, $vcal);

        $this->assertSame(['n3w3v3nt0001', null, '"3561234567900000"', null], [$ref->remoteId, $ref->href, $ref->etag, $ref->uid]);
        $this->assertSame(['POST ' . self::EVENTS], $this->requests());
        $this->assertSame([
            'iCalUID'    => 'new-event@freescout.example.com',
            'summary'    => 'Onboarding call',
            'location'   => 'Online',
            'start'      => ['dateTime' => '2026-10-20T09:00:00+02:00', 'timeZone' => self::ZONE],
            'end'        => ['dateTime' => '2026-10-20T09:45:00+02:00', 'timeZone' => self::ZONE],
            'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3'],
            'visibility' => 'default',
        ], self::body($this->sent(0)));
        $this->assertArrayNotHasKey('If-Match', $this->sent(0)['headers']);

        // When Google files the event under a UID of its own, FreeScout takes that one.
        $this->assertSame('n3w3v3nt0002@google.com', $this->provider->create($this->calendar, $vcal)->uid);
    }

    public function test_a_uid_google_keeps_for_a_deleted_event_is_inserted_again_under_a_new_uid(): void
    {
        // Moved out of Google and back: Google keeps the deleted event (and its iCalUID) for 30 days and refuses the UID.
        $vcal = EventIcs::build([
            'uid' => 'k4e4d0e@google.com', 'title' => 'Team lunch', 'all_day' => false,
            'start' => new DateTimeImmutable('2026-10-22 08:30', new DateTimeZone(self::ZONE)), 'end' => new DateTimeImmutable('2026-10-22 15:00', new DateTimeZone(self::ZONE)),
        ]);
        $this->http->queue(
            self::googleError(409, 'duplicate', 'The requested identifier already exists.'),
            self::answer(200, ['kind' => 'calendar#event', 'etag' => '"3561234567900002"', 'id' => 'n3w3v3nt0003', 'iCalUID' => 'n3w3v3nt0003@google.com', 'status' => 'confirmed'])
        );

        $ref = $this->provider->create($this->calendar, $vcal);

        $this->assertSame(['n3w3v3nt0003', '"3561234567900002"', 'n3w3v3nt0003@google.com'], [$ref->remoteId, $ref->etag, $ref->uid]);
        $this->assertSame(['POST ' . self::EVENTS, 'POST ' . self::EVENTS], $this->requests());
        $this->assertSame('k4e4d0e@google.com', self::body($this->sent(0))['iCalUID']);
        $this->assertArrayNotHasKey('iCalUID', self::body($this->sent(1)));
        $this->assertSame('Team lunch', self::body($this->sent(1))['summary']);
    }

    public function test_a_second_refusal_of_the_new_uid_is_still_an_error(): void
    {
        $vcal = EventIcs::build([
            'uid' => 'twice@google.com', 'title' => 'Twice', 'all_day' => false,
            'start' => new DateTimeImmutable('2026-10-22 08:30', new DateTimeZone(self::ZONE)), 'end' => new DateTimeImmutable('2026-10-22 09:00', new DateTimeZone(self::ZONE)),
        ]);
        $this->http->queue(
            self::googleError(409, 'duplicate', 'The requested identifier already exists.'),
            self::googleError(409, 'duplicate', 'The requested identifier already exists.')
        );

        try {
            $this->provider->create($this->calendar, $vcal);
            $this->fail('A second refusal must not pass');
        } catch (ProviderUnavailable $e) {
            $this->assertSame(409, $e->httpStatus());
        }
        $this->assertCount(2, $this->requests());
    }

    public function test_a_series_change_reads_the_event_and_writes_it_back_under_its_etag(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        EventIcs::master($vcal, $event->uid)->SUMMARY = 'Weekly planning with Example Corp';
        $current = self::fixture('recurring', [
            'attendees'      => [['email' => 'colleague@example.com', 'responseStatus' => 'accepted']],
            'conferenceData' => ['conferenceId' => 'abc-defg-hij', 'conferenceSolution' => ['key' => ['type' => 'hangoutsMeet']]],
            'reminders'      => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 15]]],
            'colorId'        => '5',
            'location'       => 'Meeting room 4',
        ]);
        unset(EventIcs::master($vcal, $event->uid)->LOCATION);
        $this->http->queue(self::answer(200, $current), self::answer(200, ['etag' => '"3561234567901000"'] + $current));

        $ref = $this->provider->update($this->calendar, $event, $vcal);

        $this->assertSame([self::SERIES, null, '"3561234567901000"', null], [$ref->remoteId, $ref->href, $ref->etag, $ref->uid]);
        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES, 'PUT ' . self::EVENTS . '/' . self::SERIES], $this->requests());
        $put = $this->sent(2);
        $this->assertSame(['"3561234567892000"'], $put['headers']['If-Match']);
        $this->assertSame(['conferenceDataVersion' => '1', 'supportsAttachments' => 'true'], self::query($put));
        $body = self::body($put);
        // A PUT replaces the whole event: what FreeScout doesn't edit comes from the event as Google has it.
        foreach (['attendees', 'conferenceData', 'reminders', 'colorId', 'iCalUID', 'organizer'] as $kept) {
            $this->assertSame($current[$kept], $body[$kept], $kept);
        }
        $this->assertSame(['Weekly planning with Example Corp', 'Plan the support week.', 'confirmed', 'default'], [$body['summary'], $body['description'], $body['status'], $body['visibility']]);
        $this->assertSame($current['recurrence'], $body['recurrence']);
        $this->assertSame([$current['start'], $current['end']], [$body['start'], $body['end']]);
        // A field FreeScout's event doesn't have is left out of the PUT, which clears it in Google.
        $this->assertArrayNotHasKey('location', $body);
    }

    public function test_googles_rich_description_stays_until_freescout_changes_its_text(): void
    {
        $html = 'Plan the <b>support</b> week.<br><br>[FreeScout]<br>Kind: Visit';
        $this->http->queue(self::page([self::fixture('recurring', ['description' => $html])], ['nextSyncToken' => 'sync-1']));
        $this->sync();
        $event = $this->stored(self::SERIES);
        $current = self::fixture('recurring', ['description' => $html]);
        $vcal = Reader::read($event->ics);
        $master = EventIcs::master($vcal, $event->uid);
        $this->assertSame('Plan the support week.', (string) $master->DESCRIPTION);
        // What EventService sends: the stored text with the readable block.
        $master->DESCRIPTION = "Plan the support week.\n\n[FreeScout]\nKind: Visit";
        $master->SUMMARY = 'Weekly planning with Example Corp';
        $this->http->queue(self::answer(200, $current), self::answer(200, ['etag' => '"3561234567901000"'] + $current));

        $this->provider->update($this->calendar, $event, $vcal);

        $this->assertSame(['Weekly planning with Example Corp', $html], [self::body($this->sent(2))['summary'], self::body($this->sent(2))['description']]);

        // A new text is written as plain text.
        $master->DESCRIPTION = "Plan the week.\n\n[FreeScout]\nKind: Visit";
        $event->etag = '"3561234567901000"';
        $this->http->queue(self::answer(200, ['etag' => '"3561234567901000"'] + $current), self::answer(200, ['etag' => '"3561234567902000"'] + $current));
        $this->provider->update($this->calendar, $event, $vcal);
        $this->assertSame("Plan the week.\n\n[FreeScout]\nKind: Visit", self::body($this->sent(4))['description']);
    }

    public function test_changes_made_in_google_first_are_conflicts(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        $cases = [
            // Another etag than the stored one: changed since the last sync, so nothing is written.
            'changed'   => [self::answer(200, self::fixture('recurring', ['etag' => '"3561234567909999"']))],
            // Changed between the read and the write.
            'race'      => [self::answer(200, self::fixture('recurring')), self::googleError(412, 'conditionNotMet', 'Precondition Failed')],
            'deleted'   => [self::googleError(404, 'notFound', 'Not Found')],
            'cancelled' => [self::answer(200, ['kind' => 'calendar#event', 'id' => self::SERIES, 'status' => 'cancelled'])],
        ];
        foreach ($cases as $case => $answers) {
            $this->http->queue(...$answers);

            $e = $this->failure(function () use ($event, $vcal) {
                $this->provider->update($this->calendar, $event, $vcal);
            });

            $this->assertInstanceOf(ProviderConflict::class, $e, $case);
        }
        $this->http->queue(self::googleError(412, 'conditionNotMet', 'Precondition Failed'));
        $this->assertInstanceOf(ProviderConflict::class, $this->failure(function () use ($event) {
            $this->provider->delete($this->calendar, $event);
        }));
        $this->assertSame(0, $this->http->pending());
        $this->assertSame(['PUT ' . self::EVENTS . '/' . self::SERIES], array_values(array_filter($this->requests(), function (string $request) {
            return strncmp($request, 'PUT', 3) === 0;
        })));
    }

    public function test_an_occurrence_google_changed_since_the_last_sync_is_a_conflict(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        $tz = new DateTimeZone(self::ZONE);
        EventIcs::addOverride($vcal, EventIcs::master($vcal, $event->uid), '20261102T090000Z', $tz)->LOCATION = 'Meeting room 4';
        // Someone moved this occurrence to 15:00 in Google after FreeScout's last sync.
        $this->http->queue(self::answer(200, self::occurrence('2026-11-02', '+01:00', [], '15')));

        $e = $this->failure(function () use ($event, $vcal) {
            $this->provider->update($this->calendar, $event, $vcal, '20261102T090000Z');
        });

        $this->assertInstanceOf(ProviderConflict::class, $e);
        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES . '_20261102T090000Z'], $this->requests());
    }

    public function test_an_edited_occurrence_is_written_to_its_instance(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        $tz = new DateTimeZone(self::ZONE);
        $override = EventIcs::addOverride($vcal, EventIcs::master($vcal, $event->uid), '20261102T090000Z', $tz);
        EventIcs::apply($override, ['title' => 'Planning with Example Corp', 'all_day' => false, 'start' => new DateTimeImmutable('2026-11-02 14:00', $tz), 'end' => new DateTimeImmutable('2026-11-02 15:00', $tz)]);
        $instance = self::occurrence('2026-11-02', '+01:00');
        $this->http->queue(self::answer(200, $instance), self::answer(200, ['etag' => '"instance-new"'] + $instance));

        $ref = $this->provider->update($this->calendar, $event, $vcal, '20261102T090000Z');

        $this->assertSame([self::SERIES, '"3561234567892000"'], [$ref->remoteId, $ref->etag]);
        $path = self::EVENTS . '/' . self::SERIES . '_20261102T090000Z';
        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . $path, 'PUT ' . $path], $this->requests());
        $put = $this->sent(2);
        $this->assertSame(['"instance-20261102T090000Z"'], $put['headers']['If-Match']);
        $body = self::body($put);
        $this->assertSame(['Planning with Example Corp', ['dateTime' => '2026-11-02T14:00:00+01:00', 'timeZone' => self::ZONE], ['dateTime' => '2026-11-02T15:00:00+01:00', 'timeZone' => self::ZONE]], [$body['summary'], $body['start'], $body['end']]);
        $this->assertSame([self::SERIES, $instance['originalStartTime'], $instance['attendees']], [$body['recurringEventId'], $body['originalStartTime'], $body['attendees']]);
        $this->assertArrayNotHasKey('recurrence', $body);
    }

    public function test_a_deleted_occurrence_cancels_its_instance(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        EventIcs::addExdate(EventIcs::master($vcal, $event->uid), '20261109T090000Z', new DateTimeZone(self::ZONE));
        $this->http->queue(new Response(204), self::googleError(410, 'deleted', 'Resource has been deleted'));

        $ref = $this->provider->update($this->calendar, $event, $vcal, '20261109T090000Z');
        // Cancelled already: nothing left to do.
        $this->provider->update($this->calendar, $event, $vcal, '20261109T090000Z');

        $this->assertSame([self::SERIES, '"3561234567892000"'], [$ref->remoteId, $ref->etag]);
        $path = 'DELETE ' . self::EVENTS . '/' . self::SERIES . '_20261109T090000Z';
        $this->assertSame(['GET ' . self::EVENTS, $path, $path], $this->requests());
        $this->assertArrayNotHasKey('If-Match', $this->sent(1)['headers']);
        // A key that is neither an override nor an EXDATE is a mistake of the caller, never a cancellation.
        $this->expectException(\LogicException::class);
        $this->provider->update($this->calendar, $event, $vcal, '20261116T090000Z');
    }

    public function test_an_instance_google_names_differently_is_found_by_its_original_start(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        $tz = new DateTimeZone(self::ZONE);
        $master = EventIcs::master($vcal, $event->uid);
        EventIcs::addOverride($vcal, $master, '20261102T090000Z', $tz)->SUMMARY = 'Planning with Example Corp';
        EventIcs::addExdate($master, '20261109T090000Z', $tz);
        $odd = self::occurrence('2026-11-02', '+01:00', ['id' => 'odd-instance-1']);
        $this->http->queue(
            self::googleError(404, 'notFound', 'Not Found'),
            self::answer(200, ['kind' => 'calendar#events', 'items' => [self::occurrence('2026-10-26', '+01:00', ['id' => 'odd-instance-0']), $odd]]),
            self::answer(200, $odd),
            self::googleError(404, 'notFound', 'Not Found'),
            self::answer(200, ['kind' => 'calendar#events', 'items' => [self::occurrence('2026-11-09', '+01:00', ['id' => 'odd-instance-2'])]]),
            new Response(204),
            self::googleError(404, 'notFound', 'Not Found'),
            self::answer(200, ['kind' => 'calendar#events', 'items' => []])
        );

        $this->provider->update($this->calendar, $event, $vcal, '20261102T090000Z');
        $this->provider->update($this->calendar, $event, $vcal, '20261109T090000Z');

        $series = self::EVENTS . '/' . self::SERIES;
        $this->assertSame([
            'GET ' . self::EVENTS,
            'GET ' . $series . '_20261102T090000Z', 'GET ' . $series . '/instances', 'PUT ' . self::EVENTS . '/odd-instance-1',
            'DELETE ' . $series . '_20261109T090000Z', 'GET ' . $series . '/instances', 'DELETE ' . self::EVENTS . '/odd-instance-2',
        ], $this->requests());
        $this->assertSame(['originalStart' => '2026-11-02T09:00:00Z', 'showDeleted' => 'true'], self::query($this->sent(2)));
        $this->assertSame(['"instance-20261109T090000Z"'], $this->sent(6)['headers']['If-Match']);
        // An occurrence Google doesn't have any more was changed elsewhere.
        $e = $this->failure(function () use ($event, $vcal) {
            $this->provider->update($this->calendar, $event, $vcal, '20261102T090000Z');
        });
        $this->assertInstanceOf(ProviderConflict::class, $e);
    }

    public function test_deleting_an_event_sends_its_etag_and_accepts_one_that_is_gone(): void
    {
        $this->http->queue(self::page([self::fixture('single-timed')], ['nextSyncToken' => 'sync-1']));
        $this->sync();
        $event = $this->stored(self::SINGLE);
        $this->http->queue(new Response(204), self::googleError(404, 'notFound', 'Not Found'), self::googleError(410, 'deleted', 'Resource has been deleted'));

        foreach (range(1, 3) as $attempt) {
            $this->provider->delete($this->calendar, $event);
        }

        $this->assertSame(array_fill(1, 3, 'DELETE ' . self::EVENTS . '/' . self::SINGLE), array_slice($this->requests(), 1, 3, true));
        $this->assertSame(['"3561234567890000"'], $this->sent(1)['headers']['If-Match']);
    }

    public function test_a_moved_series_brings_its_overrides_and_is_removed_again_when_one_fails(): void
    {
        $tz = new DateTimeZone(self::ZONE);
        $vcal = EventIcs::build([
            'uid' => 'moved@freescout.example.com', 'title' => 'Weekly planning', 'all_day' => false,
            'start' => new DateTimeImmutable('2026-10-05 10:00', $tz), 'end' => new DateTimeImmutable('2026-10-05 11:00', $tz), 'rrule' => 'FREQ=WEEKLY;COUNT=6',
        ]);
        EventIcs::addOverride($vcal, EventIcs::master($vcal, 'moved@freescout.example.com'), '20261026T090000Z', $tz)->SUMMARY = 'Weekly planning (moved)';
        $created = ['kind' => 'calendar#event', 'etag' => '"3561234567910000"', 'id' => 'm0v3d', 'iCalUID' => 'moved@freescout.example.com', 'status' => 'confirmed'];
        $instance = self::occurrence('2026-10-26', '+01:00', ['id' => 'm0v3d_20261026T090000Z', 'recurringEventId' => 'm0v3d']);
        $this->http->queue(self::answer(200, $created), self::answer(200, $instance), self::answer(200, $instance));

        $ref = $this->provider->create($this->calendar, $vcal);

        $this->assertSame(['m0v3d', '"3561234567910000"'], [$ref->remoteId, $ref->etag]);
        $this->assertSame(['POST ' . self::EVENTS, 'GET ' . self::EVENTS . '/m0v3d_20261026T090000Z', 'PUT ' . self::EVENTS . '/m0v3d_20261026T090000Z'], $this->requests());
        $this->assertSame('Weekly planning (moved)', self::body($this->sent(2))['summary']);

        // Half a series must not stay behind at Google.
        $this->http->queue(self::answer(200, $created), self::answer(200, $instance), self::answer(500, ['error' => ['code' => 500, 'message' => 'Backend Error']]), new Response(204));
        $e = $this->failure(function () use ($vcal) {
            $this->provider->create($this->calendar, $vcal);
        });
        $this->assertSame([ProviderUnavailable::class, 500], [get_class($e), $e->httpStatus()]);
        $this->assertSame('DELETE ' . self::EVENTS . '/m0v3d', $this->requests()[6]);
        $this->assertSame(0, $this->http->pending());
    }

    public function test_a_meeting_gets_its_description_without_freescouts_block(): void
    {
        $this->calendar->fields = [['id' => 3, 'name' => 'Customer', 'type' => 'text', 'required' => false, 'options' => []]];
        $this->calendar->save();
        // Google shows the organizer's description to every guest, so FreeScout's fields and ticket link stay out of it.
        $guests = ['attendees' => [
            ['email' => 'agent@example.com', 'organizer' => true, 'self' => true, 'responseStatus' => 'accepted'],
            ['email' => 'customer@example.com', 'responseStatus' => 'needsAction'],
        ]];
        $meeting = self::fixture('single-timed', $guests);
        $this->http->queue(self::page([$meeting], ['nextSyncToken' => 'sync-1']));
        $this->sync();
        $event = $this->stored(self::SINGLE);
        $event->field_values = ['3' => 'Example Corp'];
        $event->save();
        $user = $this->makeUser();
        $this->http->queue(self::answer(200, $meeting), self::answer(200, ['etag' => '"3561234567890001"'] + $meeting));

        app(EventService::class)->update($event->fresh(), $user, ['title' => 'Intake call with Example Corp (moved)']);

        $this->assertSame('Discuss the support plan.', self::body($this->sent(2))['description']);
        // The export keeps the block.
        $this->assertStringContainsString("Discuss the support plan.\n\n[FreeScout]\nCustomer: Example Corp", Reader::read(app(ExportService::class)->render($this->calendar, false))->VEVENT->DESCRIPTION->getValue());

        // Without guests (the account alone counts as none) the description carries the block.
        $alone = ['etag' => '"3561234567890001"', 'attendees' => [$guests['attendees'][0]]] + self::fixture('single-timed');
        $this->http->queue(self::answer(200, $alone), self::answer(200, ['etag' => '"3561234567890002"'] + $alone));
        app(EventService::class)->update($event->fresh(), $user, ['title' => 'Intake call with Example Corp']);
        $this->assertSame("Discuss the support plan.\n\n[FreeScout]\nCustomer: Example Corp", self::body($this->sent(4))['description']);
    }

    public function test_an_occurrence_of_a_meeting_gets_its_description_without_freescouts_block(): void
    {
        $this->calendar->fields = [['id' => 3, 'name' => 'Customer', 'type' => 'text', 'required' => false, 'options' => []]];
        $this->calendar->save();
        $event = $this->storeSeries();
        $event->field_values = ['3' => 'Example Corp'];
        $event->save();
        // The instance has a guest (colleague@example.com).
        $edited = self::occurrence('2026-11-02', '+01:00');
        $this->http->queue(self::answer(200, $edited), self::answer(200, ['etag' => '"instance-new"'] + $edited));

        app(EventService::class)->update($event->fresh(), $this->makeUser(), ['scope' => 'occurrence', 'recurrence_id' => '20261102T090000Z', 'title' => 'Planning with Example Corp']);

        $this->assertSame('Plan the support week.', self::body($this->sent(2))['description']);
    }

    public function test_an_agents_occurrence_edit_and_delete_reach_their_instances(): void
    {
        $event = $this->storeSeries();
        $user = $this->makeUser();
        $service = app(EventService::class);
        $edited = self::occurrence('2026-11-02', '+01:00');
        $this->http->queue(self::answer(200, $edited), self::answer(200, ['etag' => '"instance-new"'] + $edited), new Response(204));

        $service->update($event, $user, ['scope' => 'occurrence', 'recurrence_id' => '20261102T090000Z', 'title' => 'Planning with Example Corp', 'start' => '2026-11-02T14:00', 'end' => '2026-11-02T15:00']);
        $service->delete($event->fresh(), EventService::SCOPE_OCCURRENCE, '20261109T090000Z');

        $series = self::EVENTS . '/' . self::SERIES;
        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . $series . '_20261102T090000Z', 'PUT ' . $series . '_20261102T090000Z', 'DELETE ' . $series . '_20261109T090000Z'], $this->requests());
        $this->assertSame(['dateTime' => '2026-11-02T14:00:00+01:00', 'timeZone' => self::ZONE], self::body($this->sent(2))['start']);
        $stored = $event->fresh();
        $this->assertSame(['exdates' => ['20261019T080000Z', '20261109T090000Z'], 'overrides' => ['20261102T090000Z']], self::exceptions($stored->ics));
        $this->assertSame('"3561234567892000"', $stored->etag);
    }

    public function test_a_forgotten_sync_token_lists_again_and_sweeps_what_google_no_longer_has(): void
    {
        $this->http->queue(self::page([self::fixture('single-timed'), self::fixture('all-day')], ['nextSyncToken' => 'sync-1']));
        $this->sync();
        $generation = $this->calendar->sync_state['_generation'];
        // The sweep spares events saved after the listing started; these were saved before it.
        DB::table('calendar_events')->where('calendar_id', $this->calendar->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        $this->http->queue(
            self::googleError(410, 'fullSyncRequired', 'Sync token is no longer valid, a full sync is required.'),
            self::page([self::fixture('single-timed')], ['nextSyncToken' => 'sync-2'])
        );

        $this->sync();

        $this->assertSame([$generation + 1, 'sync-2', null], [$this->calendar->sync_state['_generation'], $this->calendar->sync_state['sync_token'], $this->calendar->last_error]);
        $this->assertNotNull($this->stored(self::SINGLE));
        $this->assertNull($this->stored('0a2b4c6d8e0f1g3h5i7j9k1l3m'));
    }

    public function test_a_page_token_google_refuses_starts_the_listing_over(): void
    {
        $this->http->queue(
            self::googleError(400, 'invalid', 'Invalid page token value.'),
            self::page([self::fixture('single-timed')], ['nextSyncToken' => 'sync-2'])
        );

        $result = $this->pull(['sync_token' => 'sync-1', 'page_token' => 'page-old']);

        $this->assertSame(['syncToken' => 'sync-1'], array_intersect_key(self::query($this->sent(1)), ['syncToken' => 1, 'pageToken' => 1]));
        $this->assertSame([false, ['sync_token' => 'sync-2']], [$result->fullListing, $result->state]);
        // Without a page token a 400 is an error.
        $this->http->queue(self::googleError(400, 'invalid', 'Invalid value.'));
        $this->assertSame(400, $this->failure(function () {
            $this->pull(['sync_token' => 'sync-1']);
        })->httpStatus());
    }

    public function test_invitations_and_special_event_types_are_read_only(): void
    {
        $this->http->queue(self::page([
            self::fixture('attendees'),
            self::fixture('attendees', ['id' => 'guests-may-modify', 'iCalUID' => 'guests-may-modify@google.com', 'guestsCanModify' => true]),
            self::fixture('all-day', ['id' => 'out-of-office', 'iCalUID' => 'out-of-office@google.com', 'eventType' => 'outOfOffice']),
            self::fixture('single-timed'),
        ], ['nextSyncToken' => 'sync-1']));

        $readOnly = [];
        foreach ($this->pull()->upserts as $remote) {
            $readOnly[$remote->remoteId] = $remote->readOnly;
        }

        $this->assertSame(['3a5c7e9g1i3k5m7o9q1s3u5v7b' => true, 'guests-may-modify' => false, 'out-of-office' => true, self::SINGLE => false], $readOnly);
    }

    public function test_an_exception_for_an_occurrence_stored_as_cancelled_reads_the_series_again(): void
    {
        $this->storeSeries([self::fixture('instance-cancelled')]);
        // 12 October was cancelled, and is back in Google as an edited occurrence: the merge can't remove the EXDATE.
        $restored = self::occurrence('2026-10-12', '+02:00', ['summary' => 'Weekly planning (extra)']);
        $this->http->queue(
            self::page([$restored], ['nextSyncToken' => 'sync-2']),
            self::answer(200, self::fixture('recurring')),
            self::seriesInstances([], ['2026-10-12' => $restored])
        );

        $result = $this->pull(['sync_token' => 'sync-1']);

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES, 'GET ' . self::EVENTS . '/' . self::SERIES . '/instances'], $this->requests());
        $this->assertFalse($result->upserts[0]->partial);
        $this->assertSame(['exdates' => ['20261019T080000Z'], 'overrides' => ['20261012T080000Z']], self::exceptions($result->upserts[0]->ics));
    }

    public function test_an_event_without_a_google_id_is_inserted_on_its_first_change(): void
    {
        $vcal = EventIcs::build(['uid' => 'local@freescout.example.com', 'title' => 'Not at Google yet', 'all_day' => true, 'start' => '2026-10-20', 'end' => '2026-10-21']);
        $event = new CalendarEvent(['calendar_id' => $this->calendar->id]);
        $event->uid = 'local@freescout.example.com';
        $this->http->queue(self::answer(200, ['kind' => 'calendar#event', 'etag' => '"1"', 'id' => 'n0w4tg00gl3', 'iCalUID' => 'local@freescout.example.com']));

        $ref = $this->provider->update($this->calendar, $event, $vcal);

        $this->assertSame(['POST ' . self::EVENTS], $this->requests());
        $this->assertSame(['n0w4tg00gl3', '"1"'], [$ref->remoteId, $ref->etag]);
        $this->assertSame([['date' => '2026-10-20'], ['date' => '2026-10-21']], [self::body($this->sent(0))['start'], self::body($this->sent(0))['end']]);
    }

    public function test_requests_an_agent_waits_for_only_wait_briefly_for_a_rate_limit(): void
    {
        $this->http->queue(self::page([self::fixture('single-timed')], ['nextSyncToken' => 'sync-1']));
        $this->sync();
        $limited = new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '5'], json_encode(['error' => ['errors' => [['domain' => 'usageLimits', 'reason' => 'rateLimitExceeded', 'message' => 'Rate Limit Exceeded']], 'code' => 429, 'message' => 'Rate Limit Exceeded']]));
        $this->http->queue($limited, $limited, self::page([], ['nextSyncToken' => 'sync-2']));

        // An agent's delete gives up at once; a sync waits.
        $e = $this->failure(function () {
            $this->provider->delete($this->calendar, $this->stored(self::SINGLE));
        });
        $this->pull(['sync_token' => 'sync-1']);

        $this->assertSame(['Google is limiting requests. The next sync tries again.', 429], [$e->userMessage(), $e->httpStatus()]);
        $this->assertSame([5.0], $this->sleeps);
    }

    public function test_the_sync_after_a_conflict_waits_only_briefly_and_never_continues_an_unfinished_listing(): void
    {
        $this->http->queue(self::page([self::fixture('single-timed')], ['nextSyncToken' => 'sync-1']));
        $this->sync();
        $limited = function () {
            return new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '5'], json_encode(['error' => ['errors' => [['domain' => 'usageLimits', 'reason' => 'rateLimitExceeded', 'message' => 'Rate Limit Exceeded']], 'code' => 429, 'message' => 'Rate Limit Exceeded']]));
        };
        $conflict = function () {
            try {
                app(EventService::class)->delete($this->stored(self::SINGLE), EventService::SCOPE_SERIES, null);
                $this->fail('The delete went through.');
            } catch (ProviderConflict $e) {
                return $e;
            }
        };

        // The agent waits for the sync after a conflict: it never waits out a rate limit as a sync of its own would.
        $this->http->queue(self::googleError(412, 'conditionNotMet', 'Precondition Failed'), $limited());
        $conflict();
        $this->assertSame([], $this->sleeps);
        $this->assertSame(0, $this->http->pending());
        $this->assertSame(['DELETE ' . self::EVENTS . '/' . self::SINGLE, 'GET ' . self::EVENTS], array_slice($this->requests(), 1, 2));

        // A listing that has not finished could take many rounds: the next run continues it instead.
        $this->calendar->update(['sync_state' => ['page_token' => 'page-2', '_generation' => 2, '_listing' => true, '_seen' => 250], 'next_sync_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $requests = count($this->http->history());
        $this->http->queue(self::googleError(412, 'conditionNotMet', 'Precondition Failed'));
        $conflict();
        $this->assertSame(['DELETE ' . self::EVENTS . '/' . self::SINGLE], array_slice($this->requests(), $requests));
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), (string) $this->calendar->fresh()->next_sync_at);
    }

    public function test_a_moved_series_leaves_out_an_edited_occurrence_google_has_no_instance_for(): void
    {
        $tz = new DateTimeZone(self::ZONE);
        $vcal = EventIcs::build([
            'uid' => 'moved@freescout.example.com', 'title' => 'Weekly planning', 'all_day' => false,
            'start' => new DateTimeImmutable('2026-10-05 10:00', $tz), 'end' => new DateTimeImmutable('2026-10-05 11:00', $tz), 'rrule' => 'FREQ=WEEKLY;COUNT=6',
        ]);
        // An override another app kept after the series moved: it is no occurrence of the rule any more.
        EventIcs::addOverride($vcal, EventIcs::master($vcal, 'moved@freescout.example.com'), '20261027T090000Z', $tz)->SUMMARY = 'Orphan';
        $this->http->queue(
            self::answer(200, ['kind' => 'calendar#event', 'etag' => '"3561234567910000"', 'id' => 'm0v3d', 'iCalUID' => 'moved@freescout.example.com', 'status' => 'confirmed']),
            self::googleError(404, 'notFound', 'Not Found'),
            self::answer(200, ['kind' => 'calendar#events', 'items' => []])
        );

        $ref = $this->provider->create($this->calendar, $vcal);

        $this->assertSame('m0v3d', $ref->remoteId);
        $this->assertSame(['POST ' . self::EVENTS, 'GET ' . self::EVENTS . '/m0v3d_20261027T090000Z', 'GET ' . self::EVENTS . '/m0v3d/instances'], $this->requests());
    }

    public function test_a_full_listing_reads_no_unchanged_series_again(): void
    {
        $this->storeSeries([self::fixture('instance-modified'), self::fixture('instance-cancelled')]);
        $this->http->queue(
            self::page([self::fixture('recurring'), self::fixture('instance-modified'), self::fixture('instance-cancelled')], ['nextPageToken' => 'page-2']),
            self::page([self::fixture('recurring', ['id' => 'other-series', 'iCalUID' => 'other-series@google.com'])], ['nextSyncToken' => 'sync-2'])
        );

        // A relist (after a failed move, or a 410) lists every series again: one that didn't change is not read again,
        // its exceptions on the page are merged.
        app(SyncService::class)->relist($this->calendar);
        $this->calendar->refresh();
        $this->sync();

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS, 'GET ' . self::EVENTS], $this->requests());
        $this->assertSame(['exdates' => ['20261012T080000Z', '20261019T080000Z'], 'overrides' => ['20261026T090000Z']], self::exceptions($this->stored(self::SERIES)->ics));
        $this->assertSame([2, 'sync-2'], [CalendarEvent::where('calendar_id', $this->calendar->id)->count(), $this->calendar->sync_state['sync_token']]);
        // Without exceptions on the page, the series only counts as seen, so the listing doesn't sweep it.
        $this->http->queue(self::page([self::fixture('recurring')], ['nextSyncToken' => 'sync-3']));
        $last = $this->pull();
        $this->assertSame([[], [self::SERIES]], [$last->upserts, $last->unchanged]);
    }

    public function test_exceptions_of_a_series_that_is_not_stored_read_it_whole_in_an_incremental_round(): void
    {
        $this->http->queue(
            self::page([self::fixture('instance-modified')], ['nextSyncToken' => 'sync-2']),
            self::answer(200, self::fixture('recurring')),
            self::seriesInstances([], ['2026-10-12' => self::fixture('instance-cancelled'), '2026-10-26' => self::fixture('instance-modified')])
        );

        $result = $this->pull(['sync_token' => 'sync-1']);

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES, 'GET ' . self::EVENTS . '/' . self::SERIES . '/instances'], $this->requests());
        $this->assertSame(['exdates' => ['20261012T080000Z', '20261019T080000Z'], 'overrides' => ['20261026T090000Z']], self::exceptions($result->upserts[0]->ics));
    }

    public function test_an_exception_of_another_form_than_the_stored_series_reads_it_again(): void
    {
        $this->storeSeries();
        $this->http->queue(
            self::page([['kind' => 'calendar#event', 'id' => self::SERIES . '_20261012', 'status' => 'cancelled', 'recurringEventId' => self::SERIES, 'originalStartTime' => ['date' => '2026-10-12']]], ['nextSyncToken' => 'sync-2']),
            self::answer(200, self::fixture('recurring')),
            self::seriesInstances()
        );

        $result = $this->pull(['sync_token' => 'sync-1']);

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES, 'GET ' . self::EVENTS . '/' . self::SERIES . '/instances'], $this->requests());
        $this->assertSame([false, ['exdates' => ['20261019T080000Z'], 'overrides' => []]], [$result->upserts[0]->partial, self::exceptions($result->upserts[0]->ics)]);
    }

    public function test_a_series_reads_its_instances_page_by_page_up_to_a_limit(): void
    {
        $logs = LogRecorder::install();
        $this->storeSeries();
        $renamed = self::fixture('recurring', ['etag' => '"3561234567899300"', 'summary' => 'Weekly planning (renamed)', 'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO']]);
        $paged = function (array $items, ?string $next) {
            return self::answer(200, ['kind' => 'calendar#events', 'items' => $items] + ($next !== null ? ['nextPageToken' => $next] : []));
        };
        $renamedOccurrence = function (string $date, string $offset, array $changes = []) {
            return self::occurrence($date, $offset, ['summary' => 'Weekly planning (renamed)'] + $changes);
        };
        // A cancelled occurrence listed with the master but past the window stays; one the instances show as regular goes.
        $farAway = ['kind' => 'calendar#event', 'id' => self::SERIES . '_20281106T090000Z', 'status' => 'cancelled', 'recurringEventId' => self::SERIES,
            'originalStartTime' => ['dateTime' => '2028-11-06T10:00:00+01:00', 'timeZone' => self::ZONE]];
        $this->http->queue(
            self::page([$renamed, $farAway, self::fixture('instance-modified')], ['nextSyncToken' => 'sync-2']),
            $paged([$renamedOccurrence('2026-10-05', '+02:00'), $renamedOccurrence('2026-10-12', '+02:00', ['status' => 'cancelled'])], 'instances-2'),
            $paged([$renamedOccurrence('2026-10-26', '+01:00')], 'instances-3'),
            $paged([], 'instances-4'),
            $paged([$renamedOccurrence('2026-11-02', '+01:00', ['location' => 'Meeting room 4'])], 'instances-5')
        );

        $result = $this->pull(['sync_token' => 'sync-1']);

        $this->assertSame(4, count(array_filter($this->requests(), function (string $request) {
            return substr($request, -10) === '/instances';
        })));
        $this->assertSame('instances-2', self::query($this->sent(3))['pageToken']);
        $ics = $result->upserts[0]->ics;
        $this->assertSame(['exdates' => ['20261012T080000Z', '20281106T090000Z'], 'overrides' => ['20261102T090000Z']], self::exceptions($ics));
        // In occurrence order, so a series read again unchanged maps to the same text.
        $this->assertLessThan(strpos($ics, ':20281106T1'), strpos($ics, ':20261012T1'));
        $this->assertSame(['A Google series has more occurrences than FreeScout reads; later exceptions are left out'], array_column($logs->records('warning'), 'message'));
    }

    public function test_a_series_whose_instances_google_refuses_keeps_its_stored_copy(): void
    {
        $this->storeSeries([self::fixture('instance-modified')]);
        $this->http->queue(
            self::page([self::fixture('recurring', ['etag' => '"3561234567899400"', 'summary' => 'Renamed']), self::fixture('single-timed')], ['nextSyncToken' => 'sync-2']),
            self::googleError(403, 'forbidden', 'Forbidden')
        );

        $this->sync();

        $series = $this->stored(self::SERIES);
        $this->assertSame(['Weekly planning', '"3561234567892000"'], [$series->summary, $series->etag]);
        $this->assertSame('Intake call with Example Corp', $this->stored(self::SINGLE)->summary);
        $this->assertSame([null, 'sync-2'], [$this->calendar->last_error, $this->calendar->sync_state['sync_token']]);
    }

    public function test_a_page_token_google_refuses_starts_a_full_listing_over(): void
    {
        $this->http->queue(self::googleError(400, 'invalid', 'Invalid page token value.'), self::page([], ['nextPageToken' => 'page-2']));

        $result = $this->pull(['page_token' => 'page-old', 'listed' => 3]);

        $this->assertSame([], array_intersect_key(self::query($this->sent(1)), ['syncToken' => 1, 'pageToken' => 1]));
        $this->assertSame([true, ['page_token' => 'page-2', 'listed' => 0]], [$result->fullListing, $result->state]);
    }

    public function test_a_calendar_list_that_repeats_its_page_token_ends(): void
    {
        $page = self::answer(200, ['kind' => 'calendar#calendarList', 'nextPageToken' => 'same', 'items' => [['id' => 'agent@example.com', 'summary' => 'agent@example.com', 'accessRole' => 'owner']]]);
        $this->http->queue($page, $page, $page);

        $this->assertCount(2, $this->provider->listCalendars($this->calendar->calendarConnection));
        $this->assertSame(1, $this->http->pending());
    }

    public function test_changed_occurrences_of_an_invitation_stay_read_only(): void
    {
        $this->storeSeries();
        $invited = self::fixture('instance-modified', ['organizer' => ['email' => 'manager@example.com']]);
        $this->http->queue(self::page([$invited], ['nextSyncToken' => 'sync-2']));

        $this->assertTrue($this->pull(['sync_token' => 'sync-1'])->upserts[0]->readOnly);
    }

    public function test_a_move_that_fails_in_another_way_removes_the_inserted_series_too(): void
    {
        $tz = new DateTimeZone(self::ZONE);
        $vcal = EventIcs::build([
            'uid' => 'moved@freescout.example.com', 'title' => 'Weekly planning', 'all_day' => false,
            'start' => new DateTimeImmutable('2026-10-05 10:00', $tz), 'end' => new DateTimeImmutable('2026-10-05 11:00', $tz), 'rrule' => 'FREQ=WEEKLY;COUNT=6',
        ]);
        $broken = EventIcs::addOverride($vcal, EventIcs::master($vcal, 'moved@freescout.example.com'), '20261026T090000Z', $tz);
        unset($broken->DTSTART, $broken->DTEND);
        $this->http->queue(
            self::answer(200, ['kind' => 'calendar#event', 'etag' => '"1"', 'id' => 'm0v3d', 'iCalUID' => 'moved@freescout.example.com']),
            self::answer(200, self::occurrence('2026-10-26', '+01:00', ['id' => 'm0v3d_20261026T090000Z'])),
            new Response(204)
        );

        try {
            $this->provider->create($this->calendar, $vcal);
            $this->fail('A broken override was written');
        } catch (\Error $e) {
            $this->assertStringContainsString('hasTime', $e->getMessage());
        }

        $this->assertSame(['POST ' . self::EVENTS, 'GET ' . self::EVENTS . '/m0v3d_20261026T090000Z', 'DELETE ' . self::EVENTS . '/m0v3d'], $this->requests());
    }

    public function test_a_rate_limit_on_reading_a_series_fails_the_round_so_the_change_is_not_lost(): void
    {
        $this->storeSeries();
        $this->http->queue(self::page([self::fixture('recurring', ['etag' => '"3561234567899500"', 'summary' => 'Renamed'])], ['nextSyncToken' => 'sync-2']));
        foreach (range(1, 4) as $attempt) {
            $this->http->queue(self::googleError(403, 'rateLimitExceeded', 'Rate Limit Exceeded'));
        }

        $this->sync();

        $this->assertSame(['Google is limiting requests. The next sync tries again. (HTTP 403)', 'sync-1'], [$this->calendar->last_error, $this->calendar->sync_state['sync_token']]);
        $this->assertSame('Weekly planning', $this->stored(self::SERIES)->summary);
    }

    public function test_a_changed_access_role_reaches_unchanged_series_too(): void
    {
        $this->storeSeries();
        DB::table('calendar_events')->where('calendar_id', $this->calendar->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        $gone = self::googleError(410, 'fullSyncRequired', 'Sync token is no longer valid, a full sync is required.');
        // The account may only read the calendar now: Google forgets the sync token, and the series' etag stays.
        $this->http->queue($gone, self::page([self::fixture('recurring')], ['accessRole' => 'reader', 'nextSyncToken' => 'sync-2']), self::seriesInstances());

        $this->sync();

        $this->assertTrue($this->stored(self::SERIES)->read_only);
        // And back.
        $this->http->queue($gone, self::page([self::fixture('recurring')], ['accessRole' => 'owner', 'nextSyncToken' => 'sync-3']), self::seriesInstances());
        $this->sync();
        $this->assertFalse($this->stored(self::SERIES)->read_only);
        $this->assertSame([null, 'sync-3'], [$this->calendar->last_error, $this->calendar->sync_state['sync_token']]);
    }
}
