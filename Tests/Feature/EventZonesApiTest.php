<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

/** The zone of a series in the agent API: where a copy is written, and in which zone a rule's end date is shown and compared. */
class EventZonesApiTest extends TestCase
{
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->calendar = Calendar::create(['name' => 'Zones ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'timezone' => 'Europe/Amsterdam']);
    }

    private function agent(string $timezone): User
    {
        $user = $this->makeUser(['timezone' => $timezone]);
        CalendarPermission::create(['calendar_id' => $this->calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);

        return $user;
    }

    /** An event another app wrote: $vevents holds its components, with {uid} for the UID. */
    private function foreign(string $vevents): CalendarEvent
    {
        $uid = 'foreign-' . uniqid() . '@example.com';
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Other app//EN\r\n" . str_replace('{uid}', $uid, $vevents) . "END:VCALENDAR\r\n";
        $event = new CalendarEvent(['calendar_id' => $this->calendar->id, 'field_values' => []]);
        $event->uid = $uid;
        $event->ics = $ics;
        $event->fill(EventIcs::derive(Reader::read($ics), $uid, $this->calendar->timezoneName()));
        $event->save();
        app(OccurrenceService::class)->refresh($event);

        return $event->fresh();
    }

    private function show(User $user, CalendarEvent $event): array
    {
        return $this->actingAs($user)->json('GET', '/calendar/api/events/e' . $event->id)->assertStatus(200)->json();
    }

    private function recurrenceIds(CalendarEvent $event): array
    {
        return CalendarOccurrence::where('event_id', $event->id)->orderBy('recurrence_id')->pluck('recurrence_id')->all();
    }

    public function test_the_details_name_the_zone_a_timed_event_lives_in(): void
    {
        $user = $this->agent('Asia/Tokyo');
        $zoned = $this->foreign("BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261001T000000Z\r\nDTSTART;TZID=America/New_York:20261019T090000\r\nDTEND;TZID=America/New_York:20261019T100000\r\nSUMMARY:Zoned\r\nEND:VEVENT\r\n");
        $utc = $this->foreign("BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261001T000000Z\r\nDTSTART:20261019T090000Z\r\nDTEND:20261019T100000Z\r\nSUMMARY:Utc\r\nEND:VEVENT\r\n");
        $floating = $this->foreign("BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261001T000000Z\r\nDTSTART:20261019T090000\r\nDTEND:20261019T100000\r\nSUMMARY:Floating\r\nEND:VEVENT\r\n");
        $allDay = $this->foreign("BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261001T000000Z\r\nDTSTART;VALUE=DATE:20261019\r\nDTEND;VALUE=DATE:20261020\r\nSUMMARY:Day\r\nEND:VEVENT\r\n");

        $this->assertSame('America/New_York', $this->show($user, $zoned)['timezone']);
        $this->assertSame('UTC', $this->show($user, $utc)['timezone']);
        // A floating event lives on its calendar's clock, so a copy is written in the calendar's zone.
        $this->assertSame('Europe/Amsterdam', $this->show($user, $floating)['timezone']);
        $this->assertNull($this->show($user, $allDay)['timezone']);
    }

    public function test_a_whole_series_copy_from_another_zone_keeps_its_weekday(): void
    {
        $user = $this->agent('America/Los_Angeles');

        // A Monday 08:00 Amsterdam series, which the copy form shows as Sunday 23:00 in Los Angeles.
        $response = $this->actingAs($user)->json('POST', '/calendar/api/events', [
            'calendar_id' => $this->calendar->id, 'title' => 'Planning', 'start' => '2026-10-18T23:00', 'end' => '2026-10-19T00:00',
            'recurrence'  => ['freq' => 'WEEKLY', 'interval' => 1, 'byday' => ['MO'], 'until' => '2026-11-30'], 'timezone' => 'Europe/Amsterdam',
        ]);

        $response->assertStatus(201);
        $copy = $response->json();
        $this->assertSame(['Europe/Amsterdam', '2026-10-18T23:00:00-07:00'], [$copy['timezone'], $copy['start']]);
        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'byday' => ['MO'], 'until' => '2026-11-30'], $copy['recurrence']);
        $ics = CalendarEvent::find($copy['id'])->ics;
        $this->assertStringContainsString("DTSTART;TZID=Europe/Amsterdam:20261019T080000\r\n", $ics);
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261130T225959Z\r\n", $ics);
    }

    public function test_a_zone_must_be_a_named_time_zone(): void
    {
        $user = $this->agent('America/Los_Angeles');
        $body = ['calendar_id' => $this->calendar->id, 'title' => 'Planning', 'start' => '2026-10-18T23:00', 'recurrence' => ['freq' => 'DAILY']];

        // Offsets and abbreviations would be written as a TZID no calendar app resolves; the system's tzdata also lists
        // files that are no zone at all.
        foreach (['Mars/Olympus', '+02:00', 'GMT+0100', 'localtime', 'tzdata.zi', ['Europe/Amsterdam']] as $zone) {
            $response = $this->actingAs($user)->json('POST', '/calendar/api/events', $body + ['timezone' => $zone]);
            $response->assertStatus(422);
            $this->assertArrayHasKey('timezone', $response->json()['errors'], json_encode($zone));
        }
        $this->assertSame(0, CalendarEvent::where('calendar_id', $this->calendar->id)->count());

        // An older name PHP still knows is a name.
        $this->actingAs($user)->json('POST', '/calendar/api/events', $body + ['timezone' => 'Europe/Kiev'])->assertStatus(201);
        $this->assertStringContainsString("DTSTART;TZID=Europe/Kiev:20261019T090000\r\n", CalendarEvent::where('calendar_id', $this->calendar->id)->value('ics'));
    }

