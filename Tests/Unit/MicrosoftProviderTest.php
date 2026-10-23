<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\Microsoft\GraphApi;
use Modules\LJPcCalendarModule\CalendarProviders\Microsoft\GraphEventMapper;
use Modules\LJPcCalendarModule\CalendarProviders\Microsoft\MicrosoftProvider;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteCalendar;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\EventImporter;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\ExportService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\SyncService;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Psr\Http\Message\RequestInterface;
use Sabre\VObject\Reader;

class MicrosoftProviderTest extends TestCase
{
    private const BASE = 'https://graph.microsoft.com/v1.0/';
    private const CALENDAR = 'me/calendars/AAMkADExampleCalendar01AAA=';
    private const EVENTS = self::CALENDAR . '/events';
    private const SINGLE = 'AAMkADExampleSingle0001AAA=';
    private const ALL_DAY = 'AAMkADExampleAllDay0001AAA=';
    private const SERIES = 'AAMkADExampleSeries0001AAA=';
    private const ZONE = 'Europe/Amsterdam';
    private const SCOPES = 'offline_access User.Read Calendars.ReadWrite Calendars.ReadWrite.Shared';

    private FakeHttp $http;

    private MicrosoftProvider $provider;

    private Calendar $calendar;

    /** @var float[] */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->http = FakeHttp::install();
        app(ConnectionService::class)->saveAppCredentials('microsoft', ['tenant' => 'example.onmicrosoft.com', 'client_id' => '00000000-0000-4000-8000-000000000001', 'client_secret' => 'test-microsoft-secret']);
        app()->instance(GraphApi::class, new GraphApi($this->http, app(ConnectionService::class), function (float $seconds) {
            $this->sleeps[] = $seconds;
        }));
        $this->provider = app(MicrosoftProvider::class);
        $connection = new CalendarConnection(['provider' => Calendar::PROVIDER_MICROSOFT, 'account' => 'agent@example.com']);
        $connection->setCredentials(['refresh_token' => 'test-refresh-1', 'access_token' => 'test-access-1', 'expires_at' => time() + 3600, 'scopes' => self::SCOPES]);
        $connection->save();
        $this->calendar = Calendar::create([
            'name' => 'Microsoft ' . uniqid(), 'provider' => Calendar::PROVIDER_MICROSOFT, 'connection_id' => $connection->id,
            'remote_id' => self::CALENDAR, 'timezone' => self::ZONE, 'sync_interval' => 15,
        ]);
    }

    private static function fixture(string $name, array $changes = []): array
    {
        return GraphEventMapperTest::fixture($name, $changes);
    }

    private static function answer(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private static function graphError(int $status, string $code, string $message, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => $code, 'message' => $message]]));
    }

    /** One page of calendars/{id}/events with $select=id,changeKey,type. */
    private static function listing(array $events, ?string $next = null): Response
    {
        return self::answer(200, array_filter(['value' => array_map(function (array $event) {
            return ['@odata.etag' => 'W/"' . $event['changeKey'] . '"', 'id' => $event['id'], 'changeKey' => $event['changeKey'], 'type' => $event['type']];
        }, $events), '@odata.nextLink' => $next]));
    }

    /** The calendar's events filtered by one iCalUId: whether the event is still in this calendar. */
    private static function inCalendar(bool $there, string $id = 'AAMkADExampleThere0001AAA='): Response
    {
        return self::answer(200, ['value' => $there ? [['id' => $id]] : []]);
    }

    /** An answer of events/{id}/instances. */
    private static function instanceList(array $instances): Response
    {
        return self::answer(200, ['value' => array_values($instances)]);
    }

    /** One instance of the weekly series from the instances fixture, by the date of its original start. */
    private static function occurrence(string $date, array $changes = []): array
    {
        foreach (GraphEventMapperTest::instances() as $instance) {
            if (strncmp($instance['originalStart'], $date, 10) === 0) {
                return array_merge($instance, $changes);
            }
        }
        self::fail('No instance on ' . $date);
    }

    /** Each request as "METHOD path", relative to Graph, without the query and with the path decoded. */
    private function requests(): array
    {
        return array_map(function (array $request) {
            return $request['method'] . ' ' . rawurldecode(substr(strtok($request['url'], '?'), strlen(self::BASE)));
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

    private function pull(array $state = [], float $seconds = 30): ChangeSet
    {
        return $this->provider->pull($this->calendar, $state, SyncBudget::seconds($seconds));
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

    /** An event as an earlier sync stored it, an hour ago, with this change key. */
    private function storeEarlier(array $event, ?string $changeKey = null): CalendarEvent
    {
        $stored = app(EventImporter::class)->upsert($this->calendar, new RemoteEvent($event['id'], GraphEventMapper::toVCalendar($event, [], null, self::ZONE), null, $changeKey ?? $event['changeKey']), 0);
        DB::table('calendar_events')->where('id', $stored->id)->update(['updated_at' => date('Y-m-d H:i:s', time() - 3600)]);

        return $stored->fresh();
    }

    /** The weekly series as a sync stores it: listed, read, and read with its instances. */
    private function storeSeries(?array $instances = null): CalendarEvent
    {
        $this->http->queue(self::listing([self::fixture('series-master')]), self::answer(200, self::fixture('series-master')), self::instanceList($instances ?? GraphEventMapperTest::instances()));
        $this->sync();

        return $this->stored(self::SERIES);
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

    public function test_own_and_shared_mailbox_calendars_are_listed_with_their_rights(): void
    {
        $this->http->queue(
            self::answer(200, self::fixture('calendars')),
            self::answer(200, ['value' => [['id' => 'AAMkADExampleCalendar04AAA=', 'name' => 'Birthdays', 'hexColor' => '#e6a0ff', 'canEdit' => false, 'isDefaultCalendar' => false]]]),
            self::answer(200, ['value' => [['id' => 'AAMkADExampleShared01AAA=', 'name' => 'Calendar', 'hexColor' => '', 'canEdit' => true, 'isDefaultCalendar' => true]]])
        );

        $calendars = $this->provider->listCalendars($this->calendar->calendarConnection);
        $shared = $this->provider->listCalendars($this->calendar->calendarConnection, ' Support@Example.com ');

        $map = function (RemoteCalendar $calendar) {
            return [$calendar->remoteId, $calendar->name, $calendar->color, $calendar->timezone, $calendar->writable, $calendar->primary];
        };
        $this->assertSame([
            ['me/calendars/AAMkADExampleCalendar01AAA=', 'Calendar', null, null, true, true],
            ['me/calendars/AAMkADExampleCalendar02AAA=', 'Support rota', '#9FE1E7', null, true, false],
            ['me/calendars/AAMkADExampleCalendar03AAA=', "Colleague's calendar", null, null, false, false],
            ['me/calendars/AAMkADExampleCalendar04AAA=', 'Birthdays', '#e6a0ff', null, false, false],
        ], array_map($map, $calendars));
        $this->assertSame([['users/support@example.com/calendars/AAMkADExampleShared01AAA=', 'Calendar', null, null, true, true]], array_map($map, $shared));
        $this->assertSame(['GET me/calendars', 'GET me/calendars', 'GET users/support@example.com/calendars'], $this->requests());
        $this->assertSame(['$select' => 'id,name,hexColor,canEdit,isDefaultCalendar', '$top' => '100'], self::query($this->sent(0)));
        $this->assertSame(self::fixture('calendars')['@odata.nextLink'], $this->sent(1)['url']);
        $this->assertSame(self::BASE . 'users/support%40example.com/calendars?%24select=id%2Cname%2ChexColor%2CcanEdit%2CisDefaultCalendar&%24top=100', $this->sent(2)['url']);
    }

    public function test_a_shared_mailbox_the_account_cant_open_says_whose_it_is(): void
    {
        $this->http->queue(
            self::graphError(403, 'ErrorAccessDenied', 'Access is denied. Check credentials and try again.'),
            self::graphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.'),
            self::graphError(403, 'ErrorAccessDenied', 'Access is denied. Check credentials and try again.')
        );

        foreach ([403, 404] as $status) {
            $e = $this->failure(function () {
                $this->provider->listCalendars($this->calendar->calendarConnection, 'support@example.com');
            });
            $this->assertSame([ProviderUnavailable::class, "You don't have access to the calendars of support@example.com.", $status], [get_class($e), $e->userMessage(), $e->httpStatus()]);
        }
        // The account's own calendars get Graph's usual message.
        $this->assertSame('Microsoft refused this request. The account may not have access to this calendar, or may only read it.', $this->failure(function () {
            $this->provider->listCalendars($this->calendar->calendarConnection);
        })->userMessage());
        // Something that is no address never reaches Graph.
        $this->assertSame('Enter the email address of the shared mailbox.', $this->failure(function () {
            $this->provider->listCalendars($this->calendar->calendarConnection, 'support/../calendars');
        })->userMessage());
        $this->assertCount(3, $this->http->history());
    }

    public function test_a_listing_reads_new_and_changed_events_skips_unchanged_ones_and_sweeps_removed_ones_across_two_pages(): void
    {
        $unchanged = $this->storeEarlier(self::fixture('single'));
        $this->storeEarlier(self::fixture('all-day'), 'DwAAABYAAABExampleAllDayKey00');
        $removed = $this->storeEarlier(self::fixture('private'));
        $page2 = self::BASE . 'me/calendars/AAMkADExampleCalendar01AAA%3D/events?%24select=id%2CchangeKey%2Ctype&%24top=100&%24skip=100';
        $this->http->queue(
            self::listing([self::fixture('single'), self::fixture('all-day')], $page2),
            self::answer(200, self::fixture('all-day', ['subject' => 'Trade fair (day 1 to 3)'])),
            self::listing([self::fixture('series-master')]),
            self::answer(200, self::fixture('series-master')),
            self::instanceList(GraphEventMapperTest::instances()),
            // Before the sweep deletes the event the listing left out, Microsoft confirms it is no longer in the calendar.
            self::inCalendar(false)
        );

        // No budget left after the first page: the run stops there and the next one continues.
        $this->sync(0);

        $this->assertSame(['next_link' => $page2, 'rereading' => true], array_intersect_key($this->calendar->sync_state, ['next_link' => 1, 'rereading' => 1]));
        $this->assertTrue($this->calendar->sync_state['_listing']);
        $this->assertNull($this->calendar->last_synced_at);
        $this->assertSame(['Trade fair (day 1 to 3)', 'DwAAABYAAABExampleAllDayKey01'], [$this->stored(self::ALL_DAY)->summary, $this->stored(self::ALL_DAY)->etag]);
        $this->assertNotNull(CalendarEvent::find($removed->id));

        $this->sync();

        $this->assertSame([
            'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::ALL_DAY,
            'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES, 'GET ' . self::EVENTS . '/' . self::SERIES . '/instances',
            'GET ' . self::EVENTS,
        ], $this->requests());
        $this->assertSame(['$select' => 'id,changeKey,type', '$top' => '100'], self::query($this->sent(0)));
        $this->assertSame($page2, $this->sent(2)['url']);
        $this->assertSame(['$filter' => "iCalUId eq '" . self::fixture('private')['iCalUId'] . "'", '$select' => 'id', '$top' => '1'], self::query($this->sent(5)));
        $this->assertSame('id,changeKey,iCalUId,type,subject,body,location,start,end,originalStartTimeZone,originalEndTimeZone,isAllDay,isCancelled,isOrganizer,sensitivity,showAs,organizer,attendees,recurrence,lastModifiedDateTime', self::query($this->sent(1))['$select']);
        $this->assertSame(['reread_from' => '2025-10-05'], array_diff_key($this->calendar->sync_state, array_flip(['_generation', '_listing', '_seen', '_listing_started', '_empty_listings'])));
        $this->assertFalse($this->calendar->sync_state['_listing']);
        $this->assertNotNull($this->calendar->last_synced_at);
        // Unchanged: kept as it was; gone from the listing: swept.
        $this->assertSame([$unchanged->id, $unchanged->ics], [$this->stored(self::SINGLE)->id, $this->stored(self::SINGLE)->ics]);
        $this->assertNull(CalendarEvent::find($removed->id));
        $series = $this->stored(self::SERIES);
        $this->assertSame(['DwAAABYAAABExampleSeriesKey01', null, false, 'Weekly planning'], [$series->etag, $series->remote_href, $series->read_only, $series->summary]);
        $this->assertSame(['exdates' => ['20261019T080000Z'], 'overrides' => ['20261026T090000Z']], self::exceptions($series->ics));
        $this->assertSame(3, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
    }

    public function test_an_event_left_out_of_a_listing_is_only_swept_once_microsoft_says_it_is_gone(): void
    {
        // Graph pages these lists by offset ($skip): an event deleted while a listing runs shifts another one out of it.
        $shifted = $this->storeEarlier(self::fixture('all-day'));
        $deleted = $this->storeEarlier(self::fixture('private'));
        // An event deleted in Outlook moves to Deleted Items and keeps its immutable id, so a GET by id still answers: the
        // calendar's own list, filtered by the event's iCalUId, says whether it is still in this calendar.
        $byUid = function (RequestInterface $request) {
            return self::inCalendar(strpos(urldecode((string) $request->getUri()), self::fixture('all-day')['iCalUId']) !== false, self::ALL_DAY);
        };
        $this->http->queue(self::listing([self::fixture('single')]), self::answer(200, self::fixture('single')), $byUid, $byUid);

        $this->sync();

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SINGLE, 'GET ' . self::EVENTS, 'GET ' . self::EVENTS], $this->requests());
        $this->assertNull(CalendarEvent::find($deleted->id));
        // Kept, as seen in this listing: the next listing that leaves it out asks about it again.
        $this->assertSame($this->calendar->sync_state['_generation'], CalendarEvent::find($shifted->id)->sync_generation);
        $this->assertNull($this->calendar->last_error);
    }

    public function test_events_left_unchecked_when_the_budget_runs_out_are_kept_for_the_next_sweep(): void
    {
        $ids = [];
        foreach (range(1, MicrosoftProvider::MIN_CONFIRMATIONS + 2) as $number) {
            $ids[] = $this->storeEarlier(self::fixture('single', ['id' => 'AAMkADExampleGone000' . $number . 'AAA=', 'iCalUId' => 'gone-' . $number . '@example.com']))->remote_id;
        }
        $this->storeEarlier(self::fixture('all-day'));
        $gone = self::inCalendar(false);
        $this->http->queue(self::listing([self::fixture('all-day')]));
        foreach (range(1, MicrosoftProvider::MIN_CONFIRMATIONS) as $number) {
            $this->http->queue($gone);
        }

        // No budget: the sweep still checks MIN_CONFIRMATIONS events, and keeps the others.
        $this->sync(0);

        $this->assertCount(1 + MicrosoftProvider::MIN_CONFIRMATIONS, $this->http->history());
        // They are asked about in a shuffled order, so events that are never listed don't always go first.
        $this->assertSame(2, CalendarEvent::where('calendar_id', $this->calendar->id)->whereIn('remote_id_hash', array_map('sha1', $ids))->count());

        $this->http->queue(self::listing([self::fixture('all-day')]), $gone, $gone);
        $this->sync();
        $this->assertSame(0, $this->http->pending());
        $this->assertSame([self::ALL_DAY], CalendarEvent::where('calendar_id', $this->calendar->id)->pluck('remote_id')->all());
    }

    public function test_a_check_microsoft_cant_answer_ends_the_checks_but_not_the_round(): void
    {
        $first = $this->storeEarlier(self::fixture('all-day'));
        $second = $this->storeEarlier(self::fixture('private'));
        $this->http->queue(self::listing([self::fixture('single')]), self::answer(200, self::fixture('single')));
        foreach (range(1, 4) as $attempt) {
            $this->http->queue(self::graphError(429, 'TooManyRequests', 'Please retry again later.'));
        }

        $this->sync();

        // The throttled check stops the checks; both events stay for the next sweep, and the round is saved.
        $this->assertCount(6, $this->http->history());
        $this->assertNotNull(CalendarEvent::find($first->id));
        $this->assertNotNull(CalendarEvent::find($second->id));
        $this->assertNull($this->calendar->last_error);
        $this->assertFalse($this->calendar->sync_state['_listing']);
        $this->assertSame('2025-10-05', $this->calendar->sync_state['reread_from']);
        $this->assertNotNull($this->stored(self::SINGLE));
    }

    public function test_an_event_without_an_ical_uid_is_checked_by_id_and_an_answer_that_says_nothing_keeps_it(): void
    {
        // The mapper falls back to the Graph id when an event has no iCalUId: then only a 404 or 410 says it is gone.
        $withoutUid = $this->storeEarlier(self::fixture('all-day', ['iCalUId' => null]));
        $refused = $this->storeEarlier(self::fixture('private'));
        $this->assertSame(self::ALL_DAY, $withoutUid->uid);
        $answer = function (RequestInterface $request) {
            return strpos((string) $request->getUri(), '%24filter') !== false
                ? self::graphError(403, 'ErrorAccessDenied', 'Access is denied. Check credentials and try again.')
                : self::graphError(410, 'ErrorItemNotFound', 'The specified object was not found in the store.');
        };
        $this->http->queue(self::listing([self::fixture('single')]), self::answer(200, self::fixture('single')), $answer, $answer);

        $this->sync();

        $this->assertNull(CalendarEvent::find($withoutUid->id));
        $this->assertNotNull(CalendarEvent::find($refused->id));
        $this->assertSame(0, $this->http->pending());
    }

    public function test_an_event_listed_twice_in_one_round_is_read_once(): void
    {
        $page2 = self::BASE . 'me/calendars/AAMkADExampleCalendar01AAA%3D/events?%24select=id%2CchangeKey%2Ctype&%24top=100&%24skip=100';
        // An event added between two page requests shifts the single event onto the second page as well.
        $this->http->queue(
            self::listing([self::fixture('single')], $page2), self::answer(200, self::fixture('single')),
            self::listing([self::fixture('single'), self::fixture('all-day')]), self::answer(200, self::fixture('all-day'))
        );

        $this->sync();

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SINGLE, 'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::ALL_DAY], $this->requests());
    }

    public function test_a_next_link_that_repeats_ends_the_sync_with_an_error_instead_of_paging_forever(): void
    {
        $page2 = self::BASE . 'me/calendars/AAMkADExampleCalendar01AAA%3D/events?%24select=id%2CchangeKey%2Ctype&%24top=100&%24skiptoken=x';
        $this->http->queue(self::listing([], $page2), self::listing([], $page2));

        $this->sync();

        $this->assertSame('Microsoft returned the same page of events again, so the calendar could not be listed to its end.', $this->calendar->last_error);
        $this->assertCount(2, $this->http->history());
    }

    public function test_a_budget_stop_inside_a_page_lists_that_page_again(): void
    {
        $page = self::listing([self::fixture('single'), self::fixture('all-day')]);
        $this->http->queue($page, self::answer(200, self::fixture('single')), $page, self::answer(200, self::fixture('single')), $page, self::answer(200, self::fixture('all-day')));

        $first = $this->pull([], 0);

        // At least one event is read per round, so a run always gets further.
        $this->assertSame([[self::SINGLE], ['rereading' => true, 'page_read' => [self::SINGLE => 'DwAAABYAAABExampleSingleKey01']], false, true, []], [array_map(function (RemoteEvent $remote) {
            return $remote->remoteId;
        }, $first->upserts), $first->state, $first->complete, $first->fullListing, $first->unchanged]);

        $this->sync(0);
        $this->sync();

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SINGLE, 'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::ALL_DAY], array_slice($this->requests(), 2));
        $this->assertSame(0, $this->http->pending());
        $this->assertFalse($this->calendar->sync_state['_listing']);
        $this->assertNotNull($this->stored(self::ALL_DAY));
    }

    public function test_a_budget_stop_in_a_pass_that_reads_every_series_reads_none_of_that_page_twice(): void
    {
        $second = self::fixture('series-master', [
            'id'        => 'AAMkADExampleSeries0002AAA=', 'changeKey' => 'DwAAABYAAABExampleSeriesKeyB1', 'subject' => 'Weekly review',
            'iCalUId'   => '040000008200E00074C5B7101A82E00800000000000000000000000000000000000000000000000000000000000004',
        ]);
        $page = self::listing([self::fixture('series-master'), $second]);
        $instances = self::instanceList(GraphEventMapperTest::instances());
        // A first sync reads every series. Each round below stops once it has read one event.
        $this->http->queue($page, self::answer(200, self::fixture('series-master')), $instances, $page, self::answer(200, $second), $instances);

        $this->sync(0);
        $this->assertSame([self::SERIES => 'DwAAABYAAABExampleSeriesKey01'], $this->calendar->sync_state['page_read']);
        $this->sync(0);

        $this->assertSame([
            'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES, 'GET ' . self::EVENTS . '/' . self::SERIES . '/instances',
            'GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . $second['id'], 'GET ' . self::EVENTS . '/' . $second['id'] . '/instances',
        ], $this->requests());
        $this->assertSame(0, $this->http->pending());
        $this->assertFalse($this->calendar->sync_state['_listing']);
        $this->assertSame('2025-10-05', $this->calendar->sync_state['reread_from']);
        $this->assertArrayNotHasKey('page_read', $this->calendar->sync_state);
        $this->assertSame(['Weekly planning', 'Weekly review'], [$this->stored(self::SERIES)->summary, $this->stored($second['id'])->summary]);
    }

    public function test_a_series_is_read_with_its_instances_in_the_occurrence_window(): void
    {
        $series = $this->storeSeries();

        $this->assertSame([
            'startDateTime' => '2025-10-05T00:00:00', 'endDateTime' => '2028-10-05T00:00:00',
            '$select' => 'id,changeKey,type,originalStart,seriesMasterId,subject,body,location,start,end,isAllDay,isCancelled,sensitivity,showAs,lastModifiedDateTime',
            '$top' => '100',
        ], self::query($this->sent(2)));
        $this->assertSame(['exdates' => ['20261019T080000Z'], 'overrides' => ['20261026T090000Z']], self::exceptions($series->ics));
        $this->assertSame(5, $series->occurrences()->count());
        $this->assertSame('Weekly planning (moved)', $series->occurrences()->where('recurrence_id', '20261026T090000Z')->value('summary'));

        // An unchanged series is not read again.
        $this->http->queue(self::listing([self::fixture('series-master')]));
        $this->sync();
        $this->assertCount(4, $this->http->history());
    }

    public function test_every_series_is_read_again_once_a_week(): void
    {
        $listing = self::listing([self::fixture('single'), self::fixture('series-master')]);
        $this->http->queue($listing, self::answer(200, self::fixture('single')), self::answer(200, self::fixture('series-master')), self::instanceList(GraphEventMapperTest::instances()));
        $this->sync();
        $this->assertSame('2025-10-05', $this->calendar->sync_state['reread_from']);
        $this->http->queue($listing);
        $this->sync();
        $this->assertCount(5, $this->http->history());

        // A week later the occurrence window has moved on: a series is read again although its change key is the same
        // (that an edited occurrence changes it is UNVERIFIED for Graph), and the occurrences that came into the window
        // are checked against Graph. Single events are not read again.
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-12', new DateTimeZone('UTC')));
        $this->http->queue(self::listing([self::fixture('single'), self::fixture('series-master')]), self::answer(200, self::fixture('series-master')), self::instanceList(GraphEventMapperTest::instances()));
        $this->sync();

        $this->assertSame(['GET ' . self::EVENTS, 'GET ' . self::EVENTS . '/' . self::SERIES, 'GET ' . self::EVENTS . '/' . self::SERIES . '/instances'], array_slice($this->requests(), 5));
        $this->assertSame('2025-10-12', $this->calendar->sync_state['reread_from']);
        $this->http->queue($listing);
        $this->sync();
        $this->assertCount(9, $this->http->history());
    }

    public function test_instances_are_read_page_by_page_and_a_series_that_is_cut_off_infers_no_deletions(): void
    {
        $instances = GraphEventMapperTest::instances();
        $next = self::BASE . 'me/calendars/AAMkADExampleCalendar01AAA%3D/events/AAMkADExampleSeries0001AAA%3D/instances?%24skip=3';
        $this->http->queue(
            self::listing([self::fixture('series-master')]), self::answer(200, self::fixture('series-master')),
            self::answer(200, ['value' => array_slice($instances, 0, 3), '@odata.nextLink' => $next]), self::instanceList(array_slice($instances, 2))
        );

        $this->sync();

        // An instance listed twice counts once.
        $this->assertSame($next, $this->sent(3)['url']);
        $this->assertSame(['exdates' => ['20261019T080000Z'], 'overrides' => ['20261026T090000Z']], self::exceptions($this->stored(self::SERIES)->ics));

        // More pages than FreeScout reads: what was read counts, but nothing is taken as deleted.
        $logs = LogRecorder::install();
        $page = self::answer(200, ['value' => [self::occurrence('2026-10-05')], '@odata.nextLink' => $next]);
        $this->http->queue(self::listing([self::fixture('series-master', ['changeKey' => 'DwAAABYAAABExampleSeriesKey02'])]), self::answer(200, self::fixture('series-master', ['changeKey' => 'DwAAABYAAABExampleSeriesKey02'])));
        foreach (range(1, MicrosoftProvider::MAX_INSTANCE_PAGES) as $attempt) {
            $this->http->queue($page);
        }
        $this->sync();
        $this->assertSame(['exdates' => [], 'overrides' => []], self::exceptions($this->stored(self::SERIES)->ics));
        $this->assertSame('A Microsoft series has more occurrences than FreeScout reads; none of them is taken as deleted', $logs->records('warning')[0]['message']);
    }

    public function test_a_saved_page_link_microsoft_refuses_starts_the_listing_over(): void
    {
        $saved = self::BASE . 'me/calendars/AAMkADExampleCalendar01AAA%3D/events?%24select=id%2CchangeKey%2Ctype&%24top=100&%24skiptoken=expired';
        $first = self::BASE . 'me/calendars/AAMkADExampleCalendar01AAA%3D/events?%24select=id%2CchangeKey%2Ctype&%24top=100';
        $this->http->queue(
            self::graphError(400, 'ErrorInvalidRequest', 'The skip token is invalid.'), self::listing([]),
            self::graphError(410, 'SyncStateNotFound', 'The sync state is no longer available.'), self::listing([])
        );

        foreach ([0, 2] as $request) {
            $changes = $this->pull(['next_link' => $saved, 'rereading' => false, 'reread_from' => '2025-10-05']);

            $this->assertSame([$saved, $first], array_column(array_slice($this->http->history(), $request, 2), 'url'));
            $this->assertSame([[], ['reread_from' => '2025-10-05'], true, true], [$changes->upserts, $changes->state, $changes->complete, $changes->fullListing]);
        }
    }

    public function test_a_calendar_microsoft_wont_list_names_the_likely_reason(): void
    {
        // Graph answers 404 for a calendar with private events the account may not read (calendar-list-events reference).
        $this->http->queue(self::graphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.'));

        $this->sync();

        $this->assertSame('Microsoft could not list the events of this calendar. It may have been deleted, or it holds private events this account may not see. (HTTP 404)', $this->calendar->last_error);
        $this->assertSame(1, $this->calendar->sync_failures);
    }

    public function test_invitations_are_read_only_and_private_events_busy(): void
    {
        $invitation = self::fixture('single', ['isOrganizer' => false]);
        $this->http->queue(self::listing([$invitation, self::fixture('private')]), self::answer(200, $invitation), self::answer(200, self::fixture('private')));

        $this->sync();

        $single = $this->stored(self::SINGLE);
        $private = $this->stored('AAMkADExamplePrivate0001AAA=');
        $this->assertSame([true, 'Intake call with Example Corp', false], [$single->read_only, $single->summary, $single->is_private]);
        $this->assertSame([true, 'Busy', true], [$private->read_only, $private->summary, $private->is_private]);
        $this->assertStringNotContainsString('referral', $private->ics);
    }

    public function test_events_that_cant_be_read_keep_their_copy_and_are_not_read_again_until_they_change(): void
    {
        $gone = $this->storeEarlier(self::fixture('all-day'), 'DwAAABYAAABExampleAllDayKey00');
        $series = $this->storeEarlier(self::fixture('series-master'), 'DwAAABYAAABExampleSeriesKey00');
        $logs = LogRecorder::install();
        $refused = self::fixture('private');
        $this->http->queue(
            self::listing([self::fixture('all-day'), self::fixture('series-master'), $refused, self::fixture('single')]),
            // Gone after the listing: kept for now; the next listing decides.
            self::graphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.'),
            // Its instances are refused: the series keeps its stored copy.
            self::answer(200, self::fixture('series-master')), self::graphError(403, 'ErrorAccessDenied', 'Access is denied. Check credentials and try again.'),
            // The event itself is refused: it is skipped, and the rest of the calendar syncs on.
            self::graphError(403, 'ErrorAccessDenied', 'Access is denied. Check credentials and try again.'),
            self::answer(200, self::fixture('single'))
        );

        $this->sync();

        $this->assertNotNull(CalendarEvent::find($gone->id));
        $this->assertSame([$series->ics, 'DwAAABYAAABExampleSeriesKey00'], [$this->stored(self::SERIES)->ics, $this->stored(self::SERIES)->etag]);
        $this->assertNull($this->stored($refused['id']));
        $this->assertNotNull($this->stored(self::SINGLE));
        $this->assertNull($this->calendar->last_error);
        $this->assertSame([self::SERIES => 'DwAAABYAAABExampleSeriesKey01', $refused['id'] => $refused['changeKey']], $this->calendar->sync_state['skipped']);
        $this->assertSame(2, count(array_keys(array_column($logs->records('warning'), 'message'), 'A pulled calendar event was skipped')));

        // The next listing: the gone event is not listed and is swept; what was skipped isn't read again with the same
        // change key, and is read again once it changes.
        $this->http->queue(self::listing([self::fixture('series-master'), $refused, self::fixture('single')]), self::inCalendar(false));
        $this->sync();
        $this->assertCount(8, $this->http->history());
        $this->assertNull(CalendarEvent::find($gone->id));
        $changed = ['changeKey' => 'DwAAABYAAABExamplePrivateKey02'] + $refused;
        $this->http->queue(self::listing([self::fixture('series-master'), $changed, self::fixture('single')]), self::answer(200, $changed));
        $this->sync();
        $this->assertSame('GET ' . self::EVENTS . '/' . $refused['id'], $this->requests()[9]);
        $this->assertNotNull($this->stored($refused['id']));
    }

    public function test_a_new_event_is_created_and_takes_microsofts_uid(): void
    {
        $vcal = EventIcs::build([
            'uid' => 'new-event@freescout.example.com', 'title' => 'Onboarding call', 'all_day' => false, 'location' => 'Online', 'description' => 'Welcome.',
            'start' => new DateTimeImmutable('2026-10-20 09:00', new DateTimeZone(self::ZONE)), 'end' => new DateTimeImmutable('2026-10-20 09:45', new DateTimeZone(self::ZONE)),
            'rrule' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=3',
        ]);
        $created = ['id' => 'AAMkADExampleNew0001AAA=', 'changeKey' => 'DwAAABYAAABExampleNewKey01', 'iCalUId' => '040000008200E00074C5B7101A82E0080000000000000000000000000000000000000000000000000000000000000A', 'type' => 'seriesMaster'];
        $this->http->queue(self::answer(201, $created), self::answer(201, $created));

        $ref = $this->provider->create($this->calendar, $vcal);

        $this->assertSame([$created['id'], null, $created['changeKey'], $created['iCalUId']], [$ref->remoteId, $ref->href, $ref->etag, $ref->uid]);
        $this->assertSame(['POST ' . self::EVENTS], $this->requests());
        $body = self::body($this->sent(0));
        $this->assertSame(32, strlen($body['transactionId']));
        unset($body['transactionId']);
        $this->assertSame([
            'subject'    => 'Onboarding call',
            'body'       => ['contentType' => 'text', 'content' => 'Welcome.'],
            'location'   => ['displayName' => 'Online'],
            'start'      => ['dateTime' => '2026-10-20T09:00:00', 'timeZone' => 'W. Europe Standard Time'],
            'end'        => ['dateTime' => '2026-10-20T09:45:00', 'timeZone' => 'W. Europe Standard Time'],
            'isAllDay'   => false,
            'recurrence' => [
                'pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['tuesday'], 'firstDayOfWeek' => 'monday'],
                'range'   => ['type' => 'numbered', 'startDate' => '2026-10-20', 'numberOfOccurrences' => 3],
            ],
        ], $body);
        // Each create gets its own transaction id, so Graph never takes a second event for a retry of the first.
        $this->provider->create($this->calendar, $vcal);
        $this->assertNotSame(self::body($this->sent(0))['transactionId'], self::body($this->sent(1))['transactionId']);
    }

    public function test_a_create_microsoft_answers_with_a_412_is_sent_once_more_under_the_same_transaction(): void
    {
        $vcal = EventIcs::build(['uid' => 'new-event@freescout.example.com', 'title' => 'Onboarding call', 'all_day' => true, 'start' => '2026-10-20', 'end' => '2026-10-21']);
        $created = ['id' => 'AAMkADExampleNew0001AAA=', 'changeKey' => 'DwAAABYAAABExampleNewKey01', 'iCalUId' => '040000008200E00074C5B7101A82E0080000000000000000000000000000000000000000000000000000000000000A'];
        // Exchange answers ErrorIrresolvableConflict to a create now and then (research question 3).
        $this->http->queue(self::graphError(412, 'ErrorIrresolvableConflict', 'The change key passed in the request does not match the current change key for the item.'), self::answer(201, $created));

        $ref = $this->provider->create($this->calendar, $vcal);

        $this->assertSame($created['id'], $ref->remoteId);
        $this->assertSame(['POST ' . self::EVENTS, 'POST ' . self::EVENTS], $this->requests());
        $this->assertSame(self::body($this->sent(0)), self::body($this->sent(1)));
    }

    public function test_an_agents_new_event_is_stored_under_microsofts_uid(): void
    {
        $created = ['id' => 'AAMkADExampleNew0001AAA=', 'changeKey' => 'DwAAABYAAABExampleNewKey01', 'iCalUId' => '040000008200E00074C5B7101A82E0080000000000000000000000000000000000000000000000000000000000000A'];
        $this->http->queue(self::answer(201, $created));

        $event = app(EventService::class)->create($this->calendar, $this->makeUser(['timezone' => self::ZONE]), ['title' => 'Onboarding call', 'start' => '2026-10-20T09:00', 'end' => '2026-10-20T09:45']);

        $this->assertSame([$created['iCalUId'], $created['id'], $created['changeKey']], [$event->uid, $event->remote_id, $event->etag]);
        $this->assertStringContainsString('UID:' . substr($created['iCalUId'], 0, 60), $event->ics);
    }

    public function test_a_series_change_checks_the_change_key_and_sends_only_what_changed(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        EventIcs::master($vcal, $event->uid)->SUMMARY = 'Weekly planning with Example Corp';
        $current = self::fixture('series-master');
        $saved = ['changeKey' => 'DwAAABYAAABExampleSeriesKey02'] + $current;
        $this->http->queue(self::answer(200, $current), self::answer(200, $saved));

        $ref = $this->provider->update($this->calendar, $event, $vcal);

        // A series write is read back at the next sync (what Outlook made of it), so its change key is marked.
        $this->assertSame([self::SERIES, null, MicrosoftProvider::REREAD . 'DwAAABYAAABExampleSeriesKey02', null], [$ref->remoteId, $ref->href, $ref->etag, $ref->uid]);
        $path = self::EVENTS . '/' . self::SERIES;
        $this->assertSame(['GET ' . $path, 'PATCH ' . $path], array_slice($this->requests(), 3));
        $this->assertSame(['$select' => 'id,changeKey,subject,body,location,sensitivity,start,end,isAllDay,recurrence,originalStartTimeZone,attendees'], self::query($this->sent(3)));
        // Only what changes is sent: Graph mails a meeting's attendees on every update, and Outlook may reset a series'
        // exceptions when its times or rule are set.
        $this->assertSame(['subject' => 'Weekly planning with Example Corp'], self::body($this->sent(4)));
        $this->assertArrayNotHasKey('If-Match', $this->sent(4)['headers']);

        // Nothing that changes: nothing is written, and the stored change key stays.
        $this->http->queue(self::answer(200, $current));
        $this->assertSame('DwAAABYAAABExampleSeriesKey01', $this->provider->update($this->calendar, $event, Reader::read($event->ics))->etag);
        $this->assertCount(6, $this->http->history());
    }

    public function test_a_series_schedule_change_sends_the_times_and_rule_that_change(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        $master = EventIcs::master($vcal, $event->uid);
        EventIcs::dropExceptions($vcal, $master);
        EventIcs::apply($master, ['all_day' => false, 'start' => new DateTimeImmutable('2026-10-05 11:00', new DateTimeZone(self::ZONE)), 'end' => new DateTimeImmutable('2026-10-05 12:00', new DateTimeZone(self::ZONE)), 'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=8']);
        $this->http->queue(self::answer(200, self::fixture('series-master')), self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSeriesKey02'] + self::fixture('series-master')));

        $ref = $this->provider->update($this->calendar, $event, $vcal);

        $body = self::body($this->sent(4));
        $this->assertSame(['start', 'end', 'recurrence'], array_keys($body));
        $this->assertSame(['dateTime' => '2026-10-05T11:00:00', 'timeZone' => 'W. Europe Standard Time'], $body['start']);
        $this->assertSame(['type' => 'numbered', 'startDate' => '2026-10-05', 'numberOfOccurrences' => 8], $body['recurrence']['range']);
        $this->assertSame(MicrosoftProvider::REREAD . 'DwAAABYAAABExampleSeriesKey02', $ref->etag);
    }

    public function test_a_longer_series_sends_its_end_only(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        EventIcs::apply(EventIcs::master($vcal, $event->uid), ['all_day' => false, 'start' => new DateTimeImmutable('2026-10-05 10:00', new DateTimeZone(self::ZONE)), 'end' => new DateTimeImmutable('2026-10-05 11:30', new DateTimeZone(self::ZONE))]);
        $this->http->queue(self::answer(200, self::fixture('series-master')), self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSeriesKey02'] + self::fixture('series-master')));

        $this->provider->update($this->calendar, $event, $vcal);

        // UNVERIFIED whether Outlook keeps a series' exceptions when only its end changes (FreeScout keeps them).
        $this->assertSame(['end' => ['dateTime' => '2026-10-05T11:30:00', 'timeZone' => 'W. Europe Standard Time']], self::body($this->sent(4)));
    }

    public function test_changes_made_in_outlook_first_are_conflicts(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        EventIcs::master($vcal, $event->uid)->SUMMARY = 'Weekly planning with Example Corp';
        $cases = [
            'changed' => [self::answer(200, self::fixture('series-master', ['changeKey' => 'DwAAABYAAABExampleSeriesKey99']))],
            'deleted' => [self::graphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.')],
            'race'    => [self::answer(200, self::fixture('series-master')), self::graphError(412, 'ErrorIrresolvableConflict', 'The change key passed in the request does not match the current change key for the item.')],
        ];
        foreach ($cases as $case => $answers) {
            $this->http->queue(...$answers);

            $this->assertInstanceOf(ProviderConflict::class, $this->failure(function () use ($event, $vcal) {
                $this->provider->update($this->calendar, $event, $vcal);
            }), $case);
        }
        $this->assertSame(0, $this->http->pending());
        $this->assertSame(['PATCH ' . self::EVENTS . '/' . self::SERIES], array_values(array_filter($this->requests(), function (string $request) {
            return strncmp($request, 'PATCH', 5) === 0;
        })));
    }

    public function test_a_series_waiting_to_be_read_again_still_catches_changes_made_in_outlook(): void
    {
        $event = $this->storeSeries();
        $event->etag = MicrosoftProvider::REREAD . 'DwAAABYAAABExampleSeriesKey01';
        $event->save();
        $vcal = Reader::read($event->ics);
        EventIcs::master($vcal, $event->uid)->SUMMARY = 'Weekly planning with Example Corp';
        $this->http->queue(
            self::answer(200, self::fixture('series-master', ['changeKey' => 'DwAAABYAAABExampleSeriesKey02'])),
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey02']),
            self::answer(200, self::fixture('series-master')), self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSeriesKey03'] + self::fixture('series-master'))
        );

        $this->assertInstanceOf(ProviderConflict::class, $this->failure(function () use ($event, $vcal) {
            $this->provider->update($this->calendar, $event, $vcal);
        }));
        $this->assertInstanceOf(ProviderConflict::class, $this->failure(function () use ($event) {
            $this->provider->delete($this->calendar, $event);
        }));
        $this->assertSame(MicrosoftProvider::REREAD . 'DwAAABYAAABExampleSeriesKey03', $this->provider->update($this->calendar, $event, $vcal)->etag);

        // The marked change key never equals a listed one: the next sync reads the series again.
        $this->http->queue(self::listing([self::fixture('series-master')]), self::answer(200, self::fixture('series-master')), self::instanceList(GraphEventMapperTest::instances()));
        $this->sync();
        $this->assertSame('DwAAABYAAABExampleSeriesKey01', $this->stored(self::SERIES)->etag);
        $this->assertSame(0, $this->http->pending());
    }

    public function test_an_edited_occurrence_is_written_to_its_instance(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        $tz = new DateTimeZone(self::ZONE);
        $override = EventIcs::addOverride($vcal, EventIcs::master($vcal, $event->uid), '20261102T090000Z', $tz);
        EventIcs::apply($override, ['title' => 'Planning with Example Corp', 'all_day' => false, 'start' => new DateTimeImmutable('2026-11-02 14:00', $tz), 'end' => new DateTimeImmutable('2026-11-02 15:00', $tz)]);
        $this->http->queue(
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey01']),
            self::instanceList([self::occurrence('2026-11-02')]),
            self::answer(200, self::occurrence('2026-11-02', ['type' => 'exception', 'changeKey' => 'DwAAABYAAABExampleSeriesKey02'])),
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey02'])
        );

        $ref = $this->provider->update($this->calendar, $event, $vcal, '20261102T090000Z');

        $series = self::EVENTS . '/' . self::SERIES;
        $instance = self::occurrence('2026-11-02')['id'];
        $this->assertSame(['GET ' . $series, 'GET ' . $series . '/instances', 'PATCH ' . self::EVENTS . '/' . $instance, 'GET ' . $series], array_slice($this->requests(), 3));
        $this->assertSame(['$select' => 'id,changeKey'], self::query($this->sent(3)));
        $this->assertSame(['startDateTime' => '2026-11-01T09:00:00', 'endDateTime' => '2026-11-04T09:00:00', '$select' => 'id,type,originalStart,isCancelled,subject,body,location,sensitivity,start,end,isAllDay,attendees', '$top' => '100'], self::query($this->sent(4)));
        $this->assertSame([
            'subject' => 'Planning with Example Corp',
            'start'   => ['dateTime' => '2026-11-02T14:00:00', 'timeZone' => 'W. Europe Standard Time'],
            'end'     => ['dateTime' => '2026-11-02T15:00:00', 'timeZone' => 'W. Europe Standard Time'],
        ], self::body($this->sent(5)));
        // The series' change key moves with its occurrences (they share it): the new one is stored, so the next series
        // change is not taken for a change made elsewhere.
        $this->assertSame([self::SERIES, 'DwAAABYAAABExampleSeriesKey02'], [$ref->remoteId, $ref->etag]);
    }

    public function test_a_deleted_occurrence_deletes_its_instance(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        EventIcs::addExdate(EventIcs::master($vcal, $event->uid), '20261109T090000Z', new DateTimeZone(self::ZONE));
        $key = ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey01'];
        $logs = LogRecorder::install();
        $this->http->queue(
            self::answer(200, $key), self::instanceList([self::occurrence('2026-11-02'), self::occurrence('2026-11-09')]), new Response(204), self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSeriesKey02'] + $key),
            // Gone already: nothing left to delete, and the series' change key stays.
            self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSeriesKey02'] + $key), self::instanceList([])
        );

        $ref = $this->provider->update($this->calendar, $event, $vcal, '20261109T090000Z');
        $event->etag = $ref->etag;
        $again = $this->provider->update($this->calendar, $event, $vcal, '20261109T090000Z');

        $series = self::EVENTS . '/' . self::SERIES;
        $this->assertSame([
            'GET ' . $series, 'GET ' . $series . '/instances', 'DELETE ' . self::EVENTS . '/' . self::occurrence('2026-11-09')['id'], 'GET ' . $series,
            'GET ' . $series, 'GET ' . $series . '/instances',
        ], array_slice($this->requests(), 3));
        $this->assertSame(['DwAAABYAAABExampleSeriesKey02', 'DwAAABYAAABExampleSeriesKey02'], [$ref->etag, $again->etag]);
        $this->assertSame('A deleted occurrence has no instance at Microsoft; nothing was deleted there', $logs->records('info')[0]['message']);
        // A key that is neither an override nor an EXDATE is a mistake of the caller, never a deletion.
        $this->expectException(\LogicException::class);
        $this->provider->update($this->calendar, $event, $vcal, '20261102T090000Z');
    }

    public function test_an_occurrence_of_an_all_day_series_is_found_by_its_date(): void
    {
        $series = self::fixture('series-master', [
            'isAllDay' => true, 'start' => ['dateTime' => '2026-10-05T00:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-06T00:00:00.0000000', 'timeZone' => 'UTC'],
        ]);
        // Graph's original starts as the midnights of the series' zone (one of the two forms the mapper reads).
        $instances = [];
        foreach (['2026-10-04T22', '2026-10-11T22', '2026-10-18T22', '2026-10-25T23', '2026-11-01T23', '2026-11-08T23'] as $index => $hour) {
            $instances[] = ['id' => 'AAMkADExampleAllDaySeries' . $index, 'type' => 'occurrence', 'originalStart' => $hour . ':00:00Z', 'isCancelled' => false];
        }
        $this->http->queue(self::listing([$series]), self::answer(200, $series), self::instanceList($instances));
        $this->sync();
        $event = $this->stored(self::SERIES);
        $this->assertSame('DTSTART;VALUE=DATE:20261005', trim(Reader::read($event->ics)->VEVENT->DTSTART->serialize()));
        $vcal = Reader::read($event->ics);
        EventIcs::addExdate(EventIcs::master($vcal, $event->uid), '20261012', new DateTimeZone(self::ZONE));
        $this->http->queue(self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey01']), self::instanceList([$instances[1], $instances[2]]), new Response(204), self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey02']));

        $this->provider->update($this->calendar, $event, $vcal, '20261012');

        $this->assertSame(['startDateTime' => '2026-10-11T00:00:00', 'endDateTime' => '2026-10-14T00:00:00'], array_intersect_key(self::query($this->sent(4)), ['startDateTime' => 1, 'endDateTime' => 1]));
        $this->assertSame('DELETE ' . self::EVENTS . '/AAMkADExampleAllDaySeries1', $this->requests()[5]);
    }

    public function test_an_occurrence_write_keeps_a_pending_read_of_the_series(): void
    {
        $event = $this->storeSeries();
        // A series write marked the change key: the next sync reads the series again, also after this occurrence write.
        $event->etag = MicrosoftProvider::REREAD . 'DwAAABYAAABExampleSeriesKey01';
        $vcal = Reader::read($event->ics);
        EventIcs::addExdate(EventIcs::master($vcal, $event->uid), '20261109T090000Z', new DateTimeZone(self::ZONE));
        $this->http->queue(
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey01']), self::instanceList([self::occurrence('2026-11-09')]), new Response(204),
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey05'])
        );

        $ref = $this->provider->update($this->calendar, $event, $vcal, '20261109T090000Z');

        $this->assertSame(MicrosoftProvider::REREAD . 'DwAAABYAAABExampleSeriesKey05', $ref->etag);
        $this->assertSame(0, $this->http->pending());
    }

    public function test_an_occurrence_moved_far_away_is_found_where_it_went(): void
    {
        $moved = self::occurrence('2026-10-26', ['start' => ['dateTime' => '2026-10-30T13:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-30T14:00:00.0000000', 'timeZone' => 'UTC']]);
        $instances = GraphEventMapperTest::instances();
        $instances[2] = $moved;
        $event = $this->storeSeries($instances);
        $vcal = Reader::read($event->ics);
        EventIcs::findOverride($vcal, $event->uid, '20261026T090000Z', new DateTimeZone(self::ZONE))->SUMMARY = 'Weekly planning (Friday)';
        $this->http->queue(
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey01']),
            self::instanceList([]),
            self::instanceList([$moved]),
            self::answer(200, $moved),
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey02'])
        );

        $this->provider->update($this->calendar, $event, $vcal, '20261026T090000Z');

        $this->assertSame(['startDateTime' => '2026-10-29T13:00:00', 'endDateTime' => '2026-11-01T13:00:00', '$select' => 'id,type,originalStart,isCancelled,subject,body,location,sensitivity,start,end,isAllDay,attendees', '$top' => '100'], self::query($this->sent(5)));
        $this->assertSame('PATCH ' . self::EVENTS . '/' . $moved['id'], $this->requests()[6]);
        $this->assertSame(['subject' => 'Weekly planning (Friday)'], self::body($this->sent(6)));
    }

    public function test_an_occurrence_microsoft_no_longer_has_or_a_series_changed_meanwhile_is_a_conflict(): void
    {
        $event = $this->storeSeries();
        $vcal = Reader::read($event->ics);
        EventIcs::addOverride($vcal, EventIcs::master($vcal, $event->uid), '20261102T090000Z', new DateTimeZone(self::ZONE))->SUMMARY = 'Planning with Example Corp';
        $this->http->queue(
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey01']), self::instanceList([]),
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey01']), self::instanceList([self::occurrence('2026-11-02', ['isCancelled' => true])]),
            // Changed in Outlook since the last sync: nothing is looked up or written.
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey99']),
            self::graphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.')
        );

        foreach (range(1, 4) as $attempt) {
            $this->assertInstanceOf(ProviderConflict::class, $this->failure(function () use ($event, $vcal) {
                $this->provider->update($this->calendar, $event, $vcal, '20261102T090000Z');
            }));
        }
        $this->assertSame(0, $this->http->pending());
        $this->assertSame([], array_filter($this->requests(), function (string $request) {
            return strncmp($request, 'GET', 3) !== 0;
        }));
    }

    public function test_deleting_an_event_checks_its_change_key_first(): void
    {
        $event = $this->storeEarlier(self::fixture('single'));
        $key = ['id' => self::SINGLE, 'changeKey' => 'DwAAABYAAABExampleSingleKey01'];
        $this->http->queue(
            self::answer(200, $key), new Response(204),
            // Gone already, before or after the check: that is what the agent wants.
            self::graphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.'),
            self::answer(200, $key), self::graphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.'),
            // A spurious ErrorIrresolvableConflict (research question 3) is tried once more.
            self::answer(200, $key), self::graphError(412, 'ErrorIrresolvableConflict', 'The change key passed in the request does not match the current change key for the item.'), new Response(204),
            // Changed in Outlook meanwhile: a conflict, and nothing is deleted.
            self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSingleKey02'] + $key)
        );

        foreach (range(1, 4) as $attempt) {
            $this->provider->delete($this->calendar, $event);
        }
        $this->assertInstanceOf(ProviderConflict::class, $this->failure(function () use ($event) {
            $this->provider->delete($this->calendar, $event);
        }));

        $get = 'GET ' . self::EVENTS . '/' . self::SINGLE;
        $delete = 'DELETE ' . self::EVENTS . '/' . self::SINGLE;
        $this->assertSame([$get, $delete, $get, $get, $delete, $get, $delete, $delete, $get], $this->requests());
        $this->assertSame(['$select' => 'id,changeKey'], self::query($this->sent(0)));
    }

    public function test_a_moved_series_brings_its_exceptions_and_is_removed_again_when_one_fails(): void
    {
        $tz = new DateTimeZone(self::ZONE);
        $vcal = EventIcs::build([
            'uid' => 'moved@freescout.example.com', 'title' => 'Weekly planning', 'all_day' => false,
            'start' => new DateTimeImmutable('2026-10-05 10:00', $tz), 'end' => new DateTimeImmutable('2026-10-05 11:00', $tz), 'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=6',
        ]);
        $master = EventIcs::master($vcal, 'moved@freescout.example.com');
        EventIcs::addOverride($vcal, $master, '20261026T090000Z', $tz)->SUMMARY = 'Weekly planning (moved)';
        EventIcs::addExdate($master, '20261019T080000Z', $tz);
        $created = ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey01', 'iCalUId' => self::fixture('series-master')['iCalUId']];
        $oct19 = self::occurrence('2026-10-12', ['id' => 'AAMkADExampleSeries0001AAABExampleOccurrence20261019', 'originalStart' => '2026-10-19T08:00:00Z']);
        $oct26 = self::occurrence('2026-11-02', [
            'id' => 'AAMkADExampleSeries0001AAABExampleOccurrence20261026', 'originalStart' => '2026-10-26T09:00:00Z', 'body' => ['contentType' => 'text', 'content' => ''],
            'start' => ['dateTime' => '2026-10-26T09:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-26T10:00:00.0000000', 'timeZone' => 'UTC'],
        ]);
        $this->http->queue(
            self::answer(201, $created),
            self::instanceList([$oct26]), self::answer(200, $oct26),
            self::instanceList([$oct19]), new Response(204),
            self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSeriesKey03'] + $created)
        );

        $ref = $this->provider->create($this->calendar, $vcal);

        $this->assertSame([self::SERIES, 'DwAAABYAAABExampleSeriesKey03', $created['iCalUId']], [$ref->remoteId, $ref->etag, $ref->uid]);
        $series = self::EVENTS . '/' . self::SERIES;
        $this->assertSame([
            'POST ' . self::EVENTS, 'GET ' . $series . '/instances', 'PATCH ' . self::EVENTS . '/' . $oct26['id'],
            'GET ' . $series . '/instances', 'DELETE ' . self::EVENTS . '/' . $oct19['id'], 'GET ' . $series,
        ], $this->requests());
        $this->assertSame(['subject' => 'Weekly planning (moved)'], self::body($this->sent(2)));

        // An edited occurrence Microsoft has no instance for is left out; the series is then read again at the next sync.
        $this->http->queue(self::answer(201, $created), self::instanceList([]), self::instanceList([$oct19]), new Response(204), self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSeriesKey04'] + $created));
        $this->assertSame(MicrosoftProvider::REREAD . 'DwAAABYAAABExampleSeriesKey04', $this->provider->create($this->calendar, $vcal)->etag);

        // Half a series must not stay behind at Microsoft.
        $this->http->queue(self::answer(201, $created), self::instanceList([$oct26]), self::graphError(500, 'generalException', 'General exception'), new Response(204));
        $e = $this->failure(function () use ($vcal) {
            $this->provider->create($this->calendar, $vcal);
        });
        $this->assertSame([ProviderUnavailable::class, 500], [get_class($e), $e->httpStatus()]);
        $this->assertSame(['PATCH ' . self::EVENTS . '/' . $oct26['id'], 'DELETE ' . $series], array_slice($this->requests(), 13));
        $this->assertSame(0, $this->http->pending());
    }

    public function test_requests_an_agent_waits_for_only_wait_briefly_for_throttling(): void
    {
        $event = $this->storeEarlier(self::fixture('single'));
        $this->http->queue(self::graphError(429, 'TooManyRequests', 'Please retry again later.', ['Retry-After' => '10']));

        $e = $this->failure(function () use ($event) {
            $this->provider->delete($this->calendar, $event);
        });

        $this->assertSame(['Microsoft is limiting requests right now. Try again in a minute.', 429], [$e->userMessage(), $e->httpStatus()]);
        $this->assertSame([], $this->sleeps);
        // A sync may wait longer.
        $this->http->queue(self::graphError(429, 'TooManyRequests', 'Please retry again later.', ['Retry-After' => '10']), self::listing([]));
        $this->sync();
        $this->assertSame([10.0], $this->sleeps);
        $this->assertNull($this->calendar->last_error);
    }

    public function test_a_meeting_gets_its_body_without_freescouts_block(): void
    {
        $this->calendar->fields = [['id' => 3, 'name' => 'Customer', 'type' => 'text', 'required' => false, 'options' => []]];
        $this->calendar->save();
        // Graph mails every update of a meeting to its attendees, who see the organizer's body: FreeScout's fields and
        // ticket link stay out of it. The single fixture has three attendees; they are read, never written.
        $this->http->queue(self::listing([self::fixture('single')]), self::answer(200, self::fixture('single')));
        $this->sync();
        $event = $this->stored(self::SINGLE);
        $this->assertCount(3, Reader::read($event->ics)->VEVENT->select('ATTENDEE'));
        $event->field_values = ['3' => 'Example Corp'];
        $event->save();
        $user = $this->makeUser(['timezone' => self::ZONE]);
        $this->http->queue(self::answer(200, self::fixture('single')), self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSingleKey02'] + self::fixture('single')));

        app(EventService::class)->update($event->fresh(), $user, ['description' => 'Bring the contract.']);

        $this->assertSame(['body' => ['contentType' => 'text', 'content' => 'Bring the contract.']], self::body($this->sent(3)));
        // The export keeps the block.
        $this->assertStringContainsString("Bring the contract.\n\n[FreeScout]\nCustomer: Example Corp", Reader::read(app(ExportService::class)->render($this->calendar, false))->VEVENT->DESCRIPTION->getValue());

        // Without attendees the body carries the block.
        $alone = ['changeKey' => 'DwAAABYAAABExampleSingleKey02', 'attendees' => []] + self::fixture('single');
        $this->http->queue(self::answer(200, $alone), self::answer(200, ['changeKey' => 'DwAAABYAAABExampleSingleKey03'] + $alone));
        app(EventService::class)->update($event->fresh(), $user, ['description' => 'Bring the signed contract.']);
        $this->assertSame("Bring the signed contract.\n\n[FreeScout]\nCustomer: Example Corp", self::body($this->sent(5))['body']['content']);
    }

    public function test_an_occurrence_of_a_meeting_gets_its_body_without_freescouts_block(): void
    {
        $this->calendar->fields = [['id' => 3, 'name' => 'Customer', 'type' => 'text', 'required' => false, 'options' => []]];
        $this->calendar->save();
        $event = $this->storeSeries();
        $event->field_values = ['3' => 'Example Corp'];
        $event->save();
        $instance = self::occurrence('2026-11-02', ['attendees' => [['type' => 'required', 'status' => ['response' => 'none'], 'emailAddress' => ['name' => 'Customer Contact', 'address' => 'contact@example.com']]]]);
        $this->http->queue(
            self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey01']), self::instanceList([$instance]),
            self::answer(200, $instance), self::answer(200, ['id' => self::SERIES, 'changeKey' => 'DwAAABYAAABExampleSeriesKey02'])
        );

        app(EventService::class)->update($event->fresh(), $this->makeUser(['timezone' => self::ZONE]), ['scope' => 'occurrence', 'recurrence_id' => '20261102T090000Z', 'description' => 'Agenda for the customer.']);

        $this->assertSame(['body' => ['contentType' => 'text', 'content' => 'Agenda for the customer.']], self::body($this->sent(5)));
    }

    public function test_a_monthly_rule_on_day_29_to_31_is_refused_with_its_reason(): void
    {
        $user = $this->makeUser(['timezone' => self::ZONE]);
        $rules = [
            ['2026-10-31T10:00', ['freq' => 'MONTHLY', 'bymonthday' => 31], "Microsoft calendars can't repeat on day 29, 30 or 31 of the month."],
            ['2026-10-30T10:00', ['freq' => 'MONTHLY'], "Microsoft calendars can't repeat on day 29, 30 or 31 of the month."],
            ['2026-10-29T10:00', ['freq' => 'MONTHLY', 'interval' => 2, 'bymonthday' => 29, 'count' => 4], "Microsoft calendars can't repeat on day 29, 30 or 31 of the month."],
            // Another rule Graph can't hold (a Monday start for Tuesdays and Wednesdays) keeps the general message.
            ['2026-10-05T10:00', ['freq' => 'WEEKLY', 'byday' => ['TU', 'WE']], "This calendar can't store this repeat rule."],
        ];
        foreach ($rules as [$start, $rule, $message]) {
            try {
                app(EventService::class)->create($this->calendar, $user, ['title' => 'Month end', 'start' => $start, 'end' => substr($start, 0, 11) . '11:00', 'recurrence' => $rule]);
                $this->fail('Accepted ' . json_encode($rule));
            } catch (ValidationException $e) {
                $this->assertSame([$message], $e->validator->errors()->get('recurrence'), json_encode($rule));
            }
        }
        $this->assertSame([], $this->http->history());
        // The same reason for a series change and for a move here.
        $series = $this->storeSeries();
        try {
            app(EventService::class)->update($series, $user, ['recurrence' => ['freq' => 'MONTHLY', 'bymonthday' => 31]]);
            $this->fail('Accepted a change to day 31');
        } catch (ValidationException $e) {
            $this->assertSame(["Microsoft calendars can't repeat on day 29, 30 or 31 of the month."], $e->validator->errors()->get('recurrence'));
        }
        $local = Calendar::create(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'timezone' => self::ZONE]);
        $monthEnd = app(EventService::class)->create($local, $user, ['title' => 'Month end', 'start' => '2026-10-31T10:00', 'end' => '2026-10-31T11:00', 'recurrence' => ['freq' => 'MONTHLY', 'bymonthday' => 31]]);
        try {
            app(EventService::class)->update($monthEnd, $user, ['calendar_id' => $this->calendar->id]);
            $this->fail('Moved a series on day 31');
        } catch (ValidationException $e) {
            $this->assertSame(["Microsoft calendars can't repeat on day 29, 30 or 31 of the month."], $e->validator->errors()->get('calendar_id'));
        }
        $this->assertCount(3, $this->http->history());
        // Outlook's own rule for the 31st (the last day of shorter months) stays writable.
        $lastDay = EventIcs::build(['uid' => 'last@example.com', 'title' => 'x', 'all_day' => false, 'start' => new DateTimeImmutable('2026-10-31 10:00', new DateTimeZone(self::ZONE)), 'end' => new DateTimeImmutable('2026-10-31 11:00', new DateTimeZone(self::ZONE)), 'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=28,29,30,31;BYSETPOS=-1']);
        $this->assertTrue($this->provider->writableRecurrence($lastDay->VEVENT));
        $this->assertNull($this->provider->recurrenceRefusal($lastDay->VEVENT));
    }

    public function test_the_series_lock_follows_what_graph_can_hold(): void
    {
        $this->assertTrue($this->provider->canWrite());
        $rules = [
            'FREQ=WEEKLY;BYDAY=MO;UNTIL=20261231T225959Z' => true,
            'FREQ=MONTHLY;BYDAY=1MO'                      => true,
            'FREQ=MONTHLY;BYDAY=MO,TU;BYSETPOS=1,2'       => false,
            'FREQ=DAILY;BYHOUR=9,17'                      => false,
        ];
        foreach ($rules as $rule => $writable) {
            $vcal = EventIcs::build(['uid' => 'rule@example.com', 'title' => 'x', 'all_day' => false, 'start' => new DateTimeImmutable('2026-10-05 10:00', new DateTimeZone(self::ZONE)), 'end' => new DateTimeImmutable('2026-10-05 11:00', new DateTimeZone(self::ZONE)), 'rrule' => $rule]);
            $this->assertSame($writable, $this->provider->writableRecurrence($vcal->VEVENT), $rule);
        }
        $single = EventIcs::build(['uid' => 'single@example.com', 'title' => 'x', 'all_day' => true, 'start' => '2026-10-05', 'end' => '2026-10-06']);
        $this->assertTrue($this->provider->writableRecurrence($single->VEVENT));
        $single->VEVENT->add('RDATE', '20261012', ['VALUE' => 'DATE']);
        $this->assertFalse($this->provider->writableRecurrence($single->VEVENT));
    }

    public function test_a_series_graph_cant_hold_is_changed_one_occurrence_at_a_time(): void
    {
        $user = $this->makeUser(['timezone' => self::ZONE]);
        CalendarPermission::create(['calendar_id' => $this->calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $odd = app(EventImporter::class)->upsert($this->calendar, new RemoteEvent('AAMkADExampleOdd0001AAA=', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Remote//EN\r\nBEGIN:VEVENT\r\n"
            . "UID:odd@example.com\r\nDTSTAMP:20261001T080000Z\r\nDTSTART:20261005T080000Z\r\nDTEND:20261005T090000Z\r\nSUMMARY:Odd\r\n"
            . "RRULE:FREQ=MONTHLY;BYDAY=MO,TU;BYSETPOS=1,2;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", null, 'DwAAABYAAABExampleOddKey01'), 1);
        $key = $odd->occurrences()->orderBy('starts_at')->value('recurrence_id');

        $detail = $this->actingAs($user)->json('GET', '/calendar/api/events/e' . $odd->id)->assertStatus(200);
        $this->assertSame([false, true], [$detail->json()['series_writable'], $detail->json()['editable']]);
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $odd->id, ['title' => 'Renamed'])->assertStatus(403)->assertJsonFragment(['code' => 'series_not_writable']);
        $this->assertSame([], $this->http->history());

        $instance = ['id' => 'AAMkADExampleOddOccurrence1', 'type' => 'occurrence', 'originalStart' => '2026-10-05T08:00:00Z', 'isCancelled' => false, 'subject' => 'Odd',
            'start' => ['dateTime' => '2026-10-05T08:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-05T09:00:00.0000000', 'timeZone' => 'UTC'], 'isAllDay' => false];
        $this->http->queue(
            self::answer(200, ['id' => 'AAMkADExampleOdd0001AAA=', 'changeKey' => 'DwAAABYAAABExampleOddKey01']), self::instanceList([$instance]),
            self::answer(200, $instance), self::answer(200, ['id' => 'AAMkADExampleOdd0001AAA=', 'changeKey' => 'DwAAABYAAABExampleOddKey02'])
        );
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $odd->id, ['scope' => 'occurrence', 'recurrence_id' => $key, 'title' => 'Odd (once)'])->assertStatus(200);

        $this->assertSame('PATCH ' . self::EVENTS . '/AAMkADExampleOddOccurrence1', $this->requests()[2]);
        $this->assertSame('Odd (once)', self::body($this->sent(2))['subject']);
        $this->assertSame('DwAAABYAAABExampleOddKey02', $odd->fresh()->etag);
    }

    public function test_a_throttled_listing_ends_the_sync_with_microsofts_reason(): void
    {
        foreach (range(1, 4) as $attempt) {
            $this->http->queue(self::graphError(429, 'TooManyRequests', 'Please retry again later.'));
        }

        $this->sync();

        $this->assertSame('Microsoft is limiting requests right now. Try again in a minute. (HTTP 429)', $this->calendar->last_error);
        $this->assertCount(3, $this->sleeps);
    }
}
