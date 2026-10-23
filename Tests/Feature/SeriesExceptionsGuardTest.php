<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** A whole-series change that would reset edited and deleted occurrences waits for the agent's confirmation (409). */
class SeriesExceptionsGuardTest extends TestCase
{
    private const QUESTION = 'Edited and deleted occurrences of this series will be reset. Continue?';

    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $this->calendar = $this->editableCalendar();
    }

    private function editableCalendar(): Calendar
    {
        $calendar = Calendar::create(['name' => 'Guard ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $this->user->id, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);

        return $calendar;
    }

    /** A daily series from Tuesday 20 October 2026, 10:00 to 10:15 in Amsterdam (08:00 UTC), four times. */
    private function series(): int
    {
        $response = $this->actingAs($this->user)->json('POST', '/calendar/api/events', [
            'calendar_id' => $this->calendar->id, 'title' => 'Standup', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T10:15',
            'recurrence'  => ['freq' => 'DAILY', 'count' => 4],
        ]);
        $response->assertStatus(201);

        return json_decode($response->getContent(), true)['id'];
    }

    private function editOccurrence(int $id, string $key, array $changes): void
    {
        $this->patchEvent($id, ['scope' => 'occurrence', 'recurrence_id' => $key] + $changes)->assertStatus(200);
    }

    private function deleteOccurrence(int $id, string $key): void
    {
        $this->actingAs($this->user)->json('DELETE', '/calendar/api/events/' . $id . '?scope=occurrence&recurrence_id=' . $key)->assertStatus(200);
    }

    private function patchEvent(int $id, array $body)
    {
        return $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $id, $body);
    }

    /** Everything a save could touch: the event row and its occurrence rows. */
    private function snapshot(int $id): array
    {
        $event = CalendarEvent::find($id);

        return [
            'ics'         => $event->ics,
            'sequence'    => $event->sequence,
            'calendar_id' => $event->calendar_id,
            'starts_at'   => $event->starts_at,
            'ends_at'     => $event->ends_at,
            'summary'     => $event->summary,
            'updated_at'  => (string) $event->updated_at,
            'occurrences' => CalendarOccurrence::where('event_id', $id)->orderBy('recurrence_id')->get(['recurrence_id', 'calendar_id', 'starts_at', 'ends_at', 'summary'])->toArray(),
        ];
    }

    private function assertAsksFirst($response): void
    {
        $response->assertStatus(409);
        $this->assertSame(['code' => 'drops_exceptions', 'message' => self::QUESTION], json_decode($response->getContent(), true));
    }

    public function test_a_time_move_of_a_series_with_an_override_is_409_and_saves_nothing(): void
    {
        $id = $this->series();
        $this->editOccurrence($id, '20261022T080000Z', ['title' => 'Standup (room B)']);
        $other = $this->editableCalendar();
        $before = $this->snapshot($id);

        // A move to another calendar in the same request must not be saved either.
        $this->assertAsksFirst($this->patchEvent($id, ['scope' => 'series', 'start' => '2026-10-20T11:00', 'end' => '2026-10-20T11:15', 'calendar_id' => $other->id]));

        $this->assertSame($before, $this->snapshot($id));
        $this->assertStringContainsString('RECURRENCE-ID', CalendarEvent::find($id)->ics);
    }

    public function test_the_same_move_with_drop_exceptions_drops_them(): void
    {
        $id = $this->series();
        $this->editOccurrence($id, '20261022T080000Z', ['title' => 'Standup (room B)']);
        $this->deleteOccurrence($id, '20261021T080000Z');
        $other = $this->editableCalendar();

        $response = $this->patchEvent($id, ['scope' => 'series', 'start' => '2026-10-20T11:00', 'end' => '2026-10-20T11:15', 'calendar_id' => $other->id, 'drop_exceptions' => true]);

        $response->assertStatus(200);
        $event = CalendarEvent::find($id);
        $this->assertStringNotContainsString('RECURRENCE-ID', $event->ics);
        $this->assertStringNotContainsString('EXDATE', $event->ics);
        $this->assertSame($other->id, $event->calendar_id);
        $occurrences = CalendarOccurrence::where('event_id', $id)->orderBy('starts_at')->get(['starts_at', 'summary']);
        $this->assertSame(['2026-10-20 09:00:00', '2026-10-21 09:00:00', '2026-10-22 09:00:00', '2026-10-23 09:00:00'], $occurrences->pluck('starts_at')->all());
        $this->assertSame([null, null, null, null], $occurrences->pluck('summary')->all());
    }

    public function test_an_exdate_alone_triggers_the_guard_for_a_move_and_for_a_rule_change(): void
    {
        $id = $this->series();
        $this->deleteOccurrence($id, '20261021T080000Z');
        $before = $this->snapshot($id);
        $this->assertStringNotContainsString('RECURRENCE-ID', $before['ics']);

        $this->assertAsksFirst($this->patchEvent($id, ['scope' => 'series', 'start' => '2026-10-20T11:00', 'end' => '2026-10-20T11:15']));
        $this->assertAsksFirst($this->patchEvent($id, ['scope' => 'series', 'recurrence' => ['freq' => 'DAILY', 'count' => 6]]));
        // false, as the boolean rule allows it, is the same as leaving the flag out.
        $this->assertAsksFirst($this->patchEvent($id, ['scope' => 'series', 'start' => '2026-10-20T11:00', 'end' => '2026-10-20T11:15', 'drop_exceptions' => false]));

        $this->assertSame($before, $this->snapshot($id));
    }

    public function test_a_title_only_change_of_a_series_with_exceptions_saves_without_the_flag(): void
    {
        $id = $this->series();
        $this->editOccurrence($id, '20261022T080000Z', ['title' => 'Standup (room B)']);
        $this->deleteOccurrence($id, '20261021T080000Z');

        // The series form sends the unchanged rule along with the new title.
        $this->patchEvent($id, ['scope' => 'series', 'title' => 'Daily standup', 'recurrence' => ['freq' => 'DAILY', 'interval' => 1, 'count' => 4]])->assertStatus(200);

        $event = CalendarEvent::find($id);
        $this->assertSame('Daily standup', $event->summary);
        $this->assertStringContainsString('EXDATE', $event->ics);
        $this->assertSame('Standup (room B)', CalendarOccurrence::where('event_id', $id)->where('recurrence_id', '20261022T080000Z')->value('summary'));
    }

    public function test_a_whole_series_resize_keeps_the_exceptions_without_the_flag(): void
    {
        $id = $this->series();
        $this->editOccurrence($id, '20261022T080000Z', ['title' => 'Standup (room B)']);
        $this->deleteOccurrence($id, '20261021T080000Z');

        // What the calendar sends after resizing the series: its own start unchanged, a later end and the same rule.
        $this->patchEvent($id, [
            'scope'      => 'series', 'all_day' => false, 'start' => '2026-10-20T10:00:00+02:00', 'end' => '2026-10-20T10:45:00+02:00',
            'recurrence' => ['freq' => 'DAILY', 'interval' => 1, 'count' => 4],
        ])->assertStatus(200);

        $event = CalendarEvent::find($id);
        $this->assertSame('2026-10-20 08:45:00', $event->ends_at);
        $this->assertStringContainsString('EXDATE', $event->ics);
        $this->assertSame('Standup (room B)', CalendarOccurrence::where('event_id', $id)->where('recurrence_id', '20261022T080000Z')->value('summary'));
    }

    public function test_a_schedule_change_of_a_series_without_exceptions_saves_without_the_flag(): void
    {
        $id = $this->series();

        $this->patchEvent($id, ['scope' => 'series', 'start' => '2026-10-20T11:00', 'end' => '2026-10-20T11:15', 'recurrence' => ['freq' => 'DAILY', 'count' => 2]])->assertStatus(200);

        $this->assertSame(['2026-10-20 09:00:00', '2026-10-21 09:00:00'], CalendarOccurrence::where('event_id', $id)->orderBy('starts_at')->pluck('starts_at')->all());
    }

    public function test_the_occurrence_scope_never_triggers_the_guard(): void
    {
        $id = $this->series();
        $this->editOccurrence($id, '20261022T080000Z', ['title' => 'Standup (room B)']);
        $this->deleteOccurrence($id, '20261021T080000Z');

        $this->editOccurrence($id, '20261023T080000Z', ['start' => '2026-10-23T14:00', 'end' => '2026-10-23T14:15']);

        $event = CalendarEvent::find($id);
        $this->assertStringContainsString('EXDATE', $event->ics);
        $this->assertSame('Standup (room B)', CalendarOccurrence::where('event_id', $id)->where('recurrence_id', '20261022T080000Z')->value('summary'));
        $this->assertSame('2026-10-23 12:00:00', CalendarOccurrence::where('event_id', $id)->where('recurrence_id', '20261023T080000Z')->value('starts_at'));
    }

    public function test_drop_exceptions_must_be_a_boolean(): void
    {
        $id = $this->series();

        $this->patchEvent($id, ['scope' => 'series', 'title' => 'x', 'drop_exceptions' => 'please'])->assertStatus(422)->assertJsonValidationErrors(['drop_exceptions']);
    }
}
