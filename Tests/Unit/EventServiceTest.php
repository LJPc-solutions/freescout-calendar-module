<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\Conversation;
use App\Thread;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Services\DropsExceptions;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\RecurrenceRule;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

class EventServiceTest extends TestCase
{
    private EventService $service;
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->service = app(EventService::class);
        $this->calendar = Calendar::create(['name' => 'Service ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
    }

    private function starts(CalendarEvent $event): array
    {
        return CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->pluck('starts_at')->all();
    }

    public function test_creates_a_timed_event_in_the_users_timezone(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $event = $this->service->create($this->calendar, $user, ['title' => 'Call', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00', 'location' => 'Office']);

        $this->assertSame('Call', $event->summary);
        $this->assertSame('2026-10-20 08:00:00', $event->starts_at);
        $this->assertSame($user->id, $event->author_id);
        $this->assertSame(['2026-10-20 08:00:00'], $this->starts($event));
        $this->assertStringContainsString('LOCATION:Office', $event->ics);
    }

    public function test_applies_title_template_and_validates_fields(): void
    {
        $this->calendar->update([
            'fields' => [['id' => 3, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']]],
            'title_template' => '{{title}} ({{field:3}})',
        ]);
        $user = $this->makeUser();

        $event = $this->service->create($this->calendar, $user, ['title' => 'Acme', 'start' => '2026-10-20T10:00', 'field_values' => ['3' => 'Visit']]);
        $this->assertSame('Acme (Visit)', $event->summary);
        $this->assertSame('Acme', $event->title_input);

        $updated = $this->service->update($event, $user, ['field_values' => ['3' => 'Call']]);
        $this->assertSame('Acme (Call)', $updated->summary);

        $this->expectException(ValidationException::class);
        $this->service->create($this->calendar, $user, ['title' => 'Acme', 'start' => '2026-10-20T10:00']);
    }

    public function test_series_edits_move_every_occurrence_and_drop_exceptions(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'Standup', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T10:15', 'recurrence' => ['freq' => 'DAILY', 'count' => 3]]);
        $this->service->delete($event->fresh(), EventService::SCOPE_OCCURRENCE, '20261021T080000Z');
        $this->assertCount(2, $this->starts($event));

        $moved = $this->service->update($event->fresh(), $user, ['start' => '2026-10-20T11:00', 'end' => '2026-10-20T11:15', 'drop_exceptions' => true]);

        $this->assertSame(['2026-10-20 09:00:00', '2026-10-21 09:00:00', '2026-10-22 09:00:00'], $this->starts($moved));
        $this->assertStringNotContainsString('EXDATE', $moved->ics);
        $this->assertSame(2, $moved->sequence); // one bump from the delete, one from the move
    }

    public function test_occurrence_edits_create_an_override(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'Standup', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T10:15', 'recurrence' => ['freq' => 'DAILY', 'count' => 3]]);

        $updated = $this->service->update($event, $user, ['scope' => 'occurrence', 'recurrence_id' => '20261021T080000Z', 'title' => 'Late standup', 'start' => '2026-10-21T14:00', 'end' => '2026-10-21T14:15']);

        $this->assertSame(['2026-10-20 08:00:00', '2026-10-21 12:00:00', '2026-10-22 08:00:00'], $this->starts($updated));
        $this->assertSame('Late standup', CalendarOccurrence::where('event_id', $event->id)->where('recurrence_id', '20261021T080000Z')->value('summary'));
        $this->assertSame('Standup', $updated->summary);
        $this->assertSame('Standup', $updated->title_input);
    }

    public function test_rejects_unknown_occurrences(): void
    {
        $event = $this->service->create($this->calendar, $this->makeUser(), ['title' => 'x', 'start' => '2026-10-20T10:00', 'recurrence' => ['freq' => 'WEEKLY', 'count' => 2]]);

        $this->expectException(ValidationException::class);
        $this->service->delete($event, EventService::SCOPE_OCCURRENCE, '20261021T080000Z');
    }

    public function test_deleting_a_series_removes_its_occurrences(): void
    {
        $event = $this->service->create($this->calendar, $this->makeUser(), ['title' => 'x', 'all_day' => true, 'start' => '2026-10-20']);
        $this->service->delete($event, EventService::SCOPE_SERIES, null);

        $this->assertNull(CalendarEvent::find($event->id));
        $this->assertSame(0, CalendarOccurrence::where('event_id', $event->id)->count());
    }

    public function test_conversation_events_add_a_line_item(): void
    {
        $user = $this->makeUser();
        $mailbox = $this->makeMailbox([$user->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE]);

        $event = $this->service->create($this->calendar, $user, ['title' => 'Follow up', 'start' => '2026-10-20T10:00'], $conversation);

        $thread = Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->first();
        $this->assertNotNull($thread);
        $this->assertSame(['event_id' => $event->id, 'calendar_id' => $this->calendar->id], $thread->getMetas());
        $this->assertSame($conversation->id, $event->conversation_id);
    }

    public function test_moving_to_another_calendar_revalidates_its_fields(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'x', 'start' => '2026-10-20T10:00']);
        $strict = Calendar::create(['name' => 'Strict ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'fields' => [['id' => 9, 'name' => 'Kind', 'type' => 'text', 'required' => true, 'options' => []]]]);

        try {
            $this->service->update($event, $user, ['calendar_id' => $strict->id]);
            $this->fail('Moved without the required field');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('field_values.9', $e->errors());
        }

        $moved = $this->service->update($event->fresh(), $user, ['calendar_id' => $strict->id, 'field_values' => ['9' => 'Call']]);
        $this->assertSame($strict->id, $moved->calendar_id);
        $this->assertSame($strict->id, CalendarOccurrence::where('event_id', $event->id)->value('calendar_id'));
    }

    private function daily(array $extra = [], ?\Modules\LJPcCalendarModule\Entities\Calendar $calendar = null): array
    {
        $user = $this->makeUser();
        $event = $this->service->create($calendar ?? $this->calendar, $user, $extra + ['title' => 'Standup', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T10:15', 'recurrence' => ['freq' => 'DAILY', 'count' => 3]]);

        return [$event, $user];
    }

    public function test_failed_conversation_line_item_rolls_back_the_event(): void
    {
        $user = $this->makeUser();
        $mailbox = $this->makeMailbox([$user->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE]);
        $conversation->setRawAttributes(['status' => null] + $conversation->getAttributes()); // in memory only: Thread::create then copies a null status into the NOT NULL column
        $events = CalendarEvent::count();
        $occurrences = CalendarOccurrence::count();

        try {
            $this->service->create($this->calendar, $user, ['title' => 'Rollback me', 'start' => '2026-10-20T10:00'], $conversation);
            $this->fail('Line item did not fail');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame(0, CalendarEvent::where('summary', 'Rollback me')->count());
            $this->assertSame($events, CalendarEvent::count());
            $this->assertSame($occurrences, CalendarOccurrence::count());
        }
    }

    public function test_occurrence_edits_reject_series_level_input(): void
    {
        [$event, $user] = $this->daily();
        $base = ['scope' => 'occurrence', 'recurrence_id' => '20261021T080000Z'];
        foreach (['field_values' => [], 'reminder_minutes' => 10, 'recurrence' => ['freq' => 'WEEKLY']] as $key => $value) {
            try {
                $this->service->update($event, $user, $base + [$key => $value]);
                $this->fail('Accepted ' . $key);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }
    }

    public function test_occurrence_scope_needs_a_recurring_event(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'Once', 'start' => '2026-10-20T10:00']);
        try {
            $this->service->update($event, $user, ['scope' => 'occurrence', 'recurrence_id' => '20261020T080000Z', 'title' => 'x']);
            $this->fail('Accepted occurrence scope');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scope', $e->errors());
        }
    }

    public function test_deleting_an_occurrence_needs_a_recurring_event(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'Once', 'start' => '2026-10-20T10:00']);
        try {
            $this->service->delete($event, EventService::SCOPE_OCCURRENCE, '20261020T080000Z');
            $this->fail('Deleted an occurrence of a single event');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scope', $e->errors());
        }
        $this->assertNotNull(CalendarEvent::find($event->id));
    }

    public function test_occurrence_time_edit_keeps_the_override_title(): void
    {
        [$event, $user] = $this->daily();
        $base = ['scope' => 'occurrence', 'recurrence_id' => '20261021T080000Z'];
        $this->service->update($event, $user, $base + ['title' => 'Late standup']);
        $this->service->update($event->fresh(), $user, $base + ['start' => '2026-10-21T14:00', 'end' => '2026-10-21T14:15']);

        $this->assertSame('Late standup', CalendarOccurrence::where('event_id', $event->id)->where('recurrence_id', '20261021T080000Z')->value('summary'));
    }

    public function test_series_title_change_reaches_untouched_overrides_only(): void
    {
        [$event, $user] = $this->daily();
        $this->service->update($event, $user, ['scope' => 'occurrence', 'recurrence_id' => '20261021T080000Z', 'start' => '2026-10-21T14:00', 'end' => '2026-10-21T14:15']);
        $this->service->update($event->fresh(), $user, ['scope' => 'occurrence', 'recurrence_id' => '20261022T080000Z', 'title' => 'Custom']);

        $this->service->update($event->fresh(), $user, ['title' => 'Daily sync']);

        // an occurrence row only stores a summary when it differs from the series
        $summary = fn ($key) => CalendarOccurrence::where('event_id', $event->id)->where('recurrence_id', $key)->value('summary') ?? $event->fresh()->summary;
        $this->assertSame('Daily sync', $summary('20261021T080000Z'));
        $this->assertSame('Custom', $summary('20261022T080000Z'));
        $this->assertSame('Daily sync', $summary('20261020T080000Z'));
    }

    public function test_title_only_series_edit_keeps_exceptions_and_bumps_sequence_once(): void
    {
        [$event, $user] = $this->daily();
        $this->service->delete($event->fresh(), EventService::SCOPE_OCCURRENCE, '20261021T080000Z');
        $this->service->update($event->fresh(), $user, ['scope' => 'occurrence', 'recurrence_id' => '20261022T080000Z', 'title' => 'Custom']);
        $before = $event->fresh();
        $sequence = $before->sequence;

        $after = $this->service->update($before, $user, ['title' => 'Renamed']);

        $this->assertStringContainsString('EXDATE', $after->ics);
        $this->assertSame(['2026-10-20 08:00:00', '2026-10-22 08:00:00'], $this->starts($after));
        $this->assertSame($sequence + 1, $after->sequence);
    }

    public function test_moving_calendars_keeps_the_summary_of_an_event_without_title_input(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'Imported', 'start' => '2026-10-20T10:00']);
        CalendarEvent::where('id', $event->id)->update(['title_input' => null]);
        $templated = Calendar::create(['name' => 'Templ ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'title_template' => 'X: {{title}}']);

        $moved = $this->service->update($event->fresh(), $user, ['calendar_id' => $templated->id]);

        $this->assertSame('Imported', $moved->summary);
    }

    public function test_failed_occurrence_validation_leaves_the_model_untouched(): void
    {
        [$event, $user] = $this->daily();
        $event = $event->fresh();
        $other = Calendar::create(['name' => 'Other ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $calendarId = $event->calendar_id;
        $fieldValues = $event->field_values;

        try {
            $this->service->update($event, $user, ['scope' => 'occurrence', 'recurrence_id' => '20300101T000000Z', 'calendar_id' => $other->id, 'title' => 'x']);
            $this->fail('Accepted unknown occurrence');
        } catch (ValidationException $e) {
            $this->assertFalse($event->isDirty());
            $this->assertSame($calendarId, $event->calendar_id);
            $this->assertSame($fieldValues, $event->field_values);
        }
    }

    public function test_line_item_persists_after_a_series_delete(): void
    {
        $user = $this->makeUser();
        $mailbox = $this->makeMailbox([$user->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE]);
        $event = $this->service->create($this->calendar, $user, ['title' => 'Gone', 'start' => '2026-10-20T10:00'], $conversation);

        $this->service->delete($event, EventService::SCOPE_SERIES, null);

        $this->assertSame(1, Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->count());
    }

    public function test_occurrence_edits_reject_calendar_moves(): void
    {
        [$event, $user] = $this->daily();
        $event = $event->fresh();
        $other = Calendar::create(['name' => 'Other ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $calendarId = $event->calendar_id;

        try {
            $this->service->update($event, $user, ['scope' => 'occurrence', 'recurrence_id' => '20261021T080000Z', 'calendar_id' => $other->id, 'title' => 'x']);
            $this->fail('Moved a single occurrence');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('calendar_id', $e->errors());
            $this->assertSame($calendarId, $event->calendar_id);
            $this->assertSame($calendarId, $event->fresh()->calendar_id);
        }
    }

    /**
     * A weekly Amsterdam series on Mondays in March 2027. US daylight saving time starts on 14 March and the EU's on
     * 28 March, so the instances of 15 and 22 March sit an hour closer to New York than the rest. The 22nd is deleted
     * (EXDATE) and the 15th is moved by a New York user (override).
     */
    private function amsterdamSeries(): array
    {
        $amsterdam = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $newYork = $this->makeUser(['timezone' => 'America/New_York']);
        $event = $this->service->create($this->calendar, $amsterdam, ['title' => 'Planning', 'start' => '2027-03-01T10:00', 'end' => '2027-03-01T11:00', 'recurrence' => ['freq' => 'WEEKLY', 'count' => 6]]);
        $this->service->delete($event->fresh(), EventService::SCOPE_OCCURRENCE, '20270322T090000Z');
        // 06:00 in New York (EDT) is 10:00 UTC, 11:00 in Amsterdam (CET).
        $this->service->update($event->fresh(), $newYork, ['scope' => 'occurrence', 'recurrence_id' => '20270315T090000Z', 'start' => '2027-03-15T06:00', 'end' => '2027-03-15T07:00']);

        return [$event->fresh(), $newYork];
    }

    private function recurrenceIds(CalendarEvent $event): array
    {
        return CalendarOccurrence::where('event_id', $event->id)->orderBy('recurrence_id')->pluck('recurrence_id')->all();
    }

    public function test_an_unchanged_time_saved_from_another_timezone_keeps_the_events_timezone_and_exceptions(): void
    {
        [$event, $newYork] = $this->amsterdamSeries();
        $this->assertStringContainsString("DTSTART;TZID=Europe/Amsterdam:20270315T110000\r\n", $event->ics);

        // 04:00 in New York (EST) is the series' own 10:00 in Amsterdam (CET): the same instant, saved with a new title.
        $saved = $this->service->update($event, $newYork, ['title' => 'Planning (renamed)', 'start' => '2027-03-01T04:00', 'end' => '2027-03-01T05:00']);

        $this->assertStringContainsString("DTSTART;TZID=Europe/Amsterdam:20270301T100000\r\n", $saved->ics);
        $this->assertStringContainsString("DTEND;TZID=Europe/Amsterdam:20270301T110000\r\n", $saved->ics);
        $this->assertStringNotContainsString('America/New_York', $saved->ics);
        $this->assertSame('Europe/Amsterdam', $saved->timezone);
        // The deleted 22nd stays deleted and the moved 15th replaces its own instance, also in the mismatch week.
        $this->assertSame(['20270301T090000Z', '20270308T090000Z', '20270315T090000Z', '20270329T080000Z', '20270405T080000Z'], $this->recurrenceIds($saved));
        $this->assertSame(['2027-03-01 09:00:00', '2027-03-08 09:00:00', '2027-03-15 10:00:00', '2027-03-29 08:00:00', '2027-04-05 08:00:00'], $this->starts($saved));
    }

    public function test_a_time_change_from_another_timezone_lands_at_the_right_instant_in_the_events_timezone(): void
    {
        [$event, $newYork] = $this->amsterdamSeries();

        // 05:00 in New York (EST) is 10:00 UTC, 11:00 in Amsterdam (CET).
        $moved = $this->service->update($event, $newYork, ['start' => '2027-03-01T05:00', 'end' => '2027-03-01T06:00', 'drop_exceptions' => true]);

        $this->assertStringContainsString("DTSTART;TZID=Europe/Amsterdam:20270301T110000\r\n", $moved->ics);
        $this->assertSame(['2027-03-01 10:00:00', 'Europe/Amsterdam'], [$moved->starts_at, $moved->timezone]);
        // Every instance stays at 11:00 in Amsterdam, across both clock changes.
        $this->assertSame(['2027-03-01 10:00:00', '2027-03-08 10:00:00', '2027-03-15 10:00:00', '2027-03-22 10:00:00', '2027-03-29 09:00:00', '2027-04-05 09:00:00'], $this->starts($moved));
    }

    public function test_a_repeat_end_date_set_from_another_timezone_ends_that_day_where_the_event_lives(): void
    {
        [$event, $newYork] = $this->amsterdamSeries();

        $ended = $this->service->update($event, $newYork, ['recurrence' => ['freq' => 'WEEKLY', 'until' => '2027-03-29'], 'drop_exceptions' => true]);

        // 23:59:59 on 29 March in Amsterdam (CEST), not in New York.
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;UNTIL=20270329T215959Z', $ended->ics);
        $starts = $this->starts($ended);
        $this->assertSame('2027-03-29 08:00:00', end($starts));
    }

    public function test_floating_and_utc_starts_keep_their_form_when_edited_from_another_timezone(): void
    {
        $this->calendar->update(['timezone' => 'Europe/Amsterdam']);
        $amsterdam = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $newYork = $this->makeUser(['timezone' => 'America/New_York']);
        $forms = [
            'floating' => ['DTSTART:20270301T100000', 'DTEND:20270301T110000', "DTSTART:20270301T110000\r\n"],
            'utc'      => ['DTSTART:20270301T090000Z', 'DTEND:20270301T100000Z', "DTSTART:20270301T100000Z\r\n"],
        ];
        foreach ($forms as $form => [$start, $end, $expected]) {
            $event = $this->service->create($this->calendar, $amsterdam, ['title' => $form, 'start' => '2027-03-01T10:00', 'end' => '2027-03-01T11:00']);
            $ics = str_replace(['DTSTART;TZID=Europe/Amsterdam:20270301T100000', 'DTEND;TZID=Europe/Amsterdam:20270301T110000'], [$start, $end], $event->ics);
            $event->ics = $ics;
            $event->fill(EventIcs::derive(Reader::read($ics), $event->uid, 'Europe/Amsterdam'));
            $event->save();

            // 05:00 in New York (EST) is 10:00 UTC, 11:00 in Amsterdam.
            $moved = $this->service->update($event->fresh(), $newYork, ['start' => '2027-03-01T05:00', 'end' => '2027-03-01T06:00']);

            $this->assertStringContainsString($expected, $moved->ics, $form);
            $this->assertStringNotContainsString('TZID', $moved->ics, $form);
            $this->assertSame('2027-03-01 10:00:00', $moved->starts_at, $form);
        }
    }

    /**
     * A weekly Amsterdam series from Monday 19 October 2026 whose RRULE another app wrote, with its second occurrence
     * deleted (EXDATE) and its third renamed (override).
     */
    private function foreignWeekly(string $rrule): array
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $event = $this->service->create($this->calendar, $user, ['title' => 'Planning', 'start' => '2026-10-19T10:00', 'end' => '2026-10-19T11:00', 'recurrence' => ['freq' => 'WEEKLY', 'byday' => ['MO']]]);
        $event->ics = preg_replace('/^RRULE:[^\r\n]*/m', 'RRULE:' . $rrule, $event->ics);
        $event->fill(EventIcs::derive(Reader::read($event->ics), $event->uid, 'Europe/Amsterdam'));
        $event->save();
        app(OccurrenceService::class)->refresh($event);
        [, $deleted, $renamed] = CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->limit(3)->pluck('recurrence_id')->all();
        $this->service->delete($event->fresh(), EventService::SCOPE_OCCURRENCE, $deleted);
        $this->service->update($event->fresh(), $user, ['scope' => 'occurrence', 'recurrence_id' => $renamed, 'title' => 'Planning (room B)']);
        $event = $event->fresh();
        $this->assertStringContainsString("RRULE:$rrule\r\n", $event->ics);

        return [$event, $user, $deleted, $renamed];
    }

    public function test_a_series_save_that_resends_an_equal_rule_keeps_its_text_and_exceptions(): void
    {
        $cases = [
            'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO;WKST=MO' => null,
            'FREQ=WEEKLY;BYDAY=MO;INTERVAL=2'         => null,
            // The form lists weekdays from Monday, whatever order another app wrote them in.
            'FREQ=WEEKLY;BYDAY=WE,MO'                 => ['freq' => 'WEEKLY', 'interval' => 1, 'byday' => ['MO', 'WE']],
        ];
        foreach ($cases as $rrule => $recurrence) {
            [$event, $user, $deleted, $renamed] = $this->foreignWeekly($rrule);
            $sequence = $event->sequence;

            // The series form sends the rule back as the details presented it, with a new title.
            $recurrence = $recurrence ?? RecurrenceRule::fromRrule($rrule, 'Europe/Amsterdam');
            $saved = $this->service->update($event, $user, ['scope' => 'series', 'title' => 'Planning renamed', 'recurrence' => $recurrence]);

            $this->assertStringContainsString("RRULE:$rrule\r\n", $saved->ics, $rrule);
            $this->assertStringContainsString('EXDATE', $saved->ics, $rrule);
            $this->assertNotContains($deleted, $this->recurrenceIds($saved), $rrule);
            $this->assertSame('Planning (room B)', CalendarOccurrence::where('event_id', $event->id)->where('recurrence_id', $renamed)->value('summary'), $rrule);
            $this->assertSame('Planning renamed', $saved->summary, $rrule);
            $this->assertSame($sequence + 1, $saved->sequence, $rrule);
        }
    }

    public function test_a_rule_change_that_would_drop_exceptions_waits_for_confirmation_and_leaves_the_model_untouched(): void
    {
        [$event, $user] = $this->foreignWeekly('FREQ=WEEKLY;INTERVAL=1;BYDAY=MO;WKST=MO');
        $other = Calendar::create(['name' => 'Other ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $ics = $event->ics;

        try {
            $this->service->update($event, $user, ['scope' => 'series', 'calendar_id' => $other->id, 'title' => 'x', 'recurrence' => ['freq' => 'DAILY', 'interval' => 1]]);
            $this->fail('Dropped the exceptions without confirmation');
        } catch (DropsExceptions $e) {
            $this->assertFalse($event->isDirty());
            $this->assertSame($ics, $event->ics);
            $this->assertSame($this->calendar->id, $event->calendar->id);
            $this->assertSame($ics, $event->fresh()->ics);
        }
    }

    public function test_a_real_rule_change_still_drops_the_exceptions(): void
    {
        [$event, $user, $deleted] = $this->foreignWeekly('FREQ=WEEKLY;INTERVAL=1;BYDAY=MO;WKST=MO');

        $saved = $this->service->update($event, $user, ['scope' => 'series', 'title' => 'Planning renamed', 'recurrence' => ['freq' => 'WEEKLY', 'interval' => 2, 'byday' => ['MO']], 'drop_exceptions' => true]);

        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO\r\n", $saved->ics);
        $this->assertStringNotContainsString('EXDATE', $saved->ics);
        $this->assertStringNotContainsString('RECURRENCE-ID', $saved->ics);
        $this->assertSame(['2026-10-19 08:00:00', '2026-11-02 09:00:00', '2026-11-16 09:00:00'], array_slice($this->starts($saved), 0, 3));
        $this->assertNull(CalendarOccurrence::where('event_id', $event->id)->whereNotNull('summary')->value('summary'));
    }

    public function test_switching_all_day_rewrites_an_equal_rule_because_its_end_date_changes_form(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $recurrence = ['freq' => 'WEEKLY', 'byday' => ['MO'], 'until' => '2026-11-30'];
        $event = $this->service->create($this->calendar, $user, ['title' => 'Planning', 'start' => '2026-10-19T10:00', 'end' => '2026-10-19T11:00', 'recurrence' => $recurrence]);
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261130T225959Z\r\n", $event->ics);

        $saved = $this->service->update($event, $user, ['all_day' => true, 'start' => '2026-10-19', 'end' => '2026-10-20', 'recurrence' => $recurrence]);

        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261130\r\n", $saved->ics);
        $this->assertCount(7, $this->starts($saved));
    }

    public function test_switching_all_day_without_a_start_is_rejected(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'Call', 'start' => '2026-10-20T10:00']);

        try {
            $this->service->update($event, $user, ['all_day' => true]);
            $this->fail('Accepted all_day without a start');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start', $e->errors());
        }
        $this->assertFalse((bool) $event->fresh()->all_day);
    }

    public function test_a_series_copy_is_written_in_the_series_zone(): void
    {
        $losAngeles = $this->makeUser(['timezone' => 'America/Los_Angeles']);

        // The copy form shows the series' Monday 08:00 in Amsterdam as Sunday 23:00 in Los Angeles, with the series' rule.
        $copy = $this->service->create($this->calendar, $losAngeles, [
            'title'      => 'Planning',
            'start'      => '2026-10-18T23:00',
            'end'        => '2026-10-19T00:00',
            'recurrence' => ['freq' => 'WEEKLY', 'byday' => ['MO'], 'until' => '2026-11-30'],
            'timezone'   => 'Europe/Amsterdam',
        ]);

        $this->assertStringContainsString("DTSTART;TZID=Europe/Amsterdam:20261019T080000\r\n", $copy->ics);
        $this->assertStringContainsString("DTEND;TZID=Europe/Amsterdam:20261019T090000\r\n", $copy->ics);
        // The end date is the end of 30 November in Amsterdam (CET), not in Los Angeles.
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261130T225959Z\r\n", $copy->ics);
        $this->assertSame('Europe/Amsterdam', $copy->timezone);
        // Every occurrence is a Monday at 08:00 in Amsterdam, before and after its clock change on 25 October.
        $this->assertSame([
            '2026-10-19 06:00:00', '2026-10-26 07:00:00', '2026-11-02 07:00:00', '2026-11-09 07:00:00',
            '2026-11-16 07:00:00', '2026-11-23 07:00:00', '2026-11-30 07:00:00',
        ], $this->starts($copy));
    }

    public function test_the_series_zone_only_applies_to_a_timed_series(): void
    {
        $losAngeles = $this->makeUser(['timezone' => 'America/Los_Angeles']);

        $single = $this->service->create($this->calendar, $losAngeles, ['title' => 'Call', 'start' => '2026-10-18T23:00', 'end' => '2026-10-19T00:00', 'timezone' => 'Europe/Amsterdam']);
        $allDay = $this->service->create($this->calendar, $losAngeles, ['title' => 'Away', 'all_day' => true, 'start' => '2026-10-19', 'end' => '2026-10-20', 'recurrence' => ['freq' => 'WEEKLY'], 'timezone' => 'Europe/Amsterdam']);

        $this->assertStringContainsString("DTSTART;TZID=America/Los_Angeles:20261018T230000\r\n", $single->ics);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261019\r\n", $allDay->ics);
        $this->assertNull($allDay->timezone);
    }

    /** A weekly series on Mondays at 10:00 from 19 October 2026, floating (no TZID): it is read in its calendar's zone. */
    private function floatingWeekly(): CalendarEvent
    {
        $this->calendar->update(['timezone' => 'Europe/Amsterdam']);
        $event = $this->service->create($this->calendar, $this->makeUser(['timezone' => 'Europe/Amsterdam']), ['title' => 'Planning', 'start' => '2026-10-19T10:00', 'end' => '2026-10-19T11:00', 'recurrence' => ['freq' => 'WEEKLY', 'count' => 8]]);
        $event->ics = str_replace(['DTSTART;TZID=Europe/Amsterdam:', 'DTEND;TZID=Europe/Amsterdam:'], ['DTSTART:', 'DTEND:'], $event->ics);
        $event->fill(EventIcs::derive(Reader::read($event->ics), $event->uid, 'Europe/Amsterdam'));
        $event->save();
        app(OccurrenceService::class)->refresh($event);
        $this->assertStringNotContainsString('TZID', $event->ics);

        return $event->fresh();
    }

    public function test_a_floating_series_gets_its_end_date_in_local_time(): void
    {
        $event = $this->floatingWeekly();
        $losAngeles = $this->makeUser(['timezone' => 'America/Los_Angeles']);

        $ended = $this->service->update($event, $losAngeles, ['recurrence' => ['freq' => 'WEEKLY', 'until' => '2026-11-30']]);

        // RFC 5545: with a floating start, UNTIL is local time, the end of that day wherever the calendar is read.
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;UNTIL=20261130T235959\r\n", $ended->ics);
        $starts = $this->starts($ended);
        $this->assertCount(7, $starts);
        $this->assertSame('2026-11-30 09:00:00', end($starts));
    }

    public function test_an_occurrence_of_a_floating_series_stays_floating_after_all_day_and_back(): void
    {
        $event = $this->floatingWeekly();
        $newYork = $this->makeUser(['timezone' => 'America/New_York']);
        $key = '20261026T090000Z'; // Monday 26 October, 10:00 in Amsterdam (CET)

        $this->service->update($event, $newYork, ['scope' => 'occurrence', 'recurrence_id' => $key, 'all_day' => true, 'start' => '2026-10-26', 'end' => '2026-10-27']);
        // 06:00 in New York (EDT) is 10:00 UTC, 11:00 in Amsterdam.
        $saved = $this->service->update($event->fresh(), $newYork, ['scope' => 'occurrence', 'recurrence_id' => $key, 'all_day' => false, 'start' => '2026-10-26T06:00', 'end' => '2026-10-26T07:00']);

        $override = EventIcs::findOverride(Reader::read($saved->ics), $saved->uid, $key, new DateTimeZone('Europe/Amsterdam'));
        $this->assertSame("DTSTART:20261026T110000\r\n", $override->DTSTART->serialize());
        $this->assertSame("DTEND:20261026T120000\r\n", $override->DTEND->serialize());
        $this->assertStringNotContainsString('TZID', $saved->ics);
        $this->assertSame('2026-10-26 10:00:00', CalendarOccurrence::where('event_id', $event->id)->where('recurrence_id', $key)->value('starts_at'));
    }

    public function test_a_custom_rule_only_matches_its_own_text(): void
    {
        $rrule = 'FREQ=WEEKLY;BYDAY=MO;BYHOUR=10';
        [$event, $user, $deleted] = $this->foreignWeekly($rrule);
        $this->assertNull(RecurrenceRule::fromRrule($rrule, 'Europe/Amsterdam'));

        // The form can't show this rule, so any rule it sends is a change, which resets the exceptions after a question.
        try {
            $this->service->update($event, $user, ['scope' => 'series', 'title' => 'Planning renamed', 'recurrence' => ['freq' => 'WEEKLY', 'byday' => ['MO']]]);
            $this->fail('Took a resent rule for the custom one');
        } catch (DropsExceptions $e) {
            $this->assertStringContainsString("RRULE:$rrule\r\n", $event->fresh()->ics);
        }

        // A save without a rule keeps the custom rule and its exceptions.
        $saved = $this->service->update($event->fresh(), $user, ['scope' => 'series', 'title' => 'Planning renamed']);
        $this->assertStringContainsString("RRULE:$rrule\r\n", $saved->ics);
        $this->assertNotContains($deleted, $this->recurrenceIds($saved));
    }
}
