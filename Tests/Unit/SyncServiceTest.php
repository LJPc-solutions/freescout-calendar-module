<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\ConfirmsDeletions;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\SyncService;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;

class SyncServiceTest extends TestCase
{
    private FakeProvider $provider;
    private SyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->provider = new FakeProvider(false);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_ICS, $this->provider);
        $this->sync = app(SyncService::class);
    }

    private function calendar(array $attributes = []): Calendar
    {
        return Calendar::create($attributes + ['name' => 'Feed ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS, 'remote_id' => 'https://calendar.example.com/feed.ics', 'sync_interval' => 5]);
    }

    private static function event(string $uid): RemoteEvent
    {
        return new RemoteEvent($uid, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Feed//EN\r\nBEGIN:VEVENT\r\nUID:" . $uid
            . "\r\nDTSTAMP:20261001T000000Z\r\nSUMMARY:" . $uid . "\r\nDTSTART:20261020T080000Z\r\nDTEND:20261020T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
    }

    private static function complete(array $upserts = [], array $unchanged = [], bool $fullListing = true): ChangeSet
    {
        return new ChangeSet($upserts, [], [], true, $fullListing, $unchanged);
    }

    private static function series(string $uid): RemoteEvent
    {
        return new RemoteEvent($uid, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Feed//EN\r\nBEGIN:VEVENT\r\nUID:" . $uid . "\r\nSUMMARY:" . $uid
            . "\r\nDTSTART:20261019T080000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
    }

    /** A partial update of a stored series that the importer can't apply: its occurrence key is not a date. */
    private static function brokenPartial(string $uid): RemoteEvent
    {
        return new RemoteEvent($uid, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Feed//EN\r\nBEGIN:VEVENT\r\nUID:" . $uid . "\r\nRECURRENCE-ID:garbage\r\n"
            . "SUMMARY:" . $uid . "\r\nDTSTART:20261020T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", null, null, false, true);
    }

    private function uids(Calendar $calendar): array
    {
        return CalendarEvent::where('calendar_id', $calendar->id)->orderBy('uid')->pluck('uid')->all();
    }

    /** Events from before the listing under test; FreeScout's own saves during a listing are never swept. */
    private function backdate(Calendar $calendar): void
    {
        DB::table('calendar_events')->where('calendar_id', $calendar->id)->update(['updated_at' => '2026-01-01 00:00:00']);
    }

    private static function minutesFromNow(?string $utc): float
    {
        return ((new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp() - time()) / 60;
    }

    public function test_a_calendar_another_run_holds_is_skipped(): void
    {
        $held = $this->calendar(['sync_lease_until' => gmdate('Y-m-d H:i:s', time() + 60)]);

        $this->assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 1], $this->sync->runDue(SyncBudget::seconds(30), $held->id));
        $this->assertSame([], $this->provider->calls);
    }

    public function test_the_lease_is_held_while_pulling_and_released_afterwards(): void
    {
        $calendar = $this->calendar(['sync_lease_until' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $lease = null;
        $this->provider->onPull(function (Calendar $pulled) use (&$lease) {
            $lease = DB::table('calendars')->where('id', $pulled->id)->value('sync_lease_until');

            return self::complete([], [], false);
        });

        $this->assertSame(['synced' => 1, 'failed' => 0, 'skipped' => 0], $this->sync->runDue(SyncBudget::seconds(30), $calendar->id));

        $this->assertGreaterThan(SyncService::LEASE_SECONDS / 60 - 0.1, self::minutesFromNow($lease));
        $this->assertNull(DB::table('calendars')->where('id', $calendar->id)->value('sync_lease_until'));
    }

    public function test_a_pull_over_several_rounds_keeps_its_lease(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(new ChangeSet([self::event('a')], [], ['page' => 2], false, true), self::complete([self::event('b')]));

        $this->assertSame(['synced' => 1, 'failed' => 0, 'skipped' => 0], $this->sync->runDue(SyncBudget::seconds(30), $calendar->id));

        $this->assertSame(['a', 'b'], $this->uids($calendar));
        $this->assertNull(DB::table('calendars')->where('id', $calendar->id)->value('sync_lease_until'));
    }

    public function test_a_run_that_lost_its_lease_stops_without_recording_a_result(): void
    {
        $log = LogRecorder::install();
        $calendar = $this->calendar();
        $otherRun = gmdate('Y-m-d H:i:s', time() + 999);
        $this->provider->onPull(function (Calendar $pulled) use ($otherRun) {
            // This run outlived its lease and another run took the calendar meanwhile.
            DB::table('calendars')->where('id', $pulled->id)->update(['sync_lease_until' => $otherRun]);

            return self::complete([self::event('a')]);
        });

        $this->assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 1], $this->sync->runDue(SyncBudget::seconds(30), $calendar->id));

        $this->assertSame([], $this->uids($calendar));
        $calendar = $calendar->fresh();
        $this->assertSame([null, null, null, 0], [$calendar->sync_state, $calendar->last_synced_at, $calendar->next_sync_at, $calendar->sync_failures]);
        $this->assertSame($otherRun, DB::table('calendars')->where('id', $calendar->id)->value('sync_lease_until'), 'the other run keeps its lease');
        $this->assertSame('A calendar sync outlived its lease and stopped; another run continues it', $log->records('warning')[0]['message']);
    }

    public function test_the_lease_is_checked_during_a_long_round(): void
    {
        $calendar = $this->calendar();
        $otherRun = gmdate('Y-m-d H:i:s', time() + 999);
        $upserts = [];
        for ($n = 1; $n <= SyncService::LEASE_RENEW_UPSERTS + 1; $n++) {
            $upserts[] = self::event('e' . $n);
        }
        $this->provider->onPull(self::complete($upserts));
        $created = 0;
        // Another run takes the calendar over while this one stores its hundredth event.
        CalendarEvent::created(function () use (&$created, $calendar, $otherRun) {
            if (++$created === SyncService::LEASE_RENEW_UPSERTS) {
                DB::table('calendars')->where('id', $calendar->id)->update(['sync_lease_until' => $otherRun]);
            }
        });
        try {
            $counts = $this->sync->runDue(SyncBudget::seconds(30), $calendar->id);
        } finally {
            CalendarEvent::flushEventListeners();
        }

        $this->assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 1], $counts);
        $this->assertSame(SyncService::LEASE_RENEW_UPSERTS, CalendarEvent::where('calendar_id', $calendar->id)->count());
        $this->assertNull($calendar->fresh()->sync_state);
    }

    public function test_a_failure_after_the_lease_was_lost_records_nothing(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(function (Calendar $pulled) {
            // The request outlived the lease; another run holds the calendar when it fails.
            DB::table('calendars')->where('id', $pulled->id)->update(['sync_lease_until' => gmdate('Y-m-d H:i:s', time() + 999)]);

            throw new ProviderUnavailable(null, 504);
        });

        $this->assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 1], $this->sync->runDue(SyncBudget::seconds(30), $calendar->id));

        $calendar = $calendar->fresh();
        $this->assertSame([null, 0, null], [$calendar->last_error, $calendar->sync_failures, $calendar->next_sync_at]);
    }

    public function test_a_spent_budget_starts_no_calendar(): void
    {
        $this->calendar();

        $this->assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 0], $this->sync->runDue(SyncBudget::seconds(0)));
        $this->assertSame([], $this->provider->calls);
    }

    public function test_the_budget_ends_the_pull_after_the_current_round_and_the_next_run_resumes(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(new ChangeSet([self::event('a')], [], ['page' => 2], false, true));

        $this->sync->syncCalendar($calendar, SyncBudget::seconds(0));

        $calendar = $calendar->fresh();
        $this->assertCount(1, $this->provider->calls('pull'));
        $this->assertSame(2, $calendar->sync_state['page']);
        $this->assertTrue($calendar->sync_state['_listing']);
        $this->assertNull($calendar->last_synced_at);
        $this->assertLessThanOrEqual(0.0, self::minutesFromNow($calendar->next_sync_at), 'an unfinished pull continues in the next run');

        $this->provider->onPull(new ChangeSet([self::event('b')], [], ['token' => 'done', '_generation' => 99], true, true));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));

        $calendar = $calendar->fresh();
        $this->assertSame(['page' => 2], $this->provider->calls('pull')[1]['state'], 'providers never see the engine keys');
        $this->assertSame(['a', 'b'], $this->uids($calendar));
        $this->assertSame(['token' => 'done', '_generation' => 1, '_listing' => false], array_intersect_key($calendar->sync_state, ['token' => 0, '_generation' => 0, '_listing' => 0]));
        $this->assertNotNull($calendar->last_synced_at);
    }

    public function test_a_full_listing_over_two_runs_sweeps_only_when_it_completes(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(self::complete([self::event('a'), self::event('b'), self::event('c')]));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));
        $this->backdate($calendar);

        $this->provider->onPull(
            new ChangeSet([self::event('a')], [], ['page' => 2], false, true),
            self::complete([], ['b'])
        );
        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(0));
        $this->assertSame(['a', 'b', 'c'], $this->uids($calendar));

        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));
        $this->assertSame(['a', 'b'], $this->uids($calendar));
        $this->assertSame([2, 2], CalendarEvent::where('calendar_id', $calendar->id)->orderBy('uid')->pluck('sync_generation')->all());
    }

    public function test_a_provider_that_confirms_deletions_keeps_what_still_exists(): void
    {
        $confirming = new class (false) extends FakeProvider implements ConfirmsDeletions {
            /** @var array[] the remote ids of each question */
            public array $asked = [];

            /** @var string[] what the provider no longer has */
            public array $gone = [];

            public function confirmGone(Calendar $calendar, array $remoteIds, SyncBudget $budget): array
            {
                $this->asked[] = $remoteIds;

                // Ids it didn't check in time are left out, like the ones it still has.
                return array_values(array_intersect($remoteIds, $this->gone));
            }
        };
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_ICS, $confirming);
        $calendar = $this->calendar();
        $confirming->onPull(self::complete([self::event('a'), self::event('b'), self::event('c'), self::event('d'), self::event('e')]));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));
        $this->backdate($calendar);
        // An event without a remote id was never at the provider: nothing to ask about.
        CalendarEvent::findByRemoteId($calendar->id, 'e')->update(['remote_id' => null]);
        $this->backdate($calendar);

        // A listing that leaves b to e out: b is gone, c still exists, d was not checked in time.
        $confirming->gone = ['b'];
        $confirming->onPull(self::complete([], ['a']));
        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));

        $this->assertSame([['b', 'c', 'd']], $confirming->asked);
        $this->assertSame(['a', 'c', 'd'], $this->uids($calendar));
        // Kept as seen in this listing: the next listing that leaves them out asks about them again.
        $this->assertSame([2, 2, 2], CalendarEvent::where('calendar_id', $calendar->id)->orderBy('uid')->pluck('sync_generation')->all());
        $this->assertSame(['2026-01-01 00:00:00'], CalendarEvent::where('calendar_id', $calendar->id)->distinct()->pluck('updated_at')->map(function ($date) {
            return (string) $date;
        })->all());
        $confirming->gone = ['c', 'd'];
        $confirming->onPull(self::complete([], ['a']));
        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));
        $this->assertSame(['c', 'd'], $confirming->asked[1]);
        $this->assertSame(['a'], $this->uids($calendar));
    }

    public function test_an_empty_listing_deletes_only_when_the_next_listing_is_empty_too(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(self::complete([self::event('a')]));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));
        $this->backdate($calendar);

        $this->provider->onPull(self::complete(), self::complete([], ['a']), self::complete());
        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));
        $this->assertSame(['a'], $this->uids($calendar));
        $this->assertSame(1, $calendar->fresh()->sync_state['_empty_listings']);

        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));
        $this->assertSame(0, $calendar->fresh()->sync_state['_empty_listings'], 'a listing with events resets the count');
        $this->backdate($calendar);

        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));
        $this->assertSame(['a'], $this->uids($calendar));

        $this->provider->onPull(self::complete());
        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));
        $this->assertSame([], $this->uids($calendar));
    }

    public function test_a_listing_that_ends_in_an_incremental_round_sweeps_nothing(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(self::complete([self::event('a'), self::event('b'), self::event('c')]));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));
        $this->backdate($calendar);

        $this->provider->onPull(
            new ChangeSet([self::event('a')], [], ['page' => 2], false, true),
            new ChangeSet([], [], ['token' => 't'], true, false)
        );
        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));

        $this->assertSame(['a', 'b', 'c'], $this->uids($calendar));
        $this->assertFalse($calendar->fresh()->sync_state['_listing']);
    }

    public function test_one_event_that_cannot_be_stored_does_not_stop_the_others(): void
    {
        $log = LogRecorder::install();
        $calendar = $this->calendar();
        $this->provider->onPull(self::complete([self::series('s')]), new ChangeSet([self::brokenPartial('s'), self::event('b')], [], [], true, false));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));

        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));

        $this->assertSame(['b', 's'], $this->uids($calendar));
        $this->assertNull($calendar->fresh()->last_error);
        $this->assertCount(1, $log->records('error'), 'the broken event is reported');
    }

    public function test_a_cause_that_hits_every_event_is_reported_once(): void
    {
        $log = LogRecorder::install();
        $calendar = $this->calendar();
        $this->provider->onPull(
            self::complete([self::series('s1'), self::series('s2'), self::series('s3')]),
            new ChangeSet([self::brokenPartial('s1'), self::brokenPartial('s2'), self::brokenPartial('s3')], [], [], true, false)
        );
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));

        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));

        $errors = $log->records('error');
        $this->assertCount(2, $errors);
        $this->assertSame(['More pulled calendar events could not be stored; the first error was reported above', ['calendar_id' => $calendar->id, 'count' => 2]], [$errors[1]['message'], $errors[1]['context']]);
    }

    public function test_deletes_remove_events_with_their_occurrences(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(self::complete([self::event('a'), self::event('b')]), new ChangeSet([], ['a', 'unknown'], [], true, false));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));
        $a = CalendarEvent::findByRemoteId($calendar->id, 'a');

        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));

        $this->assertSame(['b'], $this->uids($calendar));
        $this->assertSame(0, CalendarOccurrence::where('event_id', $a->id)->count());
    }

    public function test_events_freescout_saved_during_a_listing_are_not_swept(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(
            new ChangeSet([self::event('a')], [], ['page' => 2], false, true),
            self::complete([], ['a'])
        );
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(0));
        $local = app(EventService::class)->create($calendar->fresh(), $this->makeUser(), ['title' => 'Saved meanwhile', 'start' => '2026-10-21T10:00']);

        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));

        $this->assertNotNull(CalendarEvent::find($local->id));
    }

    public function test_events_a_listing_left_out_are_swept_however_recently_they_were_stored(): void
    {
        // A feed drops an event half an hour after it was imported: the next listing removes it.
        $calendar = $this->calendar();
        $this->provider->onPull(self::complete([self::event('a'), self::event('b'), self::event('c')]), self::complete([], ['c']));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));
        $at = function (string $uid, int $secondsAgo) use ($calendar) {
            DB::table('calendar_events')->where('calendar_id', $calendar->id)->where('uid', $uid)->update(['updated_at' => date('Y-m-d H:i:s', time() - $secondsAgo)]);
        };
        $at('a', 2 * 3600);
        $at('b', 30 * 60);

        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));

        $this->assertSame(['c'], $this->uids($calendar));
    }

    public function test_the_sweep_spares_saves_from_the_hour_a_clock_went_back_during_the_listing(): void
    {
        $at = function (string $utc): int {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
        };
        // Amsterdam goes from 03:00 summer time back to 02:00 on 25 October 2026 (01:00 UTC). A listing starts at 02:45
        // summer time; a save at 02:10 winter time comes after it but reads earlier, so the cut-off moves an hour back.
        $this->assertSame('2026-10-25 01:45:00', SyncService::sweepCutoff($at('2026-10-25 00:45:00'), $at('2026-10-25 01:30:00'), 'Europe/Amsterdam'));
        // Without a change of clock, or when it goes forward, the listing's own start counts.
        $this->assertSame('2026-10-20 12:00:00', SyncService::sweepCutoff($at('2026-10-20 10:00:00'), $at('2026-10-20 11:00:00'), 'Europe/Amsterdam'));
        $this->assertSame('2026-03-29 01:30:00', SyncService::sweepCutoff($at('2026-03-29 00:30:00'), $at('2026-03-29 01:30:00'), 'Europe/Amsterdam'));
        $this->assertSame('2026-10-25 00:45:00', SyncService::sweepCutoff($at('2026-10-25 00:45:00'), $at('2026-10-25 01:30:00'), 'UTC'));
    }

    public function test_an_event_that_can_no_longer_be_read_keeps_its_stored_copy(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(self::complete([self::event('a')]), self::complete([new RemoteEvent('a', 'garbage')]));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));
        $this->backdate($calendar);

        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));

        $this->assertSame(['a'], $this->uids($calendar));
        $this->assertSame(2, CalendarEvent::findByRemoteId($calendar->id, 'a')->sync_generation);
    }

    public function test_success_is_recorded_and_the_next_sync_planned_with_jitter(): void
    {
        $calendar = $this->calendar(['sync_interval' => 60, 'last_error' => 'Old problem', 'sync_failures' => 3]);
        $this->provider->onPull(self::complete([], [], false));

        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));

        $calendar = $calendar->fresh();
        $this->assertSame([null, 0], [$calendar->last_error, $calendar->sync_failures]);
        $this->assertEqualsWithDelta(0, self::minutesFromNow($calendar->last_synced_at), 0.1);
        $this->assertGreaterThanOrEqual(53.9, self::minutesFromNow($calendar->next_sync_at));
        $this->assertLessThanOrEqual(66.0, self::minutesFromNow($calendar->next_sync_at));
    }

    public function test_failures_back_off_up_to_six_intervals(): void
    {
        $this->assertSame([5, 10, 20, 30], array_map(function (int $failures) {
            return SyncService::backoffMinutes(5, $failures);
        }, [1, 2, 3, 10]));

        $calendar = $this->calendar(['sync_interval' => 5, 'sync_failures' => 1]);
        $this->provider->onPull(new ProviderUnavailable(null, 503));

        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));

        $calendar = $calendar->fresh();
        $this->assertSame(2, $calendar->sync_failures);
        $this->assertSame('The calendar provider could not be reached. Try again later. (HTTP 503)', $calendar->last_error);
        $this->assertGreaterThanOrEqual(8.9, self::minutesFromNow($calendar->next_sync_at));
        $this->assertLessThanOrEqual(11.0, self::minutesFromNow($calendar->next_sync_at));
        $this->assertNull($calendar->last_synced_at);

        $this->provider->onPull(new ProviderUnavailable('The calendar feed refused access (HTTP 403).', 403));
        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));
        $this->assertSame('The calendar feed refused access (HTTP 403).', $calendar->fresh()->last_error, 'a status the message names is not repeated');
    }

    public function test_a_connection_that_needs_reauthorization_is_marked(): void
    {
        $caldav = new FakeProvider(true);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_CALDAV, $caldav);
        $connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'agent@example.com']);
        $calendar = Calendar::create(['name' => 'Dav ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $connection->id]);
        $caldav->onPull(new ConnectionNeedsReauth(401));

        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));

        $message = "This calendar's connection needs to be re-authorized by an administrator. (HTTP 401)";
        $this->assertSame([CalendarConnection::STATUS_NEEDS_REAUTH, $message], [$connection->fresh()->status, $connection->fresh()->last_error]);
        $this->assertSame($message, $calendar->fresh()->last_error);
    }

    public function test_a_sync_asked_for_while_a_run_holds_the_calendar_is_kept(): void
    {
        $calendar = $this->calendar(['sync_interval' => 60, 'next_sync_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        // "Sync now", or the request after a conflict, lands while the run pulls.
        $askNow = function (Calendar $calendar) {
            $this->sync->requestSync(Calendar::find($calendar->id));
        };
        $this->provider->onPull(function (Calendar $calendar) use ($askNow) {
            $askNow($calendar);

            return self::complete([self::event('a')]);
        }, function (Calendar $calendar) use ($askNow) {
            $askNow($calendar);

            throw new ProviderUnavailable(null, 503);
        });

        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));
        $this->assertLessThanOrEqual(0.1, self::minutesFromNow($calendar->fresh()->next_sync_at));
        $this->assertNotNull($calendar->fresh()->last_synced_at);

        $calendar->update(['next_sync_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));
        $this->assertLessThanOrEqual(0.1, self::minutesFromNow($calendar->fresh()->next_sync_at));
        $this->assertSame(1, $calendar->fresh()->sync_failures);

        // Without such a request the run plans the next one itself.
        $this->provider->onPull(self::complete());
        $this->sync->syncCalendar($calendar->fresh(), SyncBudget::seconds(30));
        $this->assertGreaterThan(50, self::minutesFromNow($calendar->fresh()->next_sync_at));
    }

    public function test_a_refused_token_never_undoes_a_reauthorization_that_finished_meanwhile(): void
    {
        $google = new FakeProvider(true);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_GOOGLE, $google);
        $connection = new CalendarConnection(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'agent@example.com']);
        $connection->setCredentials(['refresh_token' => 'test-refresh-old', 'access_token' => 'test-access-old', 'expires_at' => time() + 3600]);
        $connection->save();
        $calendar = Calendar::create(['name' => 'Google ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $connection->id]);
        $google->onPull(function (Calendar $calendar) {
            // The provider read the connection, then an administrator's sign-in stored new tokens while Google refused the old one.
            $calendar->calendarConnection->credentials();
            $reauthorized = CalendarConnection::find($calendar->connection_id);
            $reauthorized->setCredentials(['refresh_token' => 'test-refresh-new', 'access_token' => 'test-access-new', 'expires_at' => time() + 3600]);
            $reauthorized->save();

            throw new ConnectionNeedsReauth(401);
        });

        $this->sync->syncCalendar($calendar, SyncBudget::seconds(30));

        $this->assertSame([CalendarConnection::STATUS_OK, null, 'test-refresh-new'], [$connection->fresh()->status, $connection->fresh()->last_error, $connection->fresh()->credentials()['refresh_token']]);
    }

    public function test_one_failing_calendar_does_not_stop_the_next_and_the_error_is_reported(): void
    {
        $log = LogRecorder::install();
        $failing = $this->calendar();
        $working = $this->calendar();
        $pull = function (Calendar $calendar) use ($failing) {
            if ($calendar->id === $failing->id) {
                throw new \RuntimeException('Unexpected parser state');
            }

            return self::complete([self::event('a')]);
        };
        $this->provider->onPull($pull, $pull);

        $this->assertSame(['synced' => 1, 'failed' => 1, 'skipped' => 0], $this->sync->runDue(SyncBudget::seconds(30)));

        $this->assertSame('Syncing failed because of an unexpected error. See the FreeScout log.', $failing->fresh()->last_error);
        $this->assertSame(['a'], $this->uids($working));
        $this->assertSame('Unexpected parser state', $log->records('error')[0]['message']);
    }

    public function test_only_enabled_external_calendars_that_are_due_and_connected_are_synced(): void
    {
        $caldav = new FakeProvider(true);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_CALDAV, $caldav);
        $broken = CalendarConnection::create(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'old@example.com', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $working = CalendarConnection::create(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'agent@example.com']);
        $due = $this->calendar(['next_sync_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $later = $this->calendar(['next_sync_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $this->calendar(['enabled' => false]);
        Calendar::create(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        Calendar::create(['name' => 'Dav ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $broken->id]);
        $connected = Calendar::create(['name' => 'Dav ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $working->id]);
        $this->provider->onPull(self::complete([], [], false), self::complete([], [], false));
        $caldav->onPull(self::complete([], [], false));

        $this->assertSame(['synced' => 2, 'failed' => 0, 'skipped' => 0], $this->sync->runDue(SyncBudget::seconds(30)));
        $this->assertSame([$due->id], array_column($this->provider->calls('pull'), 'calendar_id'));
        $this->assertSame([$connected->id], array_column($caldav->calls('pull'), 'calendar_id'));

        $this->assertSame(['synced' => 1, 'failed' => 0, 'skipped' => 0], $this->sync->runDue(SyncBudget::seconds(30), $later->id, true));
        $this->assertSame([$due->id, $later->id], array_column($this->provider->calls('pull'), 'calendar_id'));
    }

    public function test_sync_now_makes_the_calendar_due_and_keeps_its_failures(): void
    {
        $calendar = $this->calendar(['next_sync_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'sync_failures' => 2, 'last_error' => 'Still broken']);

        $this->sync->requestSync($calendar);

        $calendar = $calendar->fresh();
        $this->assertLessThanOrEqual(0.0, self::minutesFromNow($calendar->next_sync_at));
        $this->assertSame([2, 'Still broken'], [$calendar->sync_failures, $calendar->last_error]);
    }

    public function test_a_sync_by_hand_counts_what_it_added_changed_and_removed(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(self::complete([self::event('a'), self::event('b'), self::event('c')]));
        $this->sync->runDue(SyncBudget::seconds(30), $calendar->id, true);
        $this->backdate($calendar);
        $calendar->update(['last_synced_at' => gmdate('Y-m-d H:i:s', time() - SyncService::RECENT_SECONDS - 1)]);
        $changed = self::event('b');
        $changed->ics = str_replace('SUMMARY:b', 'SUMMARY:b moved', $changed->ics);
        // a stays the same, b changes, c is gone, d is new.
        $this->provider->onPull(self::complete([self::event('a'), $changed, self::event('d')]));

        $result = $this->sync->syncByHand($calendar->fresh(), SyncBudget::seconds(20));

        $this->assertSame(['state' => 'synced', 'created' => 1, 'updated' => 1, 'deleted' => 1], $result);
        $this->assertSame(['a', 'b', 'd'], $this->uids($calendar));
    }

    public function test_a_sync_by_hand_that_ran_out_of_time_is_partial_and_the_next_run_goes_on(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(function () {
            usleep(300000); // the first round outlasts the budget, so the listing stops after it

            return new ChangeSet([self::event('a')], [], ['page' => 2], false, true);
        });

        $result = $this->sync->syncByHand($calendar, SyncBudget::seconds(0.2));

        $this->assertSame(['state' => 'partial', 'created' => 1, 'updated' => 0, 'deleted' => 0], $result);
        $this->assertLessThanOrEqual(0.0, self::minutesFromNow($calendar->fresh()->next_sync_at));
    }

    public function test_a_sync_by_hand_waits_for_nothing_while_a_run_holds_the_calendar(): void
    {
        $calendar = $this->calendar(['sync_lease_until' => gmdate('Y-m-d H:i:s', time() + 60)]);

        $this->assertSame(['state' => 'running', 'created' => 0, 'updated' => 0, 'deleted' => 0], $this->sync->syncByHand($calendar, SyncBudget::seconds(20)));
        $this->assertSame([], $this->provider->calls);
    }

    public function test_a_calendar_synced_moments_ago_is_not_fetched_again(): void
    {
        // Many agents may press "Sync now"; the provider's quota is not spent twice within RECENT_SECONDS.
        $calendar = $this->calendar(['last_synced_at' => gmdate('Y-m-d H:i:s', time() - 5)]);

        $this->assertSame(['state' => 'recent', 'created' => 0, 'updated' => 0, 'deleted' => 0], $this->sync->syncByHand($calendar, SyncBudget::seconds(20)));
        $this->assertSame([], $this->provider->calls);

        $calendar->update(['last_synced_at' => gmdate('Y-m-d H:i:s', time() - SyncService::RECENT_SECONDS - 1)]);
        $this->provider->onPull(self::complete());
        $this->assertSame('synced', $this->sync->syncByHand($calendar->fresh(), SyncBudget::seconds(20))['state']);
    }

    public function test_a_failing_sync_by_hand_says_so_and_keeps_the_error(): void
    {
        $calendar = $this->calendar();
        $this->provider->onPull(new ProviderUnavailable('The feed answered with an error.', 500));

        $this->assertSame(['state' => 'failed', 'created' => 0, 'updated' => 0, 'deleted' => 0], $this->sync->syncByHand($calendar, SyncBudget::seconds(20)));
        $this->assertSame('The feed answered with an error. (HTTP 500)', $calendar->fresh()->last_error);
    }

    public function test_a_sync_by_hand_of_a_calendar_that_is_off_or_disconnected_fetches_nothing(): void
    {
        $off = $this->calendar(['enabled' => false]);
        $this->assertSame('off', $this->sync->syncByHand($off, SyncBudget::seconds(20))['state']);

        $connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $google = Calendar::create(['name' => 'Google ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $connection->id, 'remote_id' => 'team@example.com']);
        $this->assertSame('reauth', $this->sync->syncByHand($google, SyncBudget::seconds(20))['state']);
        $this->assertSame([], $this->provider->calls);
    }
}
