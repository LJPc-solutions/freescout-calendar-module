<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\Ics\IcsProvider;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\SyncService;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

class IcsProviderTest extends TestCase
{
    /** An IP address, so the host check needs no DNS lookup. */
    private const FEED = 'https://93.184.215.14/private/abc123secret/basic.ics';
    private const SERIES = '5s0hq1mj6h4f1b2kq3o7m9c1fs@google.com';
    private const CALL = '2b8f0c7e1d9a@google.com';
    private const CLOSED = '0a1b2c3d4e5f@google.com';
    private const GENERIC = 'The calendar provider could not be reached. Try again later.';

    private FakeHttp $http;
    private IcsProvider $provider;
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = FakeHttp::install();
        $this->provider = app(IcsProvider::class);
        $this->calendar = Calendar::create(['name' => 'Feed ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS, 'remote_id' => self::FEED, 'timezone' => 'Europe/Amsterdam']);
    }

    private static function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../fixtures/ics/' . $name);
    }

    private function pull(array $state = []): ChangeSet
    {
        return $this->provider->pull($this->calendar, $state, SyncBudget::seconds(30));
    }

    /** @return RemoteEvent[] keyed by remote id */
    private static function byRemoteId(ChangeSet $changes): array
    {
        $events = [];
        foreach ($changes->upserts as $event) {
            $events[$event->remoteId] = $event;
        }

        return $events;
    }

    private function assertRefused(string $message, ?int $httpStatus = null): void
    {
        try {
            $this->pull();
            $this->fail('The pull should have failed with: ' . $message);
        } catch (ProviderUnavailable $e) {
            $this->assertSame([$message, $httpStatus], [$e->userMessage(), $e->httpStatus()]);
        }
    }

    public function test_feeds_are_read_only(): void
    {
        $vcal = EventIcs::build(['uid' => 'x@example.com', 'title' => 'x', 'all_day' => true, 'start' => '2026-10-20', 'end' => '2026-10-21']);

        $this->assertFalse($this->provider->canWrite());
        $this->assertFalse($this->provider->writableRecurrence($vcal->VEVENT));
        $this->assertSame([], $this->provider->listCalendars(new CalendarConnection()));
        $this->assertFalse($this->calendar->isWritable());
        $this->assertFalse($this->makeAdmin()->can('create', $this->calendar));
        foreach (['create' => [$this->calendar, $vcal], 'update' => [$this->calendar, new CalendarEvent(), $vcal], 'delete' => [$this->calendar, new CalendarEvent()]] as $method => $arguments) {
            try {
                $this->provider->{$method}(...$arguments);
                $this->fail($method . ' should be refused');
            } catch (LogicException $e) {
                $this->assertStringContainsString('read-only', $e->getMessage());
            }
        }
    }

    public function test_splits_the_feed_into_one_calendar_per_uid_with_only_the_time_zones_it_uses(): void
    {
        $feed = self::fixture('google.ics');
        $this->http->queue(new Response(200, ['ETag' => '"v1"', 'Last-Modified' => 'Wed, 07 Oct 2026 10:00:00 GMT'], $feed));

        $changes = $this->pull();

        $this->assertSame([true, true, [], []], [$changes->complete, $changes->fullListing, $changes->deletes, $changes->unchanged]);
        $this->assertSame(['etag' => '"v1"', 'last_modified' => 'Wed, 07 Oct 2026 10:00:00 GMT', 'hash' => IcsProvider::contentHash($feed)], $changes->state);
        $events = self::byRemoteId($changes);
        $this->assertSame([self::SERIES, self::CALL, self::CLOSED], array_keys($events));
        foreach ($events as $event) {
            $this->assertSame([null, null, true, false], [$event->href, $event->etag, $event->readOnly, $event->partial]);
        }
        $series = Reader::read($events[self::SERIES]->ics);
        $this->assertCount(2, $series->select('VEVENT'), 'the override stays with its series');
        $this->assertSame(['Europe/Amsterdam'], array_map(function ($zone) {
            return (string) $zone->TZID;
        }, $series->select('VTIMEZONE')));
        $this->assertSame([], Reader::read($events[self::CALL]->ics)->select('VTIMEZONE'));
        $this->assertSame([['GET', self::FEED]], array_map(function (array $request) {
            return [$request['method'], $request['url']];
        }, $this->http->history()));
        $this->assertArrayNotHasKey('If-None-Match', $this->http->history()[0]['headers']);
    }

    public function test_sends_the_saved_validators_and_a_304_changes_nothing(): void
    {
        $state = ['etag' => '"v1"', 'last_modified' => 'Wed, 07 Oct 2026 10:00:00 GMT', 'hash' => sha1('previous')];
        $this->http->queue(new Response(304));

        $changes = $this->pull($state);

        $this->assertSame([[], true, false, $state], [$changes->upserts, $changes->complete, $changes->fullListing, $changes->state]);
        $headers = $this->http->history()[0]['headers'];
        $this->assertSame(['"v1"'], $headers['If-None-Match']);
        $this->assertSame(['Wed, 07 Oct 2026 10:00:00 GMT'], $headers['If-Modified-Since']);
    }

