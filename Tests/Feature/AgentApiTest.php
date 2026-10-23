<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use App\Thread;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

class AgentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
    }

    private function calendarFor($user, array $rights, array $attributes = []): Calendar
    {
        $calendar = Calendar::create($attributes + ['name' => 'Api ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id] + $rights);

        return $calendar;
    }

    private function body(Calendar $calendar, array $overrides = []): array
    {
        return $overrides + ['calendar_id' => $calendar->id, 'title' => 'Call', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00'];
    }

    public function test_calendar_list_only_shows_viewable_calendars_without_secrets(): void
    {
        $user = $this->makeUser();
        $visible = $this->calendarFor($user, ['can_view' => true]);
        $hidden = Calendar::create(['name' => 'Hidden ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);

        $response = $this->actingAs($user)->json('GET', '/calendar/api/calendars');

        $response->assertStatus(200);
        $ids = array_column(json_decode($response->getContent(), true), 'id');
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        foreach (['export_token', 'export_busy_token', 'permissions', 'sync_state', 'custom_fields'] as $secret) {
            $this->assertStringNotContainsString('"' . $secret . '"', $response->getContent());
        }
    }

    public function test_create_needs_the_create_right_and_returns_times_in_the_agents_timezone(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $readOnly = $this->calendarFor($user, ['can_view' => true]);
        $writable = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);

        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($readOnly))->assertStatus(403);

        $response = $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($writable));
        $response->assertStatus(201)->assertJsonFragment(['start' => '2026-10-20T10:00:00+02:00', 'title' => 'Call']);
        $this->assertSame($user->id, json_decode($response->getContent(), true)['author']['id']);
    }

    public function test_validation_errors_are_422(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);

        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar, ['end' => '2026-10-20T09:00']))->assertStatus(422);
        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar, ['recurrence' => ['freq' => 'HOURLY']]))->assertStatus(422);
        $this->actingAs($user)->json('GET', '/calendar/api/events?start=2026-01-01&end=2028-01-01')->assertStatus(422);
    }

    public function test_range_returns_occurrences_of_viewable_calendars_only(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $mine = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $other = $this->makeUser();
        $theirs = $this->calendarFor($other, ['can_view' => true, 'can_create' => true]);
        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($mine, ['recurrence' => ['freq' => 'DAILY', 'count' => 2]]))->assertStatus(201);
        $this->actingAs($other)->json('POST', '/calendar/api/events', $this->body($theirs))->assertStatus(201);

        $rows = json_decode($this->actingAs($user)->json('GET', '/calendar/api/events?start=2026-10-19T00:00:00%2B02:00&end=2026-10-26T00:00:00%2B02:00')->getContent(), true);

        $this->assertSame([$mine->id, $mine->id], array_column($rows, 'calendar_id'));
        $this->assertSame('2026-10-21T10:00:00+02:00', $rows[1]['start']);
        $this->assertStringContainsString(':20261021T080000Z', $rows[1]['id']);
        $this->assertFalse($rows[0]['editable']); // the view and create rights don't include edit
    }

    public function test_update_and_delete_need_the_edit_right_on_the_events_own_calendar(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $id = json_decode($this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar))->getContent(), true)['id'];

        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $id, ['title' => 'Changed'])->assertStatus(403);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $id)->assertStatus(403);

        CalendarPermission::where('calendar_id', $calendar->id)->update(['can_edit' => true]);
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $id, ['title' => 'Changed'])->assertStatus(200)->assertJsonFragment(['title' => 'Changed']);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $id)->assertStatus(200);
        $this->assertNull(CalendarEvent::find($id));
    }

    public function test_events_in_read_only_providers_cannot_be_edited(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true, 'can_edit' => true], ['provider' => Calendar::PROVIDER_ICS]);

        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar))->assertStatus(403);
    }

    public function test_show_finds_by_permalink_id_legacy_id_and_uid_but_only_when_visible(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $id = json_decode($this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar))->getContent(), true)['id'];
        $event = CalendarEvent::find($id);
        $event->update(['legacy_id' => '291']);

        $this->actingAs($user)->json('GET', '/calendar/api/events/e' . $id)->assertStatus(200)->assertJsonFragment(['id' => $id]);
        $this->actingAs($user)->json('GET', '/calendar/api/events/291')->assertStatus(200)->assertJsonFragment(['id' => $id]);
        $this->actingAs($user)->json('GET', '/calendar/api/events/' . rawurlencode($event->uid))->assertStatus(200);
        $this->actingAs($this->makeUser())->json('GET', '/calendar/api/events/e' . $id)->assertStatus(404);
    }

    public function test_a_v2_link_never_opens_a_v3_event_with_the_same_number(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true]);
        $id = $this->eventIn($calendar);
        $this->assertNull(CalendarEvent::where('legacy_id', (string) $id)->first());

        // An old v2 link "#<item id>" whose item no longer exists, while v3 event <id> is visible.
        $this->actingAs($user)->json('GET', '/calendar/api/events/' . $id)->assertStatus(404);
        $this->actingAs($user)->json('GET', '/calendar/api/events/e' . $id)->assertStatus(200)->assertJsonFragment(['id' => $id]);
        $this->actingAs($user)->json('GET', '/calendar/api/events/e' . $id . 'x')->assertStatus(404);
    }

    public function test_conversation_events_need_access_to_the_conversation(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $foreign = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox()->id, 'status' => Conversation::STATUS_ACTIVE]);
        $own = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);

        $this->actingAs($user)->json('POST', '/calendar/api/conversations/' . $foreign->id . '/events', $this->body($calendar))->assertStatus(404);

        $response = $this->actingAs($user)->json('POST', '/calendar/api/conversations/' . $own->id . '/events', $this->body($calendar));
        $response->assertStatus(201);
        $this->assertSame($own->id, json_decode($response->getContent(), true)['conversation']['id']);
        $this->assertTrue(Thread::where('conversation_id', $own->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->exists());
    }

    private function eventIn(Calendar $calendar, $creator = null): int
    {
        $creator = $creator ?: $this->makeUser();
        $admin = $this->calendarFor($creator, ['can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $event = app(\Modules\LJPcCalendarModule\Services\EventService::class)->create($calendar, $creator, ['title' => 'Seed', 'start' => '2026-10-20T10:00']);

        return $event->id;
    }

    public function test_edit_right_on_one_calendar_does_not_reach_events_in_another(): void
    {
        $user = $this->makeUser();
        $this->calendarFor($user, ['can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $hidden = Calendar::create(['name' => 'Hidden ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $viewable = $this->calendarFor($user, ['can_view' => true]);
        $hiddenId = $this->eventIn($hidden);
        $viewableId = $this->eventIn($viewable);

        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $hiddenId, ['title' => 'x'])->assertStatus(404);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $hiddenId)->assertStatus(404);
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $viewableId, ['title' => 'x'])->assertStatus(403);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $viewableId)->assertStatus(403);
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/99999999', ['title' => 'x'])->assertStatus(404);
        $this->assertNotNull(CalendarEvent::find($hiddenId));
        $this->assertNotNull(CalendarEvent::find($viewableId));
    }

    public function test_moving_an_event_needs_create_on_a_visible_target(): void
    {
        $user = $this->makeUser();
        $source = $this->calendarFor($user, ['can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $viewOnly = $this->calendarFor($user, ['can_view' => true]);
        $hidden = Calendar::create(['name' => 'Hidden ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $target = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $id = $this->eventIn($source);

        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $id, ['calendar_id' => $viewOnly->id])->assertStatus(403);
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $id, ['calendar_id' => $hidden->id])->assertStatus(404);
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $id, ['calendar_id' => 99999999])->assertStatus(404);
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $id, ['calendar_id' => $target->id])->assertStatus(200)->assertJsonFragment(['calendar_id' => $target->id]);
    }

    public function test_creating_in_an_invisible_calendar_is_404(): void
    {
        $user = $this->makeUser();
        $hidden = Calendar::create(['name' => 'Hidden ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);

        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($hidden))->assertStatus(404);
        $this->actingAs($user)->json('POST', '/calendar/api/events', ['calendar_id' => 99999999] + $this->body($hidden))->assertStatus(404);
    }

    public function test_read_only_events_cannot_be_changed_in_an_editable_calendar(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $id = $this->eventIn($calendar);
        CalendarEvent::where('id', $id)->update(['read_only' => true]);

        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $id, ['title' => 'x'])->assertStatus(403);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $id)->assertStatus(403);
        $this->assertNotNull(CalendarEvent::find($id));
    }

    public function test_legacy_id_and_uid_lookups_respect_calendar_visibility(): void
    {
        $user = $this->makeUser();
        $hidden = Calendar::create(['name' => 'Hidden ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $id = $this->eventIn($hidden);
        $event = CalendarEvent::find($id);
        $event->update(['legacy_id' => '777']);

        $this->actingAs($user)->json('GET', '/calendar/api/events/777')->assertStatus(404);
        $this->actingAs($user)->json('GET', '/calendar/api/events/' . rawurlencode($event->uid))->assertStatus(404);
    }

    public function test_event_detail_hides_a_conversation_the_user_cannot_view(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true]);
        $foreign = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox()->id, 'status' => Conversation::STATUS_ACTIVE]);
        $creator = $this->makeUser();
        $event = app(\Modules\LJPcCalendarModule\Services\EventService::class)->create($calendar, $creator, ['title' => 'Linked', 'start' => '2026-10-20T10:00'], $foreign);

        $response = $this->actingAs($user)->json('GET', '/calendar/api/events/e' . $event->id);

        $response->assertStatus(200);
        $this->assertNull(json_decode($response->getContent(), true)['conversation']);
    }

    public function test_occurrence_scope_rules_are_422_over_http(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $series = json_decode($this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar, ['recurrence' => ['freq' => 'DAILY', 'count' => 3]]))->getContent(), true)['id'];
        $single = json_decode($this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar))->getContent(), true)['id'];

        $response = $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $series, ['scope' => 'occurrence', 'recurrence_id' => '20261021T080000Z', 'field_values' => ['a' => 'b']]);
        $response->assertStatus(422)->assertJsonValidationErrors(['field_values']);

        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $single, ['scope' => 'occurrence', 'recurrence_id' => '20261020T080000Z', 'title' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['scope']);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $single . '?scope=occurrence&recurrence_id=20261020T080000Z')
            ->assertStatus(422)->assertJsonValidationErrors(['scope']);
        $this->assertNotNull(CalendarEvent::find($single));
    }

    public function test_a_permalink_to_an_occurrence_the_series_no_longer_produces_is_404_with_its_reason(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $series = json_decode($this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar, [
            'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00', 'recurrence' => ['freq' => 'DAILY', 'count' => 3],
        ]))->getContent(), true)['id'];
        $single = json_decode($this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar))->getContent(), true)['id'];
        $show = function ($id, string $key) use ($user) {
            return $this->actingAs($user)->json('GET', '/calendar/api/events/e' . $id . '?recurrence_id=' . $key);
        };

        $show($series, '20261021T080000Z')->assertStatus(200);
        // A moved occurrence keeps its key.
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $series, ['scope' => 'occurrence', 'recurrence_id' => '20261022T080000Z', 'start' => '2026-10-23T15:00', 'end' => '2026-10-23T16:00'])->assertStatus(200);
        $show($series, '20261022T080000Z')->assertStatus(200);

        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $series . '?scope=occurrence&recurrence_id=20261021T080000Z')->assertStatus(200);
        foreach ([[$series, '20261021T080000Z'], [$series, '20261025T080000Z'], [$series, '20261020'], [$single, '20261020T080000Z']] as [$id, $key]) {
            $response = $show($id, $key)->assertStatus(404);
            $this->assertSame('occurrence_missing', $response->json()['code'], $key);
        }
    }

    public function test_a_series_end_date_round_trips_unchanged_for_an_agent_east_of_the_event(): void
    {
        $amsterdam = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $rights = ['can_view' => true, 'can_create' => true, 'can_edit' => true];
        $calendar = $this->calendarFor($amsterdam, $rights);
        $body = $this->body($calendar, ['start' => '2027-03-01T10:00', 'end' => '2027-03-01T11:00', 'recurrence' => ['freq' => 'WEEKLY', 'until' => '2027-03-29']]);
        $id = json_decode($this->actingAs($amsterdam)->json('POST', '/calendar/api/events', $body)->getContent(), true)['id'];
        $this->actingAs($amsterdam)->json('DELETE', '/calendar/api/events/' . $id . '?scope=occurrence&recurrence_id=20270322T090000Z')->assertStatus(200);
        $this->actingAs($amsterdam)->json('PATCH', '/calendar/api/events/' . $id, ['scope' => 'occurrence', 'recurrence_id' => '20270315T090000Z', 'title' => 'Moved'])->assertStatus(200);
        $before = CalendarEvent::find($id);
        $rrule = (string) Reader::read($before->ics)->VEVENT->RRULE;
        $this->assertSame('FREQ=WEEKLY;UNTIL=20270329T215959Z', $rrule); // 23:59:59 on 29 March in Amsterdam (CEST)
        $recurrenceIds = CalendarOccurrence::where('event_id', $id)->orderBy('recurrence_id')->pluck('recurrence_id')->all();

        // Tokyo is ahead of Amsterdam: the stored UNTIL is already 30 March there, but the end date is the event's own.
        $tokyo = $this->makeUser(['timezone' => 'Asia/Tokyo']);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $tokyo->id] + $rights);
        $shown = json_decode($this->actingAs($tokyo)->json('GET', '/calendar/api/events/e' . $id)->assertStatus(200)->getContent(), true);
        $this->assertSame('2027-03-29', $shown['recurrence']['until']);

        // Saving the form as shown, the way the event form sends a series, changes nothing.
        $saved = $this->actingAs($tokyo)->json('PATCH', '/calendar/api/events/' . $id, [
            'title' => $shown['title'], 'start' => $shown['start'], 'end' => $shown['end'], 'recurrence' => $shown['recurrence'],
        ]);
        $saved->assertStatus(200);
        $this->assertSame('2027-03-29', json_decode($saved->getContent(), true)['recurrence']['until']);
        $after = CalendarEvent::find($id);
        $this->assertSame($rrule, (string) Reader::read($after->ics)->VEVENT->RRULE);
        $this->assertStringContainsString('EXDATE', $after->ics);
        $this->assertStringContainsString('RECURRENCE-ID', $after->ics);
        $this->assertSame($recurrenceIds, CalendarOccurrence::where('event_id', $id)->orderBy('recurrence_id')->pluck('recurrence_id')->all());
        $this->assertSame('Moved', CalendarOccurrence::where('event_id', $id)->where('recurrence_id', '20270315T090000Z')->value('summary'));
    }

    public function test_range_needs_an_end(): void
    {
        $this->actingAs($this->makeUser())->json('GET', '/calendar/api/events?start=2026-10-19T00:00:00%2B02:00')->assertStatus(422);
    }

    public function test_calendar_list_says_whether_sync_failed_without_the_error_text(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true], ['last_error' => 'HTTP 401 from https://user:secret@dav.example.test']);

        $content = $this->actingAs($user)->json('GET', '/calendar/api/calendars')->assertStatus(200)->getContent();
        $listed = collect(json_decode($content, true))->firstWhere('id', $calendar->id);

        $this->assertTrue($listed['sync_failed']);
        $this->assertArrayNotHasKey('last_error', $listed);
        $this->assertStringNotContainsString('secret', $content);
    }

    public function test_event_list_never_loads_the_icalendar_text(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $this->eventIn($calendar, $user);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $rows = json_decode($this->actingAs($user)->json('GET', '/calendar/api/events?start=2026-10-19&end=2026-10-26')->assertStatus(200)->getContent(), true);
        $queries = array_column(\Illuminate\Support\Facades\DB::getQueryLog(), 'query');
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame('Seed', $rows[0]['title']);
        $seen = 0;
        foreach ($queries as $query) {
            if (preg_match('/from [`"]?\w*calendar_events[`"]?\s/i', $query)) {
                $seen++;
                $this->assertDoesNotMatchRegularExpression('/select\s+\*/i', $query, 'The event list must select only the columns it shows.');
            }
        }
        $this->assertGreaterThan(0, $seen, 'The check above must have seen the event query.');
    }
}
