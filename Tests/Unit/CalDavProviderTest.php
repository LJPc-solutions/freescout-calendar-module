<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavClient;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\EventImporter;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\SyncService;
use Modules\LJPcCalendarModule\Tests\Support\DavResponses;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Psr\Http\Message\RequestInterface;
use Sabre\VObject\Reader;

class CalDavProviderTest extends TestCase
{
    private const SERVER = 'https://192.0.2.10';
    private const WORK = self::SERVER . '/dav/calendars/calendar-user/work/';
    private const TOKEN = 'http://sabre.io/ns/sync/7';

    private FakeHttp $http;

    private CalDavProvider $provider;

    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        config(['app.remote_host_white_list' => '192.0.2.10']);
        $this->http = FakeHttp::install();
        $this->provider = app(CalDavProvider::class);
        $connection = new CalendarConnection(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'calendar-user', 'server_url' => self::SERVER]);
        $connection->setCredentials(['username' => 'calendar-user', 'password' => 'fake-password']);
        $connection->save();
        $this->calendar = Calendar::create([
            'name' => 'Work ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $connection->id,
            'remote_id' => self::WORK, 'timezone' => 'Europe/Amsterdam', 'sync_interval' => 15,
        ]);
    }

    private function pull(array $state = [], ?SyncBudget $budget = null): ChangeSet
    {
        return $this->provider->pull($this->calendar, $state, $budget ?? SyncBudget::seconds(30));
    }

    /** The collection's own properties: its sync token and ctag. */
    private static function info(?string $token, ?string $ctag): Response
    {
        return DavResponses::multistatus([self::WORK => array_filter(['resourcetype' => 'calendar', 'getctag' => $ctag, 'sync-token' => $token])]);
    }

    /** A depth-1 listing: the collection itself and one member per name => ETag. */
    private static function members(array $etags): Response
    {
        $entries = [self::WORK => ['resourcetype' => 'calendar']];
        foreach ($etags as $name => $etag) {
            $entries[self::WORK . $name] = ['getetag' => $etag];
        }

        return DavResponses::multistatus($entries);
    }

    /** Answers a calendar-multiget with the data of every object it asks for, its ETag taken from $etags (by name). */
    private static function multiget(array $etags = []): callable
    {
        return function (RequestInterface $request) use ($etags) {
            preg_match_all('#<d:href>([^<]+)</d:href>#', (string) $request->getBody(), $matches);
            $entries = [];
            foreach ($matches[1] as $path) {
                $name = basename($path);
                $entries[self::SERVER . $path] = ['getetag' => $etags[$name] ?? '"' . $name . '-v1"', 'calendar-data' => DavResponses::event(basename($name, '.ics') . '@example.com', 'Event ' . $name)];
            }

            return DavResponses::multistatus($entries);
        };
    }

    private function requests(): array
    {
        return array_map(function (array $request) {
            return $request['method'] . ' ' . substr($request['url'], strlen(self::SERVER)) . ($request['method'] === 'PUT' || $request['method'] === 'DELETE' ? '' : ' depth ' . ($request['headers']['Depth'][0] ?? '-'));
        }, $this->http->history());
    }

    private static function hrefsIn(array $request): array
    {
        preg_match_all('#<d:href>([^<]+)</d:href>#', $request['body'], $matches);

        return $matches[1];
    }

    private static function remoteIds(array $events): array
    {
        return array_map(function (RemoteEvent $event) {
            return $event->remoteId;
        }, $events);
    }

    /** Stores an event as a previous pull did. */
    private function stored(string $name, string $etag): CalendarEvent
    {
        $href = self::WORK . $name;

        return app(EventImporter::class)->upsert($this->calendar, new RemoteEvent($href, DavResponses::event(basename($name, '.ics') . '@example.com', 'Stored ' . $name), $href, $etag), 0);
    }

    private function sync(): void
    {
        app(SyncService::class)->syncCalendar($this->calendar, SyncBudget::seconds(30));
        $this->calendar->refresh();
    }

    public function test_writes_are_possible_with_a_working_account(): void
    {
        $vcal = EventIcs::build(['uid' => 'x@example.com', 'title' => 'x', 'all_day' => true, 'start' => '2026-10-20', 'end' => '2026-10-21', 'rrule' => 'FREQ=MONTHLY;BYDAY=2TU']);

        $this->assertTrue($this->provider->canWrite());
        $this->assertTrue($this->provider->writableRecurrence($vcal->VEVENT));
        $this->assertTrue($this->calendar->isWritable());
    }

    public function test_a_first_pull_reads_the_token_then_lists_and_downloads_only_new_and_changed_objects(): void
    {
        $this->stored('visit.ics', '"visit-v1"');
        $this->http->queue(
            DavResponses::fixture('collection-info.xml'),
            DavResponses::multistatus([
                self::WORK              => ['resourcetype' => 'calendar'],
                self::WORK . 'visit.ics'   => ['getetag' => '"visit-v1"'],
                self::WORK . 'standup.ics' => ['getetag' => '"standup-v1"', 'getcontenttype' => 'text/calendar; charset=utf-8; component=vevent'],
                self::WORK . 'todo.ics'    => ['getetag' => '"todo-v1"', 'getcontenttype' => 'text/calendar;charset=utf-8;component=VTODO'],
                self::WORK . 'archive/'    => ['getetag' => '"archive"', 'resourcetype' => 'collection'],
            ]),
            self::multiget(['standup.ics' => '"standup-v1"'])
        );

        $changes = $this->pull();

        $this->assertSame([true, true, [], [self::WORK . 'visit.ics']], [$changes->complete, $changes->fullListing, $changes->deletes, $changes->unchanged]);
        $this->assertSame([self::WORK . 'standup.ics'], self::remoteIds($changes->upserts));
        $standup = $changes->upserts[0];
        $this->assertSame([self::WORK . 'standup.ics', '"standup-v1"', false, false], [$standup->href, $standup->etag, $standup->readOnly, $standup->partial]);
        $this->assertSame(['sync_token' => self::TOKEN, 'ctag' => '"ctag-7"', 'supports_sync' => true, 'pending' => [], 'pending_deletes' => [], 'retry' => [], 'more' => false], $changes->state);
        $this->assertSame(['PROPFIND /dav/calendars/calendar-user/work/ depth 0', 'PROPFIND /dav/calendars/calendar-user/work/ depth 1', 'REPORT /dav/calendars/calendar-user/work/ depth 1'], $this->requests());
        $history = $this->http->history();
        $this->assertStringContainsString('<cs:getctag/><d:sync-token/><d:supported-report-set/>', $history[0]['body']);
        $this->assertStringContainsString('<c:calendar-multiget', $history[2]['body']);
        $this->assertSame(['/dav/calendars/calendar-user/work/standup.ics'], self::hrefsIn($history[2]));
    }

    public function test_later_pulls_ask_for_the_changes_since_the_token(): void
    {
        $this->http->queue(DavResponses::fixture('sync-changes.xml'), self::multiget(['standup.ics' => '"standup-v2"']));

        $changes = $this->pull(['sync_token' => self::TOKEN, 'supports_sync' => true, 'ctag' => '"ctag-7"']);

        $this->assertSame([true, false, [self::WORK . 'cancelled.ics']], [$changes->complete, $changes->fullListing, $changes->deletes]);
        $this->assertSame([self::WORK . 'standup.ics', self::WORK . 'visit%40example.com.ics'], self::remoteIds($changes->upserts), 'the to-do is left out');
        $this->assertSame('"standup-v2"', $changes->upserts[0]->etag);
        $this->assertSame('http://sabre.io/ns/sync/8', $changes->state['sync_token']);
        [$report, $multiget] = $this->http->history();
        $this->assertSame(['REPORT', ['0']], [$report['method'], $report['headers']['Depth']]);
        $this->assertStringContainsString('<d:sync-collection', $report['body']);
        $this->assertStringContainsString('<d:sync-token>' . self::TOKEN . '</d:sync-token>', $report['body']);
        $this->assertSame(['/dav/calendars/calendar-user/work/standup.ics', '/dav/calendars/calendar-user/work/visit%40example.com.ics'], self::hrefsIn($multiget));
    }

    public function test_a_truncated_answer_keeps_paging_with_the_token_it_returned(): void
    {
        $this->http->queue(
            DavResponses::fixture('sync-truncated.xml'),
            self::multiget(),
            DavResponses::multistatus([self::WORK . 'review.ics' => ['getetag' => '"review-v1"']], 'http://sabre.io/ns/sync/9'),
            self::multiget()
        );

        $first = $this->pull(['sync_token' => self::TOKEN, 'supports_sync' => true]);
        $second = $this->pull($first->state);

        $this->assertSame([false, 'http://sabre.io/ns/sync/8', [self::WORK . 'standup.ics']], [$first->complete, $first->state['sync_token'], self::remoteIds($first->upserts)]);
        $this->assertSame([true, 'http://sabre.io/ns/sync/9', [self::WORK . 'review.ics']], [$second->complete, $second->state['sync_token'], self::remoteIds($second->upserts)]);
        $this->assertStringContainsString('<d:sync-token>http://sabre.io/ns/sync/8</d:sync-token>', $this->http->history()[2]['body']);
    }

    public function test_a_forgotten_token_starts_a_full_listing_with_a_new_token(): void
    {
        foreach ([403, 409] as $status) {
            $this->http->queue(
                DavResponses::fixture('sync-invalid-token.xml', $status),
                DavResponses::fixture('collection-info.xml'),
                self::members(['standup.ics' => '"standup-v3"']),
                self::multiget()
            );

            $changes = $this->pull(['sync_token' => 'http://sabre.io/ns/sync/1', 'supports_sync' => true, 'ctag' => '"ctag-1"']);

            $this->assertSame([true, true, self::TOKEN, true], [$changes->complete, $changes->fullListing, $changes->state['sync_token'], $changes->state['supports_sync']], 'status ' . $status);
            $this->assertSame([self::WORK . 'standup.ics'], self::remoteIds($changes->upserts));
        }
        $this->assertSame(0, $this->http->pending());
    }

    public function test_a_server_without_sync_collection_is_listed_again_only_when_its_ctag_changes(): void
    {
        $this->http->queue(new Response(501), self::info(null, '"ctag-1"'), self::members(['standup.ics' => '"standup-v1"']), self::multiget());
        $first = $this->pull(['sync_token' => self::TOKEN, 'supports_sync' => true]);
        $this->assertSame(['supports_sync' => false, 'sync_token' => null, 'ctag' => '"ctag-1"', 'pending' => [], 'pending_deletes' => [], 'retry' => [], 'more' => false], $first->state);

        // The server still hands out a token; once it failed the report, the ctag decides.
        $this->http->queue(self::info('http://sabre.io/ns/sync/2', '"ctag-1"'));
        $unchanged = $this->pull($first->state);

        $this->assertSame([[], [], true, false, $first->state], [$unchanged->upserts, $unchanged->unchanged, $unchanged->complete, $unchanged->fullListing, $unchanged->state]);
        $this->assertSame(0, $this->http->pending());
        $this->assertCount(5, $this->http->history());
    }

    public function test_a_changed_ctag_lists_the_collection_and_the_engine_removes_what_is_gone(): void
    {
        $this->stored('same.ics', '"same-v1"');
        $this->stored('edited.ics', '"edited-v1"');
        $this->stored('gone.ics', '"gone-v1"');
        DB::table('calendar_events')->where('calendar_id', $this->calendar->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        $this->calendar->update(['sync_state' => ['supports_sync' => false, 'sync_token' => null, 'ctag' => '"ctag-1"']]);
        $this->http->queue(
            self::info(null, '"ctag-2"'),
            self::members(['same.ics' => '"same-v1"', 'edited.ics' => '"edited-v2"', 'new.ics' => '"new-v1"']),
            self::multiget(['edited.ics' => '"edited-v2"', 'new.ics' => '"new-v1"'])
        );

        $this->sync();

        $events = CalendarEvent::where('calendar_id', $this->calendar->id)->orderBy('uid')->get()->keyBy('uid');
        $this->assertSame(['edited@example.com', 'new@example.com', 'same@example.com'], $events->keys()->all());
        $this->assertSame(['Event edited.ics', '"edited-v2"'], [$events['edited@example.com']->summary, $events['edited@example.com']->etag]);
        $this->assertSame('Stored same.ics', $events['same@example.com']->summary);
        $this->assertSame(['/dav/calendars/calendar-user/work/edited.ics', '/dav/calendars/calendar-user/work/new.ics'], self::hrefsIn($this->http->history()[2]));
        $this->assertSame('"ctag-2"', $this->calendar->sync_state['ctag']);
        $this->assertNull($this->calendar->last_error);
    }

    public function test_a_listing_downloads_fifty_objects_at_a_time_and_resumes_where_the_budget_stopped(): void
    {
        $etags = [];
        for ($i = 1; $i <= 120; $i++) {
            $etags[sprintf('event-%03d.ics', $i)] = '"v1"';
        }
        $this->http->queue(DavResponses::fixture('collection-info.xml'), self::members($etags), self::multiget());

        $first = $this->pull([], SyncBudget::seconds(0));

        $this->assertSame([false, true, 50], [$first->complete, $first->fullListing, count($first->upserts)]);
        $this->assertSame(['after' => self::WORK . 'event-050.ics', 'sync_token' => self::TOKEN, 'ctag' => '"ctag-7"', 'supports_sync' => true, 'unreadable' => []], $first->state['listing']);
        $this->assertCount(50, self::hrefsIn($this->http->history()[2]));

        $this->http->queue(self::members($etags), self::multiget(), self::multiget());
        $second = $this->pull($first->state);

        $this->assertSame([true, true, 70], [$second->complete, $second->fullListing, count($second->upserts)]);
        $this->assertSame(self::WORK . 'event-051.ics', $second->upserts[0]->remoteId);
        $this->assertSame(['sync_token' => self::TOKEN, 'ctag' => '"ctag-7"', 'supports_sync' => true, 'pending' => [], 'pending_deletes' => [], 'retry' => [], 'more' => false], $second->state);
        $history = $this->http->history();
        $this->assertSame([50, 20], [count(self::hrefsIn($history[4])), count(self::hrefsIn($history[5]))]);
        $this->assertSame('PROPFIND /dav/calendars/calendar-user/work/ depth 1', $this->requests()[3], 'a resumed listing reads the members again, not the token');
    }

    public function test_an_empty_listing_deletes_nothing_and_lists_again_next_time(): void
    {
        // Zoho answers a first sync-collection with an empty list; a listing that finds nothing must not empty the
        // calendar, and keeps no token, so the next sync lists again and two empty listings in a row decide.
        $this->stored('standup.ics', '"standup-v1"');
        $this->stored('visit.ics', '"visit-v1"');
        DB::table('calendar_events')->where('calendar_id', $this->calendar->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        $this->http->queue(DavResponses::fixture('collection-info.xml'), self::members([]));

        $this->sync();

        $this->assertSame(2, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
        $this->assertSame([1, null, null], [$this->calendar->sync_state['_empty_listings'], $this->calendar->sync_state['sync_token'], $this->calendar->sync_state['ctag']]);

        $this->http->queue(DavResponses::fixture('collection-info.xml'), self::members([]));
        $this->sync();

        $this->assertSame(0, CalendarEvent::where('calendar_id', $this->calendar->id)->count(), 'the second empty listing in a row empties it');
        $this->assertSame(['PROPFIND', 'PROPFIND', 'PROPFIND', 'PROPFIND'], array_column($this->http->history(), 'method'));
    }

    public function test_an_object_the_server_refuses_keeps_its_stored_copy_and_is_asked_for_again(): void
    {
        $this->stored('standup.ics', '"standup-v1"');
        $this->stored('visit.ics', '"visit-v1"');
        DB::table('calendar_events')->where('calendar_id', $this->calendar->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        $this->http->queue(
            DavResponses::fixture('collection-info.xml'),
            self::members(['standup.ics' => '"standup-v2"', 'visit.ics' => '"visit-v2"']),
            DavResponses::multistatus([
                self::WORK . 'standup.ics' => ['getetag' => '"standup-v2"', 'calendar-data' => DavResponses::event('standup@example.com', 'Standup moved')],
                self::WORK . 'visit.ics'   => 500,
            ]),
            new Response(503)
        );

        $this->sync();

        $events = CalendarEvent::where('calendar_id', $this->calendar->id)->orderBy('uid')->pluck('summary', 'uid')->all();
        $this->assertSame(['standup@example.com' => 'Standup moved', 'visit@example.com' => 'Stored visit.ics'], $events, 'the listing never sweeps what it could not read');
        $this->assertSame([self::WORK . 'visit.ics'], $this->calendar->sync_state['retry']);
        $this->assertNull($this->calendar->last_error);

        // The next sync asks for it again, together with whatever the token reports.
        $this->http->queue(DavResponses::multistatus([], 'http://sabre.io/ns/sync/8'), self::multiget(['visit.ics' => '"visit-v2"']));
        $this->sync();

        $this->assertSame('Event visit.ics', CalendarEvent::findByRemoteId($this->calendar->id, self::WORK . 'visit.ics')->summary);
        $this->assertSame([[], 'http://sabre.io/ns/sync/8'], [$this->calendar->sync_state['retry'], $this->calendar->sync_state['sync_token']]);
        $this->assertSame(['/dav/calendars/calendar-user/work/visit.ics'], self::hrefsIn($this->http->history()[5]));
    }

    public function test_a_multiget_over_the_size_limit_is_split_until_one_object_is_left(): void
    {
        $tooLarge = new Response(207, ['Content-Length' => (string) (CalDavClient::MAX_BYTES + 1)], '<d:multistatus xmlns:d="DAV:"/>');
        $this->http->queue(
            DavResponses::fixture('collection-info.xml'),
            self::members(['big.ics' => '"big-v1"', 'small.ics' => '"small-v1"']),
            $tooLarge,
            $tooLarge,
            self::multiget()
        );

        $changes = $this->pull();

        $this->assertSame([[self::WORK . 'small.ics'], [self::WORK . 'big.ics'], true], [self::remoteIds($changes->upserts), $changes->unchanged, $changes->complete], 'the big one keeps its stored copy');
        $this->assertSame([[self::WORK . 'big.ics'], null], [$changes->state['retry'], $this->calendar->last_error]);
        $history = $this->http->history();
        $this->assertSame([2, 1, 1], [count(self::hrefsIn($history[2])), count(self::hrefsIn($history[3])), count(self::hrefsIn($history[4]))]);
        $this->assertSame([5, 0], [count($history), $this->http->pending()], 'an object too large on its own is not asked for again in this round');
    }

    public function test_without_sync_collection_an_unreadable_object_lists_the_calendar_again_next_time(): void
    {
        $this->http->queue(self::info(null, '"ctag-2"'), self::members(['standup.ics' => '"standup-v2"']), DavResponses::multistatus([self::WORK . 'standup.ics' => 500]), new Response(500));

        $changes = $this->pull(['supports_sync' => false, 'sync_token' => null, 'ctag' => '"ctag-1"']);

        $this->assertSame([[self::WORK . 'standup.ics'], null, []], [$changes->unchanged, $changes->state['ctag'], $changes->state['retry']]);
    }

    public function test_many_changes_are_downloaded_within_the_budget(): void
    {
        $entries = [self::WORK . 'renamed-before.ics' => 404];
        for ($i = 1; $i <= 120; $i++) {
            $entries[sprintf('%sevent-%03d.ics', self::WORK, $i)] = ['getetag' => '"v2"'];
        }
        $this->http->queue(DavResponses::multistatus($entries, 'http://sabre.io/ns/sync/8'), self::multiget());

        $first = $this->pull(['sync_token' => self::TOKEN, 'supports_sync' => true], SyncBudget::seconds(0));

        $this->assertSame([false, 50, 70, 'http://sabre.io/ns/sync/8'], [$first->complete, count($first->upserts), count($first->state['pending']), $first->state['sync_token']]);
        $this->assertSame([[], [self::WORK . 'renamed-before.ics']], [$first->deletes, $first->state['pending_deletes']], 'deletes wait for the last change, which may be the same event under a new href');

        $this->http->queue(self::multiget(), self::multiget());
        $second = $this->pull($first->state);

        $this->assertSame([true, 70, [], [self::WORK . 'renamed-before.ics'], []], [$second->complete, count($second->upserts), $second->state['pending'], $second->deletes, $second->state['pending_deletes']]);
        $this->assertSame(['REPORT', 'REPORT', 'REPORT', 'REPORT'], array_column($this->http->history(), 'method'), 'the server is not asked again before the waiting changes are in');
        $this->assertStringContainsString('<d:sync-collection', $this->http->history()[0]['body']);
        $this->assertSame([50, 50, 20], [count(self::hrefsIn($this->http->history()[1])), count(self::hrefsIn($this->http->history()[2])), count(self::hrefsIn($this->http->history()[3]))]);
    }

    public function test_a_507_without_a_new_token_ends_the_round(): void
    {
        $this->http->queue(DavResponses::multistatus([self::WORK => 507, self::WORK . 'standup.ics' => ['getetag' => '"v2"']], self::TOKEN), self::multiget());

        $changes = $this->pull(['sync_token' => self::TOKEN, 'supports_sync' => true]);

        $this->assertSame([true, false], [$changes->complete, $changes->state['more']]);
    }

    public function test_a_server_whose_reports_leave_out_sync_collection_is_listed_by_ctag(): void
    {
        $this->http->queue(
            new Response(207, [], '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:response><d:href>/dav/calendars/calendar-user/work/</d:href>'
                . '<d:propstat><d:prop><cs:getctag>"ctag-3"</cs:getctag><d:sync-token>http://sabre.io/ns/sync/3</d:sync-token><d:supported-report-set><d:supported-report><d:report><d:expand-property/></d:report></d:supported-report></d:supported-report-set></d:prop>'
                . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'),
            self::members(['standup.ics' => '"standup-v1"']),
            self::multiget()
        );

        $changes = $this->pull();

        $this->assertSame([false, null, '"ctag-3"'], [$changes->state['supports_sync'], $changes->state['sync_token'], $changes->state['ctag']]);
    }

    public function test_objects_a_multiget_sends_without_data_are_read_one_by_one(): void
    {
        $this->http->queue(
            DavResponses::multistatus([self::WORK . 'standup.ics' => ['getetag' => '"standup-v2"']], 'http://sabre.io/ns/sync/8'),
            DavResponses::multistatus([self::WORK . 'standup.ics' => ['getetag' => '"standup-v2"']]),
            new Response(200, ['ETag' => '"standup-v2"'], DavResponses::event('standup@example.com', 'Standup'))
        );

        $changes = $this->pull(['sync_token' => self::TOKEN, 'supports_sync' => true]);

        $this->assertSame([self::WORK . 'standup.ics'], self::remoteIds($changes->upserts));
        $this->assertSame(['"standup-v2"', DavResponses::event('standup@example.com', 'Standup')], [$changes->upserts[0]->etag, $changes->upserts[0]->ics]);
        $this->assertSame('GET', $this->http->history()[2]['method']);
    }

    public function test_create_puts_a_new_object_with_its_time_zones_and_client_side_scheduling(): void
    {
        $vcal = Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//LJPc//FreeScout Calendar 3//EN\r\nBEGIN:VEVENT\r\nUID:visit@example.com\r\nDTSTAMP:20261001T080000Z\r\n"
            . "DTSTART;TZID=Europe/Amsterdam:20261020T100000\r\nDTEND;TZID=Europe/Amsterdam:20261020T110000\r\nSUMMARY:Visit\r\n"
            . "ORGANIZER;CN=Agent:mailto:agent@example.com\r\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:customer@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->http->queue(new Response(201, ['ETag' => '"new-v1"']));

        $ref = $this->provider->create($this->calendar, $vcal);

        $url = self::WORK . sha1('visit@example.com') . '.ics';
        $this->assertSame([$url, $url, '"new-v1"', null], [$ref->remoteId, $ref->href, $ref->etag, $ref->uid]);
        $put = $this->http->history()[0];
        $this->assertSame(['PUT', $url, ['*']], [$put['method'], $put['url'], $put['headers']['If-None-Match']]);
        $sent = Reader::read($put['body']);
        $this->assertSame('Europe/Amsterdam', (string) $sent->VTIMEZONE->TZID);
        $this->assertSame(['CLIENT', 'CLIENT'], [(string) $sent->VEVENT->ORGANIZER['SCHEDULE-AGENT'], (string) $sent->VEVENT->ATTENDEE['SCHEDULE-AGENT']]);
        $this->assertNull($vcal->VTIMEZONE, 'the caller\'s calendar is left as it was');
        $this->assertNull($vcal->VEVENT->ATTENDEE['SCHEDULE-AGENT']);
    }

    public function test_create_describes_every_tzid_sabre_can_read_and_keeps_the_others_as_they_are(): void
    {
        $event = function (string $uid, string $tzid) {
            return Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Other app//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261001T080000Z\r\n"
                . "DTSTART;TZID=$tzid:20261020T100000\r\nDTEND;TZID=$tzid:20261020T110000\r\nSUMMARY:Visit\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        };
        $this->http->queue(new Response(201, ['ETag' => '"windows-v1"']), new Response(201, ['ETag' => '"cest-v1"']));

        // A Windows name gets the rules of the zone it stands for, under its own name.
        $this->provider->create($this->calendar, $event('windows@example.com', 'W. Europe Standard Time'));
        // An abbreviation sabre can't read is sent without a definition, as it came.
        $this->provider->create($this->calendar, $event('cest@example.com', 'CEST'));

        [$windows, $cest] = $this->http->history();
        $sent = Reader::read($windows['body']);
        $this->assertSame(['W. Europe Standard Time', '+0200'], [(string) $sent->VTIMEZONE->TZID, (string) $sent->VTIMEZONE->DAYLIGHT->TZOFFSETTO]);
        $this->assertStringNotContainsString('BEGIN:VTIMEZONE', $cest['body']);
        $this->assertStringContainsString('DTSTART;TZID=CEST:20261020T100000', $cest['body']);
    }

    public function test_a_uid_another_calendar_of_the_account_holds_gets_a_new_one(): void
    {
        $vcal = EventIcs::build(['uid' => 'moved@example.com', 'title' => 'Moved', 'all_day' => true, 'start' => '2026-10-20', 'end' => '2026-10-21']);
        $this->http->queue(
            new Response(403, [], '<?xml version="1.0"?><d:error xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><c:no-uid-conflict><d:href>/dav/calendars/calendar-user/home/moved.ics</d:href></c:no-uid-conflict></d:error>'),
            new Response(201, ['ETag' => '"moved-v1"'])
        );

        $ref = $this->provider->create($this->calendar, $vcal);

        [$refused, $put] = $this->http->history();
        $this->assertSame(self::WORK . sha1('moved@example.com') . '.ics', $refused['url']);
        $this->assertNotNull($ref->uid);
        $this->assertNotSame('moved@example.com', $ref->uid);
        $this->assertSame([self::WORK . sha1($ref->uid) . '.ics', $put['url'], '"moved-v1"'], [$ref->href, $ref->remoteId, $ref->etag]);
        $this->assertSame($ref->uid, (string) Reader::read($put['body'])->VEVENT->UID);
        $this->assertSame('moved@example.com', (string) $vcal->VEVENT->UID, 'the caller decides what to store');
    }

    public function test_a_create_whose_etag_cant_be_read_back_still_succeeds(): void
    {
        $vcal = EventIcs::build(['uid' => 'call@example.com', 'title' => 'Call', 'all_day' => true, 'start' => '2026-10-20', 'end' => '2026-10-21']);
        $this->http->queue(new Response(201), new Response(500));

        $ref = $this->provider->create($this->calendar, $vcal);

        $this->assertSame([self::WORK . sha1('call@example.com') . '.ics', null], [$ref->href, $ref->etag]);
    }

    public function test_a_create_answered_without_an_etag_reads_it_back(): void
    {
        $vcal = EventIcs::build(['uid' => 'call@example.com', 'title' => 'Call', 'all_day' => true, 'start' => '2026-10-20', 'end' => '2026-10-21']);
        $url = self::WORK . sha1('call@example.com') . '.ics';
        $this->http->queue(new Response(201), DavResponses::multistatus([$url => ['getetag' => '"call-v1"']]));

        $ref = $this->provider->create($this->calendar, $vcal);

        $this->assertSame('"call-v1"', $ref->etag);
        $this->assertSame(['PUT', 'PROPFIND'], array_column($this->http->history(), 'method'));
        $this->assertStringNotContainsString('BEGIN:VTIMEZONE', $this->http->history()[0]['body'], 'an all-day event uses no time zone');
    }

    public function test_update_writes_the_whole_calendar_with_if_match_and_a_newer_object_is_a_conflict(): void
    {
        $event = $this->stored('standup.ics', '"standup-v1"');
        $vcal = Reader::read($event->ics);
        $this->http->queue(new Response(204, ['ETag' => '"standup-v2"']), new Response(412));

        $ref = $this->provider->update($this->calendar, $event, $vcal, '20261020T080000Z');

        $this->assertSame([self::WORK . 'standup.ics', '"standup-v2"'], [$ref->href, $ref->etag]);
        $put = $this->http->history()[0];
        $this->assertSame(['PUT', self::WORK . 'standup.ics', ['"standup-v1"']], [$put['method'], $put['url'], $put['headers']['If-Match']]);
        $this->assertStringContainsString('BEGIN:VTIMEZONE', $put['body']);
        $this->expectException(ProviderConflict::class);
        $this->provider->update($this->calendar, $event, $vcal);
    }

    public function test_delete_removes_the_object_with_if_match(): void
    {
        $event = $this->stored('standup.ics', '"standup-v1"');
        $this->http->queue(new Response(204), new Response(404));

        $this->provider->delete($this->calendar, $event);
        $this->provider->delete($this->calendar, $event);

        $this->assertSame(['DELETE /dav/calendars/calendar-user/work/standup.ics', 'DELETE /dav/calendars/calendar-user/work/standup.ics'], $this->requests());
        $this->assertSame(['"standup-v1"'], $this->http->history()[0]['headers']['If-Match']);
    }

    public function test_a_password_the_server_refuses_during_a_sync_marks_the_connection_for_reauthorization(): void
    {
        $this->http->queue(new Response(401, ['WWW-Authenticate' => 'Basic realm="dav"']));

        $this->sync();

        $connection = $this->calendar->calendarConnection->fresh();
        $this->assertSame([CalendarConnection::STATUS_NEEDS_REAUTH, 'The account must be connected again.'], [$connection->status, $connection->last_error]);
        $this->assertSame(["This calendar's connection needs to be re-authorized by an administrator. (HTTP 401)", 1], [$this->calendar->last_error, $this->calendar->sync_failures]);

        // Until an administrator logs in again, the refused password is never sent again.
        $this->calendar = Calendar::find($this->calendar->id);
        try {
            $this->pull(['sync_token' => self::TOKEN, 'supports_sync' => true]);
            $this->fail('Pulled with a login the server refused');
        } catch (ConnectionNeedsReauth $e) {
            $this->assertCount(1, $this->http->history());
        }
    }

    public function test_a_password_the_server_refuses_during_a_write_marks_the_connection_and_answers_502(): void
    {
        $user = $this->makeUser();
        CalendarPermission::create(['calendar_id' => $this->calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $event = $this->stored('standup.ics', '"standup-v1"');
        $reauth = ['code' => 'connection_needs_reauth', 'message' => "This calendar's connection needs to be re-authorized by an administrator."];
        $this->http->queue(new Response(401, ['WWW-Authenticate' => 'Basic realm="dav"']));

        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $event->id)->assertStatus(502)->assertExactJson($reauth);

        $connection = $this->calendar->calendarConnection->fresh();
        $this->assertSame([CalendarConnection::STATUS_NEEDS_REAUTH, 'The account must be connected again.'], [$connection->status, $connection->last_error]);
        $this->assertNotNull(CalendarEvent::find($event->id), 'nothing was deleted');
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $event->id, ['title' => 'Changed'])->assertStatus(502)->assertExactJson($reauth);
        $this->assertCount(1, $this->http->history(), 'the next write is refused without a request');
    }

    public function test_only_a_401_the_digest_answer_gets_counts_and_not_the_challenge_before_it(): void
    {
        // A response of its own each time: Guzzle closes the body of a challenge it answers.
        $challenge = function () {
            return new Response(401, ['WWW-Authenticate' => 'Digest realm="dav", nonce="abc", qop="auth"']);
        };
        $state = ['sync_token' => self::TOKEN, 'supports_sync' => true];
        // Basic first, then Guzzle's digest probe and its answer: two challenges before the server accepts the login.
        $this->http->queue($challenge(), $challenge(), DavResponses::multistatus([], 'http://sabre.io/ns/sync/8'));

        $changes = $this->pull($state);

        $this->assertSame([true, 'http://sabre.io/ns/sync/8'], [$changes->complete, $changes->state['sync_token']]);
        $this->assertSame([CalendarConnection::STATUS_OK, null], [$this->calendar->calendarConnection->fresh()->status, $this->calendar->calendarConnection->fresh()->last_error]);

        // A digest answer the server refuses is a refused login.
        $this->http->queue($challenge(), $challenge(), new Response(401, ['WWW-Authenticate' => 'Digest realm="dav", nonce="def", qop="auth"']));
        try {
            $this->pull($state);
            $this->fail('A refused digest login passed');
        } catch (ConnectionNeedsReauth $e) {
            $this->assertSame(401, $e->httpStatus());
        }
        $this->assertSame(CalendarConnection::STATUS_NEEDS_REAUTH, $this->calendar->calendarConnection->fresh()->status);
        $this->assertSame(0, $this->http->pending());
        $this->assertSame(['Basic ', '', 'Digest', 'Basic ', '', 'Digest'], array_map(function (array $request) {
            return substr($request['headers']['Authorization'][0] ?? '', 0, 6);
        }, $this->http->history()));
    }

    public function test_a_403_is_no_refused_login(): void
    {
        // Without a precondition: the calendar keeps the message that names the login, but a 403 refuses this request
        // (rights, a rule of the server), so the account is not marked.
        $this->http->queue(new Response(403));
        $this->sync();
        $this->assertSame(['The CalDAV server refused the username or password. (HTTP 403)', CalendarConnection::STATUS_OK], [$this->calendar->last_error, $this->calendar->calendarConnection->fresh()->status]);

        // With a precondition: the server refused what was written.
        $event = $this->stored('standup.ics', '"standup-v1"');
        $this->http->queue(new Response(403, [], '<?xml version="1.0"?><d:error xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><c:valid-calendar-data/></d:error>'));
        try {
            $this->provider->update($this->calendar, $event, Reader::read($event->ics));
            $this->fail('A refused write passed');
        } catch (ProviderUnavailable $e) {
            $this->assertSame(['The CalDAV server refused this request (HTTP 403).', 403], [$e->userMessage(), $e->httpStatus()]);
        }
        $this->assertSame([CalendarConnection::STATUS_OK, null], [$this->calendar->calendarConnection->fresh()->status, $this->calendar->calendarConnection->fresh()->last_error]);
    }

    public function test_a_401_for_a_login_changed_meanwhile_leaves_the_connection_alone(): void
    {
        // An administrator stores a new password while a request with the old one is under way.
        $this->http->queue(function () {
            $connection = CalendarConnection::find($this->calendar->connection_id);
            $connection->setCredentials(['username' => 'calendar-user', 'password' => 'new-password']);
            $connection->save();

            return new Response(401, ['WWW-Authenticate' => 'Basic realm="dav"']);
        });

        $this->sync();

        $connection = $this->calendar->calendarConnection->fresh();
        $this->assertSame([CalendarConnection::STATUS_OK, null, 'new-password'], [$connection->status, $connection->last_error, $connection->credentials()['password']]);
        $this->assertSame('The CalDAV server refused the username or password. (HTTP 401)', $this->calendar->last_error);
    }

    public function test_a_calendar_without_an_account_can_not_sync(): void
    {
        $this->calendar->update(['connection_id' => null]);

        $this->expectException(ProviderUnavailable::class);
        $this->expectExceptionMessage('This calendar has no CalDAV account.');
        $this->pull();
    }
}
