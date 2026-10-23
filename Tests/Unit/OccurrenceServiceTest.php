<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Sabre\VObject\Settings;
use Sabre\VObject\Component\VCalendar;

class OccurrenceServiceTest extends TestCase
{
    private OccurrenceService $service;
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OccurrenceService::class);
        $this->service->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->calendar = Calendar::create(['name' => 'Occurrences ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'timezone' => 'Europe/Amsterdam']);
    }

    private function store(VCalendar $vcal, string $uid): CalendarEvent
    {
        $event = new CalendarEvent(['calendar_id' => $this->calendar->id, 'ics' => $vcal->serialize()] + EventIcs::derive($vcal, $uid, 'Europe/Amsterdam'));
        $event->uid = $uid;
        $event->save();
        $this->service->refresh($event);

        return $event;
    }

    private function local(string $tz, string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone($tz));
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public function test_weekly_series_keeps_local_time_across_the_european_dst_change(): void
    {
        $vcal = EventIcs::build(['uid' => 'w', 'title' => 'Standup', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-20 10:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-20 10:15'), 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
        $event = $this->store($vcal, 'w');

        $rows = CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->pluck('starts_at')->all();
        $this->assertSame(['2026-10-20 08:00:00', '2026-10-27 09:00:00', '2026-11-03 09:00:00'], $rows);
        $this->assertSame('20261027T090000Z', CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->skip(1)->first()->recurrence_id);
    }

    public function test_weekly_series_keeps_local_time_across_the_sydney_dst_change(): void
    {
        $vcal = EventIcs::build(['uid' => 's', 'title' => 'Sync', 'all_day' => false, 'start' => $this->local('Australia/Sydney', '2026-09-24 09:00'), 'end' => $this->local('Australia/Sydney', '2026-09-24 10:00'), 'rrule' => 'FREQ=WEEKLY;INTERVAL=2;COUNT=2']);
        $event = $this->store($vcal, 's');

        $rows = CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->pluck('starts_at')->all();
        $this->assertSame(['2026-09-23 23:00:00', '2026-10-07 22:00:00'], $rows);
    }

    public function test_exdate_and_override_are_respected(): void
    {
        $vcal = EventIcs::build(['uid' => 'e', 'title' => 'Daily', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-20 10:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-20 11:00'), 'rrule' => 'FREQ=DAILY;COUNT=3']);
        $master = EventIcs::master($vcal, 'e');
        $tz = new DateTimeZone('Europe/Amsterdam');
        EventIcs::addExdate($master, '20261021T080000Z', $tz);
        $override = EventIcs::addOverride($vcal, $master, '20261022T080000Z', $tz);
        EventIcs::apply($override, ['title' => 'Moved', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-22 15:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-22 16:00')]);
        $event = $this->store($vcal, 'e');

        $rows = CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-22 13:00:00', $rows[1]->starts_at);
        $this->assertSame('Moved', $rows[1]->summary);
        $this->assertNull($rows[0]->summary);
        $this->assertTrue($this->service->hasOccurrence($event, '20261022T080000Z'));
        $this->assertFalse($this->service->hasOccurrence($event, '20261021T080000Z'));
    }

    public function test_between_includes_multi_day_events_that_started_before_the_range(): void
    {
        $this->store(EventIcs::build(['uid' => 'm', 'title' => 'Trip', 'all_day' => true, 'start' => '2026-10-01', 'end' => '2026-10-11']), 'm');
        $this->store(EventIcs::build(['uid' => 'n', 'title' => 'Night', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-04 22:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-05 02:00')]), 'n');

        $rows = $this->service->between([$this->calendar->id], $this->local('Europe/Amsterdam', '2026-10-05 00:00'), $this->local('Europe/Amsterdam', '2026-10-12 00:00'), 'Europe/Amsterdam');

        $this->assertCount(2, $rows);
        $none = $this->service->between([$this->calendar->id], $this->local('Europe/Amsterdam', '2026-10-11 00:00'), $this->local('Europe/Amsterdam', '2026-10-12 00:00'), 'Europe/Amsterdam');
        $this->assertCount(0, $none); // the all-day end date is exclusive
    }

    public function test_ranges_outside_the_stored_window_are_expanded_on_the_fly(): void
    {
        $this->store(EventIcs::build(['uid' => 'f', 'title' => 'Forever', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-05 09:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-05 09:30'), 'rrule' => 'FREQ=WEEKLY']), 'f');

        $rows = $this->service->between([$this->calendar->id], $this->utc('2030-01-01'), $this->utc('2030-01-15'), 'Europe/Amsterdam');

        $this->assertCount(2, $rows);
        $this->assertSame('2030-01-07 08:00:00', $rows[0]['starts_at']);
        $this->assertSame(0, CalendarOccurrence::where('starts_at', '>=', '2030-01-01')->count());
    }

    public function test_infinite_series_is_capped(): void
    {
        $event = $this->store(EventIcs::build(['uid' => 'c', 'title' => 'Daily', 'all_day' => true, 'start' => '2000-01-01', 'end' => '2000-01-02', 'rrule' => 'FREQ=DAILY']), 'c');

        $rows = $this->service->expand($event, $this->utc('2000-01-01'), $this->utc('2100-01-01'));
        $this->assertCount(OccurrenceService::MAX_PER_EVENT, $rows);
    }

    public function test_instances_straddling_the_window_edge_are_returned_once(): void
    {
        $event = $this->store(EventIcs::build(['uid' => 'edge', 'title' => 'Late', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2025-10-05 01:00'), 'end' => $this->local('Europe/Amsterdam', '2025-10-05 03:00'), 'rrule' => 'FREQ=WEEKLY']), 'edge');

        $rows = $this->service->between([$this->calendar->id], $this->utc('2025-10-04'), $this->utc('2025-10-20'), 'Europe/Amsterdam');

        $keys = array_column($rows, 'recurrence_id');
        $this->assertContains('20251004T230000Z', $keys);
        $this->assertSame(array_values(array_unique($keys)), $keys);
        $this->assertCount(3, $rows);
    }

    public function test_all_day_instances_near_the_window_end_are_returned_once_east_of_utc(): void
    {
        $this->store(EventIcs::build(['uid' => 'ad', 'title' => 'Allday', 'all_day' => true, 'start' => '2026-10-05', 'end' => '2026-10-06', 'rrule' => 'FREQ=WEEKLY']), 'ad');
        [, $windowTo] = $this->service->window();

        $rows = $this->service->between([$this->calendar->id], $windowTo->modify('-30 days'), $windowTo->modify('+30 days'), 'Pacific/Auckland');

        $keys = array_column($rows, 'recurrence_id');
        $this->assertNotEmpty($keys);
        $this->assertSame(array_values(array_unique($keys)), $keys);
    }

    public function test_runaway_series_signals_failure_and_restores_the_recurrence_setting(): void
    {
        $event = $this->store(EventIcs::build(['uid' => 'min', 'title' => 'Minutely', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2000-01-01 00:00'), 'end' => $this->local('Europe/Amsterdam', '2000-01-01 00:01'), 'rrule' => 'FREQ=MINUTELY']), 'min');
        $before = Settings::$maxRecurrences;

        $result = $this->service->expand($event, $this->utc('2026-01-01'), $this->utc('2026-01-02'));

        $this->assertNull($result);
        $this->assertSame($before, Settings::$maxRecurrences);
    }

    public function test_failed_expansion_keeps_the_stored_rows(): void
    {
        $event = $this->store(EventIcs::build(['uid' => 'bad', 'title' => 'Bad', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-20 10:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-20 11:00'), 'rrule' => 'FREQ=DAILY;COUNT=3']), 'bad');
        $this->assertSame(3, CalendarOccurrence::where('event_id', $event->id)->count());
        DB::table($event->getTable())->where('id', $event->id)->update(['ics' => 'BEGIN:VCALENDAR']);

        $this->service->refresh(CalendarEvent::find($event->id));

        $this->assertSame(3, CalendarOccurrence::where('event_id', $event->id)->count());
    }

    public function test_refresh_recurring_continues_after_a_corrupt_event(): void
    {
        $bad = $this->store(EventIcs::build(['uid' => 'bad2', 'title' => 'Bad', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-20 10:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-20 11:00'), 'rrule' => 'FREQ=DAILY;COUNT=2']), 'bad2');
        $good = $this->store(EventIcs::build(['uid' => 'good2', 'title' => 'Good', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-20 10:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-20 11:00'), 'rrule' => 'FREQ=DAILY;COUNT=2']), 'good2');
        DB::table($bad->getTable())->where('id', $bad->id)->update(['ics' => 'BEGIN:VCALENDAR']);
        CalendarOccurrence::where('event_id', $good->id)->delete();

        $this->service->refreshRecurring();

        $this->assertSame(2, CalendarOccurrence::where('event_id', $good->id)->count());
        $this->assertSame(2, CalendarOccurrence::where('event_id', $bad->id)->count());
    }

    public function test_find_returns_the_row_for_an_existing_key_and_null_for_an_excluded_one(): void
    {
        $vcal = EventIcs::build(['uid' => 'fx', 'title' => 'Daily', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-20 10:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-20 11:00'), 'rrule' => 'FREQ=DAILY;COUNT=3']);
        EventIcs::addExdate(EventIcs::master($vcal, 'fx'), '20261021T080000Z', new DateTimeZone('Europe/Amsterdam'));
        $event = $this->store($vcal, 'fx');

        $row = $this->service->find($event, '20261022T080000Z');

        $this->assertSame('2026-10-22 08:00:00', $row['starts_at']);
        $this->assertNull($this->service->find($event, '20261021T080000Z'));
    }

    public function test_refresh_command_moves_the_window(): void
    {
        $this->service->moveWindow(new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC')));

        $this->assertSame(0, $this->artisan('calendar:refresh-occurrences'));

        [$from] = $this->service->window();
        $this->assertGreaterThan(2024, (int) $from->format('Y'));
    }
}