    /** Saves the series with a new title, sending its rule back exactly as the details showed it, as the series form does. */
    private function renameWithShownRule(User $user, CalendarEvent $event): array
    {
        $shown = $this->show($user, $event);
        $this->assertNotNull($shown['recurrence'], 'The details show the rule');
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $event->id, ['scope' => 'series', 'title' => 'Renamed', 'recurrence' => $shown['recurrence']])->assertStatus(200);

        return $shown;
    }

    public function test_a_rule_anchored_in_utc_round_trips_with_its_exceptions(): void
    {
        $user = $this->agent('Asia/Tokyo');
        $event = $this->foreign(
            "BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261001T000000Z\r\nDTSTART:20261019T080000Z\r\nDTEND:20261019T090000Z\r\n"
            . "RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261130T080000Z\r\nEXDATE:20261026T080000Z\r\nSUMMARY:Sync\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261001T000000Z\r\nRECURRENCE-ID:20261102T080000Z\r\nDTSTART:20261102T100000Z\r\nDTEND:20261102T110000Z\r\nSUMMARY:Sync (moved)\r\nEND:VEVENT\r\n"
        );
        $ids = $this->recurrenceIds($event);

        $shown = $this->renameWithShownRule($user, $event);

        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'byday' => ['MO'], 'until' => '2026-11-30'], $shown['recurrence']);
        $saved = $event->fresh();
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261130T080000Z\r\n", $saved->ics);
        $this->assertStringContainsString("EXDATE:20261026T080000Z\r\n", $saved->ics);
        $this->assertSame($ids, $this->recurrenceIds($saved));
        $this->assertSame('Sync (moved)', CalendarOccurrence::where('event_id', $event->id)->where('recurrence_id', '20261102T080000Z')->value('summary'));
        $this->assertSame($shown['recurrence'], $this->show($user, $saved)['recurrence']);
    }

    public function test_a_floating_rule_with_a_local_end_round_trips_with_its_exceptions(): void
    {
        $user = $this->agent('Asia/Tokyo');
        $event = $this->foreign(
            "BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261001T000000Z\r\nDTSTART:20261019T100000\r\nDTEND:20261019T110000\r\n"
            . "RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261130T100000\r\nEXDATE:20261026T100000\r\nSUMMARY:Planning\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261001T000000Z\r\nRECURRENCE-ID:20261102T100000\r\nDTSTART:20261102T140000\r\nDTEND:20261102T150000\r\nSUMMARY:Planning (moved)\r\nEND:VEVENT\r\n"
        );
        $ids = $this->recurrenceIds($event);
        $this->assertCount(6, $ids);

        $shown = $this->renameWithShownRule($user, $event);

        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'byday' => ['MO'], 'until' => '2026-11-30'], $shown['recurrence']);
        $this->assertFalse($shown['recurrence_custom']);
        $saved = $event->fresh();
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261130T100000\r\n", $saved->ics);
        $this->assertStringContainsString("EXDATE:20261026T100000\r\n", $saved->ics);
        $this->assertSame($ids, $this->recurrenceIds($saved));
        $this->assertSame('Planning (moved)', CalendarOccurrence::where('event_id', $event->id)->where('recurrence_id', '20261102T090000Z')->value('summary'));
    }

    public function test_an_all_day_rule_with_a_utc_end_shows_the_same_end_date_to_every_agent(): void
    {
        $losAngeles = $this->agent('America/Los_Angeles');
        $tokyo = $this->agent('Asia/Tokyo');
        // Midnight of 30 November in Amsterdam, written as UTC by another app: the last occurrence is that Monday.
        $event = $this->foreign(
            "BEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261001T000000Z\r\nDTSTART;VALUE=DATE:20261019\r\nDTEND;VALUE=DATE:20261020\r\n"
            . "RRULE:FREQ=WEEKLY;UNTIL=20261129T230000Z\r\nEXDATE;VALUE=DATE:20261026\r\nSUMMARY:Away\r\nEND:VEVENT\r\n"
        );
        $ids = $this->recurrenceIds($event);
        $this->assertSame('20261130', end($ids));

        $this->assertSame('2026-11-30', $this->show($tokyo, $event)['recurrence']['until']);
        $shown = $this->renameWithShownRule($losAngeles, $event);

        $this->assertSame('2026-11-30', $shown['recurrence']['until']);
        $saved = $event->fresh();
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;UNTIL=20261129T230000Z\r\n", $saved->ics);
        $this->assertStringContainsString("EXDATE;VALUE=DATE:20261026\r\n", $saved->ics);
        $this->assertSame($ids, $this->recurrenceIds($saved));
    }
}