    public function test_an_unchanged_feed_is_not_imported_again(): void
    {
        $feed = self::fixture('google.ics');
        // Google's export stamps every request with the current time.
        $this->http->queue(new Response(200, ['ETag' => '"v2"'], str_replace('DTSTAMP:20261007T100000Z', 'DTSTAMP:20261007T121500Z', $feed)));

        $changes = $this->pull(['etag' => '"v1"', 'hash' => IcsProvider::contentHash($feed)]);

        $this->assertSame([[], true, false], [$changes->upserts, $changes->complete, $changes->fullListing]);
        $this->assertSame(['etag' => '"v2"', 'last_modified' => null, 'hash' => IcsProvider::contentHash($feed)], $changes->state);
        $this->assertNotSame(IcsProvider::contentHash($feed), IcsProvider::contentHash(str_replace('Standup (moved)', 'Standup (moved again)', $feed)));
    }

    public function test_webcal_addresses_are_fetched_over_https(): void
    {
        $this->calendar->remote_id = 'webcal://calendar.example.com/team.ics';
        $this->http->queue(new Response(200, [], self::fixture('tasks.ics')));

        $this->pull();

        $this->assertSame('https://calendar.example.com/team.ics', $this->http->history()[0]['url']);
    }

    public function test_a_feed_over_20_mb_is_refused(): void
    {
        $tooLarge = 'The calendar feed is too large (over 20 MB).';
        $this->http->queue(new Response(200, ['Content-Length' => (string) (IcsProvider::MAX_BYTES + 1)], 'BEGIN:VCALENDAR'));
        $this->assertRefused($tooLarge);

        $this->http->queue(new Response(200, [], str_repeat('A', IcsProvider::MAX_BYTES + 1)));
        $this->assertRefused($tooLarge);
    }

    public function test_errors_say_what_went_wrong(): void
    {
        foreach ([401, 403] as $status) {
            $this->http->queue(new Response($status, [], 'Denied'));
            $this->assertRefused('The calendar feed refused access (HTTP ' . $status . ').', $status);
        }
        foreach ([404, 410] as $status) {
            $this->http->queue(new Response($status, [], 'Gone'));
            $this->assertRefused('The calendar feed was not found (HTTP ' . $status . ').', $status);
        }
        $this->http->queue(new Response(500, [], 'Internal error'));
        $this->assertRefused(self::GENERIC, 500);

        $this->http->queue(new ConnectException('cURL error 7: Failed to connect to 93.184.215.14 port 443', new Request('GET', self::FEED)));
        $this->assertRefused(self::GENERIC);

        foreach ([self::fixture('invalid.ics'), "BEGIN:VCARD\nVERSION:3.0\nFN:Agent\nEND:VCARD\n", ''] as $body) {
            $this->http->queue(new Response(200, [], $body));
            $this->assertRefused('The calendar feed is not a valid iCalendar file.');
        }
    }

    public function test_an_address_no_request_can_be_made_to_fails_the_sync_with_a_message(): void
    {
        // Allowed by name, but no valid host: Guzzle refuses it before anything is sent.
        config(['app.remote_host_white_list' => 'exa%20mple']);
        $this->calendar->remote_id = 'https://exa%20mple/team.ics';

        $this->assertRefused('This is not a valid http or https address.');
        $this->assertSame([], $this->http->history());
    }

    public function test_a_blocked_or_missing_address_is_never_requested(): void
    {
        $this->calendar->remote_id = 'http://169.254.169.254/latest/meta-data/';
        $this->assertRefused('This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST.');

        $this->calendar->remote_id = null;
        $this->assertRefused('This is not a valid http or https address.');

        $this->assertSame([], $this->http->history());
    }

