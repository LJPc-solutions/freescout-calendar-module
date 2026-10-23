<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\EventImporter;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** How the agent API answers provider errors, and what it says after a move that half worked. */
class ProviderWritesApiTest extends TestCase
{
    private const HREF = 'https://dav.example.com/cal/visit.ics';
    private const RIGHTS = ['can_view' => true, 'can_create' => true, 'can_edit' => true];

    private FakeProvider $provider;

    private CalendarConnection $connection;

    private Calendar $calendar;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->provider = new FakeProvider(true);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_CALDAV, $this->provider);
        $this->connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'agent@example.com']);
        $this->user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $this->calendar = $this->calendarFor(['name' => 'Work ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $this->connection->id, 'remote_id' => 'https://dav.example.com/cal/']);
    }

    private function calendarFor(array $attributes): Calendar
    {
        $calendar = Calendar::create($attributes + ['timezone' => 'Europe/Amsterdam']);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $this->user->id] + self::RIGHTS);

        return $calendar;
    }

    private function body(array $overrides = []): array
    {
        return $overrides + ['calendar_id' => $this->calendar->id, 'title' => 'Visit', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00'];
    }

    /** An event FreeScout created at the provider earlier. */
    private function event(array $overrides = [], string $href = self::HREF): CalendarEvent
    {
        $this->provider->onCreate(new RemoteRef($href, $href, '"v1"'));
        $id = json_decode($this->actingAs($this->user)->json('POST', '/calendar/api/events', $this->body($overrides))->assertStatus(201)->getContent(), true)['id'];

        return CalendarEvent::find($id);
    }

    public function test_a_change_made_elsewhere_answers_409_once_the_other_change_is_stored(): void
    {
        $event = $this->event();
        $this->provider->onUpdate(new ProviderConflict(412));
        $this->provider->onPull(new ChangeSet([new RemoteEvent(self::HREF, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Other app//EN\r\nBEGIN:VEVENT\r\nUID:" . $event->uid
            . "\r\nDTSTAMP:20261008T080000Z\r\nDTSTART:20261020T090000Z\r\nDTEND:20261020T100000Z\r\nSUMMARY:Changed elsewhere\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", self::HREF, '"v9"')], [], [], true, false));

        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $event->id, ['title' => 'Changed here'])
            ->assertStatus(409)
            ->assertExactJson(['code' => 'changed_elsewhere', 'message' => 'This event was changed elsewhere. Reload and try again.']);

        $this->assertSame(['Changed elsewhere', '"v9"'], [$event->fresh()->summary, $event->fresh()->etag], 'the reload the message asks for shows the other change');
    }

    public function test_a_failing_sync_after_a_conflict_changes_nothing_in_the_answer(): void
    {
        $event = $this->event();
        $this->provider->onUpdate(new ProviderConflict(412));
        $this->provider->onPull(new ProviderUnavailable(null, 503));

        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $event->id, ['title' => 'Changed here'])
            ->assertStatus(409)
            ->assertExactJson(['code' => 'changed_elsewhere', 'message' => 'This event was changed elsewhere. Reload and try again.']);

        $this->assertSame(['Visit', '"v1"'], [$event->fresh()->summary, $event->fresh()->etag]);
    }

    public function test_an_unreachable_provider_answers_502_and_stores_nothing(): void
    {
        $mailbox = $this->makeMailbox([$this->user->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE]);
        $this->provider->onCreate(new ProviderUnavailable(null, 503), new ProviderUnavailable(null, 503));
        $count = CalendarEvent::count();

        $this->actingAs($this->user)->json('POST', '/calendar/api/events', $this->body())
            ->assertStatus(502)
            ->assertExactJson(['code' => 'provider_unavailable', 'message' => 'The calendar provider could not be reached. Try again later.']);
        $this->actingAs($this->user)->json('POST', '/calendar/api/conversations/' . $conversation->id . '/events', $this->body())
            ->assertStatus(502)
            ->assertJsonFragment(['code' => 'provider_unavailable']);

        $this->assertSame($count, CalendarEvent::count());
    }

    public function test_a_connection_the_provider_no_longer_accepts_answers_502_connection_needs_reauth(): void
    {
        $event = $this->event();
        $this->provider->onDelete(new ConnectionNeedsReauth(401));

        $this->actingAs($this->user)->json('DELETE', '/calendar/api/events/' . $event->id)
            ->assertStatus(502)
            ->assertExactJson(['code' => 'connection_needs_reauth', 'message' => 'This calendar\'s connection needs to be re-authorized by an administrator.']);

        $this->assertNotNull(CalendarEvent::find($event->id));
    }

    public function test_a_calendar_whose_connection_needs_reauthorization_says_so_before_the_provider(): void
    {
        // The page shows such a calendar read-only; one opened before the account was marked still sends its writes.
        $event = $this->event();
        $local = $this->calendarFor(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $localEvent = json_decode($this->actingAs($this->user)->json('POST', '/calendar/api/events', $this->body(['calendar_id' => $local->id]))->assertStatus(201)->getContent(), true)['id'];
        $mailbox = $this->makeMailbox([$this->user->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE]);
        $calls = count($this->provider->calls);
        $reauth = ['code' => 'connection_needs_reauth', 'message' => 'This calendar\'s connection needs to be re-authorized by an administrator.'];

        foreach ([CalendarConnection::STATUS_NEEDS_REAUTH, CalendarConnection::STATUS_ERROR] as $status) {
            $this->connection->update(['status' => $status]);
            $this->actingAs($this->user)->json('POST', '/calendar/api/events', $this->body())->assertStatus(502)->assertExactJson($reauth);
            $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $event->id, ['title' => 'Changed'])->assertStatus(502)->assertExactJson($reauth);
            $this->actingAs($this->user)->json('DELETE', '/calendar/api/events/' . $event->id)->assertStatus(502)->assertExactJson($reauth);
            $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $localEvent, ['calendar_id' => $this->calendar->id])->assertStatus(502)->assertExactJson($reauth);
            $this->actingAs($this->user)->json('POST', '/calendar/api/conversations/' . $conversation->id . '/events', $this->body())->assertStatus(502)->assertExactJson($reauth);
        }

        // Without the right to write, for an event that is read-only on its own, or in a calendar the account may only
        // read, the account's state is no reason to answer otherwise: 403, as before.
        $viewer = $this->makeUser();
        CalendarPermission::create(['calendar_id' => $this->calendar->id, 'subject_type' => 'user', 'subject_id' => $viewer->id, 'can_view' => true]);
        $this->actingAs($viewer)->json('POST', '/calendar/api/events', $this->body())->assertStatus(403);
        $this->actingAs($viewer)->json('PATCH', '/calendar/api/events/' . $event->id, ['title' => 'Changed'])->assertStatus(403);
        $event->update(['read_only' => true]);
        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $event->id, ['title' => 'Changed'])->assertStatus(403);
        $this->calendar->update(['remote_read_only' => true]);
        $this->actingAs($this->user)->json('POST', '/calendar/api/events', $this->body())->assertStatus(403);

        $this->assertCount($calls, $this->provider->calls);
        $this->assertSame('Visit', $event->fresh()->summary);
        $this->assertSame($local->id, (int) CalendarEvent::find($localEvent)->calendar_id);
    }

    public function test_a_calendar_the_account_may_only_read_refuses_writes_before_the_provider(): void
    {
        $event = $this->event();
        $local = $this->calendarFor(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $localEvent = json_decode($this->actingAs($this->user)->json('POST', '/calendar/api/events', $this->body(['calendar_id' => $local->id]))->assertStatus(201)->getContent(), true)['id'];
        $this->calendar->update(['remote_read_only' => true]);
        $calls = count($this->provider->calls);

        $this->actingAs($this->user)->json('POST', '/calendar/api/events', $this->body())->assertStatus(403);
        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $event->id, ['title' => 'Changed'])->assertStatus(403);
        $this->actingAs($this->user)->json('DELETE', '/calendar/api/events/' . $event->id)->assertStatus(403);
        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $localEvent, ['calendar_id' => $this->calendar->id])->assertStatus(403);

        $this->assertCount($calls, $this->provider->calls);
        $this->assertSame('Visit', $event->fresh()->summary);
        $this->assertSame($local->id, (int) CalendarEvent::find($localEvent)->calendar_id);
    }

    public function test_an_event_pulled_while_the_account_could_only_read_is_editable_once_it_may_write_again(): void
    {
        $this->calendar->update(['remote_read_only' => true]);
        $pulled = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Other app//EN\r\nBEGIN:VEVENT\r\nUID:pulled@example.com\r\nDTSTAMP:20261008T080000Z\r\n"
            . "DTSTART:20261020T090000Z\r\nDTEND:20261020T100000Z\r\nSUMMARY:Pulled\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $event = app(EventImporter::class)->upsert($this->calendar->fresh(), new RemoteEvent(self::HREF, $pulled, self::HREF, '"v1"'), 1);
        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $event->id, ['title' => 'Changed'])->assertStatus(403);

        // The pick screen found that the account may write again: no sync in between.
        $this->calendar->update(['remote_read_only' => false]);
        $this->provider->onUpdate(new RemoteRef(self::HREF, self::HREF, '"v2"'));
        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $event->id, ['title' => 'Changed'])->assertStatus(200);

        $this->assertSame(['Changed', '"v2"'], [$event->fresh()->summary, $event->fresh()->etag]);
    }

    public function test_the_reset_question_still_comes_first_without_a_provider_call(): void
    {
        $series = $this->event(['recurrence' => ['freq' => 'DAILY', 'count' => 3]]);
        $this->provider->onUpdate(new RemoteRef(self::HREF, self::HREF, '"v2"'));
        $this->actingAs($this->user)->json('DELETE', '/calendar/api/events/' . $series->id, ['scope' => 'occurrence', 'recurrence_id' => '20261021T080000Z'])->assertStatus(200);
        $calls = count($this->provider->calls('update'));

        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $series->id, ['start' => '2026-10-20T11:00', 'end' => '2026-10-20T12:00'])
            ->assertStatus(409)
            ->assertJsonFragment(['code' => 'drops_exceptions']);

        $this->assertCount($calls, $this->provider->calls('update'));
    }

    public function test_a_move_the_source_could_not_complete_answers_with_the_event_and_a_warning(): void
    {
        $event = $this->event();
        $local = $this->calendarFor(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $this->provider->onDelete(new ProviderUnavailable(null, 503));

        $response = $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $event->id, ['calendar_id' => $local->id])->assertStatus(200);

        $this->assertSame($local->id, $response->json()['calendar_id']);
        $this->assertSame('The event was moved, but it could not be removed from "' . $this->calendar->name . '". It is now in both calendars.', $response->json()['warning']);
        $this->assertSame($local->id, $event->fresh()->calendar_id);
    }

    public function test_answers_without_a_warning_leave_it_out(): void
    {
        $this->provider->onCreate(new RemoteRef(self::HREF, self::HREF, '"v1"'));

        $response = $this->actingAs($this->user)->json('POST', '/calendar/api/events', $this->body())->assertStatus(201);

        $this->assertArrayNotHasKey('warning', $response->json());
    }

    public function test_a_series_the_provider_cant_write_is_changed_one_occurrence_at_a_time(): void
    {
        $this->provider->writableRecurrence = false;
        $series = app(EventImporter::class)->upsert($this->calendar, new RemoteEvent(self::HREF, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Remote//EN\r\nBEGIN:VEVENT\r\n"
            . "UID:odd@example.com\r\nDTSTAMP:20261001T080000Z\r\nDTSTART:20261019T080000Z\r\nDTEND:20261019T090000Z\r\nSUMMARY:Odd\r\n"
            . "RRULE:FREQ=MONTHLY;BYDAY=MO,TU;BYSETPOS=2;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", self::HREF, '"odd-v1"'), 1);
        $key = $series->occurrences()->orderBy('starts_at')->value('recurrence_id');
        $single = $this->calendarFor(['name' => 'Other ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);

        $detail = $this->actingAs($this->user)->json('GET', '/calendar/api/events/e' . $series->id)->assertStatus(200);
        $this->assertSame([false, true], [$detail->json()['series_writable'], $detail->json()['editable']]);
        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $series->id, ['title' => 'Renamed'])
            ->assertStatus(403)
            ->assertExactJson(['code' => 'series_not_writable', 'message' => 'This series can only be changed one occurrence at a time.']);
        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $series->id, ['calendar_id' => $single->id])->assertStatus(403);
        $this->assertSame([], $this->provider->calls('update'));

        $this->provider->onUpdate(new RemoteRef(self::HREF, self::HREF, '"odd-v2"'));
        $this->actingAs($this->user)->json('PATCH', '/calendar/api/events/' . $series->id, ['scope' => 'occurrence', 'recurrence_id' => $key, 'title' => 'Odd (once)'])->assertStatus(200);
        $this->actingAs($this->user)->json('DELETE', '/calendar/api/events/' . $series->id)->assertStatus(200);
        $this->assertNull(CalendarEvent::find($series->id));
    }

    public function test_single_events_and_series_of_writing_providers_can_be_changed_as_a_whole(): void
    {
        $single = $this->event();
        $series = $this->event(['recurrence' => ['freq' => 'WEEKLY', 'byday' => ['TU'], 'count' => 3]], 'https://dav.example.com/cal/weekly.ics');

        $this->assertTrue($this->actingAs($this->user)->json('GET', '/calendar/api/events/e' . $single->id)->json()['series_writable']);
        $this->assertTrue($this->actingAs($this->user)->json('GET', '/calendar/api/events/e' . $series->id)->json()['series_writable']);
    }
}