    public function test_tasks_and_journals_are_dropped_and_events_without_a_uid_get_a_stable_one(): void
    {
        $this->http->queue(new Response(200, [], self::fixture('tasks.ics')), new Response(200, [], self::fixture('tasks.ics')));

        $first = self::byRemoteId($this->pull());
        $second = self::byRemoteId($this->pull());

        $this->assertSame(array_keys($first), array_keys($second));
        $this->assertCount(3, $first);
        $generated = array_values(array_diff(array_keys($first), ['event-1@example.com']));
        $this->assertCount(2, $generated, 'two identical events without a UID still get one each');
        foreach ($generated as $uid) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $uid);
            $this->assertSame($uid, (string) Reader::read($first[$uid]->ics)->VEVENT->UID);
        }
        foreach ($first as $event) {
            $this->assertStringNotContainsString('VTODO', $event->ics);
            $this->assertStringNotContainsString('VJOURNAL', $event->ics);
        }
    }

    public function test_failures_are_logged_with_the_host_only(): void
    {
        $log = LogRecorder::install();
        $this->http->queue(
            new Response(404, [], '<p>The requested URL /private/abc123secret/basic.ics was not found on this server.</p>'),
            new ConnectException('cURL error 28: Operation timed out after 30001 milliseconds for ' . self::FEED, new Request('GET', self::FEED))
        );

        foreach ([1, 2] as $attempt) {
            try {
                $this->pull();
            } catch (ProviderUnavailable $e) {
                $this->assertStringNotContainsString('abc123secret', $e->getMessage() . ($e->getPrevious() ? $e->getPrevious()->getMessage() : ''));
            }
        }

        $records = $log->records('warning');
        $this->assertCount(2, $records);
        $this->assertSame(['calendar_id' => $this->calendar->id, 'provider' => 'ics', 'status' => 404, 'host' => '93.184.215.14'], $records[0]['context']);
        $this->assertSame('cURL error 28: Operation timed out after 30001 milliseconds for 93.184.215.14', $records[1]['context']['error']);
        $this->assertStringNotContainsString('abc123secret', json_encode($log->records()));
    }

    public function test_the_sync_engine_imports_a_feed_and_removes_what_leaves_it(): void
    {
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $feed = self::fixture('google.ics');
        $this->http->queue(new Response(200, [], $feed));

        $this->assertSame(['synced' => 1, 'failed' => 0, 'skipped' => 0], app(SyncService::class)->runDue(SyncBudget::seconds(30), $this->calendar->id));

        $series = CalendarEvent::findByRemoteId($this->calendar->id, self::SERIES);
        $occurrences = CalendarOccurrence::where('event_id', $series->id)->orderBy('starts_at')->get();
        // Monday 09:00 in Amsterdam: 07:00 UTC in summer time, 08:00 UTC after 25 October; 26 October moved, 2 November cancelled.
        $this->assertSame(['2026-10-19 07:00:00', '2026-10-26 09:00:00', '2026-11-09 08:00:00', '2026-11-16 08:00:00', '2026-11-23 08:00:00'], $occurrences->pluck('starts_at')->all());
        $this->assertSame('Standup (moved)', $occurrences[1]->summary);
        $this->assertSame('2026-10-21 13:00:00', CalendarEvent::findByRemoteId($this->calendar->id, self::CALL)->starts_at);
        $closed = CalendarEvent::findByRemoteId($this->calendar->id, self::CLOSED);
        $this->assertSame(['2026-10-23 00:00:00', true], [$closed->starts_at, $closed->all_day]);
        $this->assertSame([true], CalendarEvent::where('calendar_id', $this->calendar->id)->pluck('read_only')->unique()->values()->all());

        // The next listing must start after these rows were written to sweep them.
        DB::table('calendar_events')->where('calendar_id', $this->calendar->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        $this->http->queue(new Response(200, [], preg_replace('/BEGIN:VEVENT\nDTSTART:20261021T130000Z.*?END:VEVENT\n/s', '', $feed)));
        app(SyncService::class)->runDue(SyncBudget::seconds(30), $this->calendar->id, true);

        $this->assertNull(CalendarEvent::findByRemoteId($this->calendar->id, self::CALL));
        $this->assertSame(2, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
    }

    public function test_a_feed_that_becomes_empty_empties_its_calendar_at_the_second_empty_listing(): void
    {
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->http->queue(new Response(200, ['ETag' => '"full"'], self::fixture('google.ics')));
        app(SyncService::class)->runDue(SyncBudget::seconds(30), $this->calendar->id);
        $this->assertSame(3, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
        DB::table('calendar_events')->where('calendar_id', $this->calendar->id)->update(['updated_at' => '2026-01-01 00:00:00']);

        // The feed is empty now, and answers 304 to a request that names its version.
        $empty = function (\Psr\Http\Message\RequestInterface $request) {
            return $request->getHeaderLine('If-None-Match') === '"empty"' ? new Response(304) : new Response(200, ['ETag' => '"empty"'], "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Feed//EN\r\nEND:VCALENDAR\r\n");
        };
        $this->http->queue($empty, $empty);
        app(SyncService::class)->runDue(SyncBudget::seconds(30), $this->calendar->id, true);
        $this->assertSame(3, CalendarEvent::where('calendar_id', $this->calendar->id)->count(), 'one empty listing never empties a calendar');
        app(SyncService::class)->runDue(SyncBudget::seconds(30), $this->calendar->id, true);

        $this->assertArrayNotHasKey('If-None-Match', $this->http->history()[2]['headers']);
        $this->assertSame(0, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
    }

    public function test_outlook_feeds_with_windows_time_zones_import_at_the_right_time(): void
    {
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->http->queue(new Response(200, [], self::fixture('outlook.ics')));

        app(SyncService::class)->runDue(SyncBudget::seconds(30), $this->calendar->id);

        $events = CalendarEvent::where('calendar_id', $this->calendar->id)->orderBy('starts_at')->get();
        $this->assertSame(['Quarterly review', 'Busy'], $events->pluck('summary')->all());
        $this->assertSame(['2026-10-27 13:00:00', '2026-10-27 14:30:00'], [$events[0]->starts_at, $events[0]->ends_at]);
        $this->assertSame(['2026-10-28 08:00:00', true], [$events[1]->starts_at, $events[1]->is_private]);
        $this->assertStringContainsString('BEGIN:VTIMEZONE', $events[0]->ics);
    }
}
