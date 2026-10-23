<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\Conversation;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Services\DropsExceptions;
use Modules\LJPcCalendarModule\Services\EventImporter;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\SeriesNotWritable;
use Modules\LJPcCalendarModule\Services\SyncService;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

/** EventService on calendars of other providers: the provider writes first, FreeScout saves after it confirmed. */
class EventServiceProviderTest extends TestCase
{
    private const HREF = 'https://dav.example.com/cal/visit.ics';
    private const SERIES = 'https://dav.example.com/cal/standup.ics';

    private FakeProvider $remote;

    private FakeProvider $other;

    private EventService $service;

    private Calendar $local;

    private Calendar $work;

    private Calendar $team;

    private User $user;

    /** @var string[] the provider calls of every test provider, in the order they happened */
    private array $order = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->remote = new FakeProvider(true);
        $this->other = new FakeProvider(true);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_CALDAV, $this->remote);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_GOOGLE, $this->other);
        $dav = CalendarConnection::create(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'agent@example.com']);
        $google = CalendarConnection::create(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com']);
        $this->local = Calendar::create(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $this->work = Calendar::create([
            'name' => 'Work ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $dav->id, 'remote_id' => 'https://dav.example.com/cal/',
            'timezone' => 'Europe/Amsterdam', 'fields' => [['id' => 7, 'name' => 'Kind', 'type' => 'dropdown', 'required' => false, 'options' => ['Call', 'Visit']]],
        ]);
        $this->team = Calendar::create(['name' => 'Team ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $google->id, 'remote_id' => 'team@example.com', 'timezone' => 'Europe/Amsterdam']);
        $this->user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $this->service = app(EventService::class);
    }

    private static function ref(string $remoteId, ?string $etag, ?string $uid = null): RemoteRef
    {
        return new RemoteRef($remoteId, $remoteId, $etag, $uid);
    }

    /** A result that records the call in $this->order before it answers. */
    private function logged(string $name, $result): callable
    {
        return function () use ($name, $result) {
            $this->order[] = $name;
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return $result;
        };
    }

    private function remoteEvent(array $input = [], string $href = self::HREF): CalendarEvent
    {
        $this->remote->onCreate(self::ref($href, '"v1"'));

        return $this->service->create($this->work, $this->user, $input + ['title' => 'Visit', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00']);
    }

    private function remoteSeries(): CalendarEvent
    {
        return $this->remoteEvent(['title' => 'Standup', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T10:15', 'recurrence' => ['freq' => 'DAILY', 'count' => 3]], self::SERIES);
    }

    /** A series as a pull stores it, with a rule only some providers can write. */
    private function pulledSeries(): CalendarEvent
    {
        return app(EventImporter::class)->upsert($this->work, new RemoteEvent(self::HREF, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Remote//EN\r\nBEGIN:VEVENT\r\n"
            . "UID:odd@example.com\r\nDTSTAMP:20261001T080000Z\r\nDTSTART:20261019T080000Z\r\nDTEND:20261019T090000Z\r\nSUMMARY:Odd\r\n"
            . "RRULE:FREQ=MONTHLY;BYDAY=MO,TU;BYSETPOS=2;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", self::HREF, '"odd-v1"'), 1);
    }

    private static function description(string $ics): string
    {
        return (string) Reader::read($ics)->VEVENT->DESCRIPTION;
    }

    public function test_a_create_sends_the_readable_block_to_the_provider_but_never_stores_it(): void
    {
        $mailbox = $this->makeMailbox([$this->user->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE]);
        $this->remote->onCreate(self::ref(self::HREF, '"v1"'));

        $event = $this->service->create($this->work, $this->user, ['title' => 'Visit', 'start' => '2026-10-20T10:00', 'description' => 'Bring the contract', 'field_values' => ['7' => 'Visit']], $conversation);

        $sent = $this->remote->calls('create')[0];
        $this->assertSame($this->work->id, $sent['calendar_id']);
        $this->assertSame("Bring the contract\n\n[FreeScout]\nKind: Visit\nTicket #" . $conversation->number . ': ' . $conversation->url(), self::description($sent['ics']));
        $this->assertSame('Bring the contract', self::description($event->ics));
        $this->assertSame([self::HREF, self::HREF, '"v1"', $this->work->id], [$event->remote_id, $event->remote_href, $event->etag, $event->calendar_id]);
        $this->assertNull($this->service->lastWarning());
    }

    public function test_an_event_a_sync_imported_before_the_create_was_saved_becomes_the_agents_event(): void
    {
        $mailbox = $this->makeMailbox([$this->user->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE]);
        // A sync lists the calendar right after the server stored the new event, before FreeScout saved it.
        $this->remote->onCreate(function (Calendar $calendar, $vcal) {
            app(EventImporter::class)->upsert($calendar, new RemoteEvent(self::HREF, $vcal->serialize(), self::HREF, '"v1"'), 1);

            return self::ref(self::HREF, '"v1"');
        });

        $event = $this->service->create($this->work, $this->user, ['title' => 'Visit', 'start' => '2026-10-20T10:00', 'field_values' => ['7' => 'Visit'], 'reminder_minutes' => 30], $conversation);

        $this->assertSame([$event->id], CalendarEvent::where('calendar_id', $this->work->id)->pluck('id')->all());
        $this->assertSame([$this->user->id, $conversation->id, ['7' => 'Visit'], 'Visit', 30, self::HREF], [$event->author_id, $event->conversation_id, $event->field_values, $event->title_input, $event->reminder_minutes, $event->remote_id]);
        $this->assertSame(1, \App\Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->count());
        $this->assertSame(1, $event->occurrences()->count());
    }

    public function test_a_uid_the_provider_assigns_replaces_freescouts(): void
    {
        $this->remote->onCreate(new RemoteRef('AAMkAGI2', null, 'changekey-1', '040000008200E00074C5B7101A82E008@example.com'));

        $event = $this->service->create($this->work, $this->user, ['title' => 'Call', 'start' => '2026-10-20T10:00']);

        $this->assertSame(['040000008200E00074C5B7101A82E008@example.com', 'AAMkAGI2', null, 'changekey-1'], [$event->uid, $event->remote_id, $event->remote_href, $event->etag]);
        $this->assertSame('040000008200E00074C5B7101A82E008@example.com', (string) Reader::read($event->ics)->VEVENT->UID);
        $this->assertNotNull(CalendarEvent::findByUid($this->work->id, '040000008200E00074C5B7101A82E008@example.com'));
    }

    public function test_nothing_is_stored_when_the_provider_refuses_a_create(): void
    {
        $this->remote->onCreate(new ProviderUnavailable(null, 503));
        $count = CalendarEvent::count();

        try {
            $this->service->create($this->work, $this->user, ['title' => 'Lost', 'start' => '2026-10-20T10:00']);
            $this->fail('The create should have failed.');
        } catch (ProviderUnavailable $e) {
            $this->assertSame($count, CalendarEvent::count());
        }
    }

    public function test_local_calendars_never_reach_a_provider(): void
    {
        $event = $this->service->create($this->local, $this->user, ['title' => 'Local', 'start' => '2026-10-20T10:00']);
        $this->service->update($event, $this->user, ['title' => 'Still local']);
        $this->service->delete($event->fresh(), EventService::SCOPE_SERIES, null);

        $this->assertSame([], array_merge($this->remote->calls, $this->other->calls));
        $this->assertNull($event->remote_id);
    }

    public function test_a_series_update_writes_first_and_keeps_the_new_etag(): void
    {
        $event = $this->remoteEvent(['field_values' => ['7' => 'Call']]);
        $this->remote->onUpdate(self::ref(self::HREF, '"v2"'));

        $updated = $this->service->update($event, $this->user, ['title' => 'Visit (moved)', 'field_values' => ['7' => 'Visit']]);

        $call = $this->remote->calls('update')[0];
        $this->assertSame([$event->id, null], [$call['event_id'], $call['occurrence_key']]);
        $this->assertSame("[FreeScout]\nKind: Visit", self::description($call['ics']), 'the block shows the values being saved');
        $this->assertSame(['Visit (moved)', '"v2"', self::HREF], [$updated->summary, $updated->etag, $updated->remote_href]);
    }

    public function test_an_occurrence_update_passes_its_key(): void
    {
        $series = $this->remoteSeries();
        $this->remote->onUpdate(self::ref(self::SERIES, '"v2"'));

        $this->service->update($series, $this->user, ['scope' => EventService::SCOPE_OCCURRENCE, 'recurrence_id' => '20261021T080000Z', 'title' => 'Standup (late)']);

        $call = $this->remote->calls('update')[0];
        $this->assertSame('20261021T080000Z', $call['occurrence_key']);
        $this->assertStringContainsString('RECURRENCE-ID', $call['ics']);
        $this->assertSame('"v2"', $series->fresh()->etag);
    }

    /** The provider's version of a stored event, as a pull brings it after another app changed it. */
    private static function changedElsewhere(CalendarEvent $event, string $etag): RemoteEvent
    {
        return new RemoteEvent(self::HREF, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Other app//EN\r\nBEGIN:VEVENT\r\nUID:" . $event->uid
            . "\r\nDTSTAMP:20261008T080000Z\r\nDTSTART:20261020T090000Z\r\nDTEND:20261020T100000Z\r\nSUMMARY:Changed elsewhere\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", self::HREF, $etag);
    }

    public function test_a_conflict_saves_nothing_of_the_agents_change_and_brings_the_other_one_in_first(): void
    {
        $event = $this->remoteEvent();
        $this->remote->onUpdate(new ProviderConflict(412));
        $budgets = [];
        $this->remote->onPull(function (Calendar $calendar, array $state, SyncBudget $budget) use ($event, &$budgets) {
            $budgets[] = $budget->remaining();

            return new ChangeSet([self::changedElsewhere($event, '"v9"')], [], ['sync_token' => 'after-the-conflict'], true, false);
        });

        try {
            $this->service->update($event, $this->user, ['title' => 'Changed here']);
            $this->fail('The update should have conflicted.');
        } catch (ProviderConflict $e) {
            $stored = $event->fresh();
            $this->assertSame(['Changed elsewhere', '"v9"'], [$stored->summary, $stored->etag], 'a reload shows the other change at once');
            $this->assertSame('Visit', $event->summary, 'the model handed in stays as it was');
            $this->assertCount(1, $budgets);
            $this->assertLessThanOrEqual(EventService::CONFLICT_SYNC_SECONDS, $budgets[0]);
            $work = $this->work->fresh();
            $this->assertSame(['after-the-conflict', null, null], [$work->sync_state['sync_token'], $work->sync_lease_until, $work->last_error]);
        }
    }

    public function test_a_conflict_while_a_run_holds_the_calendar_asks_for_the_next_run(): void
    {
        $event = $this->remoteEvent();
        DB::table('calendars')->where('id', $this->work->id)->update(['sync_lease_until' => gmdate('Y-m-d H:i:s', time() + 60), 'next_sync_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $this->remote->onUpdate(new ProviderConflict(412));

        try {
            $this->service->update($event, $this->user, ['title' => 'Changed here']);
            $this->fail('The update should have conflicted.');
        } catch (ProviderConflict $e) {
            $this->assertSame([], $this->remote->calls('pull'), 'the run that holds the lease is left alone');
            $this->assertSame(['Visit', '"v1"'], [$event->fresh()->summary, $event->fresh()->etag]);
            $this->assertLessThanOrEqual(time(), strtotime($this->work->fresh()->next_sync_at . ' UTC'), 'the next run takes it on at once');
        }
    }

    public function test_a_sync_that_fails_after_a_conflict_still_answers_the_conflict(): void
    {
        $event = $this->remoteEvent();
        $this->remote->onUpdate(new ProviderConflict(412));
        $this->remote->onPull(new ProviderUnavailable(null, 503));

        try {
            $this->service->update($event, $this->user, ['title' => 'Changed here']);
            $this->fail('The update should have conflicted.');
        } catch (ProviderConflict $e) {
            $this->assertSame('The calendar provider could not be reached. Try again later. (HTTP 503)', $this->work->fresh()->last_error);
            $this->assertSame('Visit', $event->fresh()->summary);
        }
    }

    public function test_an_occurrence_delete_passes_its_key_with_the_exdate(): void
    {
        $series = $this->remoteSeries();
        $this->remote->onUpdate(self::ref(self::SERIES, '"v2"'));

        $this->service->delete($series, EventService::SCOPE_OCCURRENCE, '20261021T080000Z');

        $call = $this->remote->calls('update')[0];
        $this->assertSame('20261021T080000Z', $call['occurrence_key']);
        $this->assertStringContainsString('EXDATE', $call['ics']);
        $this->assertSame(['"v2"', 2], [$series->fresh()->etag, $series->occurrences()->count()]);
    }

    public function test_a_whole_delete_asks_the_provider_first(): void
    {
        $event = $this->remoteEvent();
        $this->remote->onDelete(new ProviderUnavailable(null, 500), null);

        try {
            $this->service->delete($event, EventService::SCOPE_SERIES, null);
            $this->fail('The delete should have failed.');
        } catch (ProviderUnavailable $e) {
            $this->assertNotNull(CalendarEvent::find($event->id));
        }
        $this->service->delete($event->fresh(), EventService::SCOPE_SERIES, null);

        $this->assertNull(CalendarEvent::find($event->id));
        $this->assertSame([$event->id, $event->id], array_column($this->remote->calls('delete'), 'event_id'));
    }

    public function test_the_reset_question_comes_before_any_provider_call(): void
    {
        $series = $this->remoteSeries();
        $this->remote->onUpdate(self::ref(self::SERIES, '"v2"'));
        $this->service->delete($series, EventService::SCOPE_OCCURRENCE, '20261021T080000Z');

        try {
            $this->service->update($series->fresh(), $this->user, ['start' => '2026-10-20T11:00', 'end' => '2026-10-20T11:15']);
            $this->fail('The agent should have been asked first.');
        } catch (DropsExceptions $e) {
            $this->assertCount(1, $this->remote->calls('update'), 'only the occurrence delete reached the provider');
        }
    }

    public function test_a_move_from_a_local_calendar_creates_the_event_at_the_provider(): void
    {
        $event = $this->service->create($this->local, $this->user, ['title' => 'Visit', 'start' => '2026-10-20T10:00']);
        $this->remote->onCreate(self::ref(self::HREF, '"v1"'));

        $moved = $this->service->update($event, $this->user, ['calendar_id' => $this->work->id, 'field_values' => ['7' => 'Visit']]);

        $this->assertSame([$this->work->id, self::HREF, '"v1"'], [$moved->calendar_id, $moved->remote_id, $moved->etag]);
        $this->assertSame("[FreeScout]\nKind: Visit", self::description($this->remote->calls('create')[0]['ics']));
        $this->assertSame([], $this->remote->calls('delete'));
        $this->assertNull($this->service->lastWarning());
    }

    public function test_a_move_to_a_local_calendar_removes_the_event_from_the_provider(): void
    {
        $event = $this->remoteEvent();

        $moved = $this->service->update($event, $this->user, ['calendar_id' => $this->local->id]);

        $this->assertSame([$this->local->id, null, null, null], [$moved->calendar_id, $moved->remote_id, $moved->remote_href, $moved->etag]);
        $this->assertSame([$event->id], array_column($this->remote->calls('delete'), 'event_id'));
        $this->assertNull($this->service->lastWarning());
    }

    public function test_a_move_between_providers_creates_in_the_target_first_and_deletes_from_the_source_second(): void
    {
        $event = $this->remoteEvent();
        $this->other->onCreate($this->logged('create in the target', self::ref('team-event-1', '"t1"')));
        $this->remote->onDelete($this->logged('delete from the source', null));

        $moved = $this->service->update($event, $this->user, ['calendar_id' => $this->team->id]);

        $this->assertSame(['create in the target', 'delete from the source'], $this->order);
        $this->assertSame([$this->team->id, 'team-event-1', 'team-event-1', '"t1"', $event->uid], [$moved->calendar_id, $moved->remote_id, $moved->remote_href, $moved->etag, $moved->uid]);
    }

    public function test_a_source_that_keeps_the_event_leaves_it_in_both_calendars_with_a_warning(): void
    {
        $event = $this->remoteEvent();
        $this->work->update(['sync_state' => ['sync_token' => 'http://sabre.io/ns/sync/7', 'supports_sync' => true, '_generation' => 4, '_listing' => false]]);
        $this->other->onCreate(self::ref('team-event-1', '"t1"'));
        $this->remote->onDelete(new ProviderUnavailable(null, 503));

        $moved = $this->service->update($event->fresh(), $this->user, ['calendar_id' => $this->team->id]);

        $this->assertSame([$this->team->id, 'team-event-1'], [$moved->calendar_id, $moved->remote_id]);
        $this->assertSame('The event was moved, but it could not be removed from "' . $this->work->name . '". It is now in both calendars.', $this->service->lastWarning());
        // A token would never report the copy that stayed; the source is listed in full at its next sync.
        $source = $this->work->fresh();
        $this->assertSame(['_generation' => 4, '_listing' => false, '_seen' => 0, '_listing_started' => null], $source->sync_state);
        $this->assertNotNull($source->next_sync_at);
    }

    public function test_a_moved_event_starts_over_in_the_generations_of_its_new_calendar(): void
    {
        $event = $this->remoteEvent();
        DB::table('calendar_events')->where('id', $event->id)->update(['sync_generation' => 5000]);
        $this->other->onCreate(self::ref('team-event-1', '"t1"'));

        $moved = $this->service->update($event->fresh(), $this->user, ['calendar_id' => $this->team->id]);

        $this->assertSame(0, $moved->sync_generation);
    }

    /** An event as a pull stores it from $calendar, with these ORGANIZER and ATTENDEE lines. */
    private function pulledMeeting(Calendar $calendar, string $remoteId, string ...$people): CalendarEvent
    {
        return app(EventImporter::class)->upsert($calendar, new RemoteEvent($remoteId, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Remote//EN\r\nBEGIN:VEVENT\r\n"
            . 'UID:' . $remoteId . "@example.com\r\nDTSTAMP:20261001T080000Z\r\nDTSTART:20261020T080000Z\r\nDTEND:20261020T090000Z\r\nSUMMARY:Planning\r\n"
            . implode('', array_map(function (string $line) {
                return $line . "\r\n";
            }, $people)) . "END:VEVENT\r\nEND:VCALENDAR\r\n", null, '"' . $remoteId . '-v1"'), 1);
    }

    public function test_a_meeting_with_attendees_is_never_moved_away_from_its_provider(): void
    {
        $microsoft = new FakeProvider(true);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_MICROSOFT, $microsoft);
        $outlook = Calendar::create([
            'name' => 'Outlook ' . uniqid(), 'provider' => Calendar::PROVIDER_MICROSOFT, 'timezone' => 'Europe/Amsterdam', 'remote_id' => 'users/desk@example.com/calendars/AAMkADExample=',
            'connection_id' => CalendarConnection::create(['provider' => Calendar::PROVIDER_MICROSOFT, 'account' => 'agent@example.com'])->id,
        ]);
        $teamTwo = Calendar::create(['name' => 'Team two ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $this->team->connection_id, 'remote_id' => 'two@group.calendar.example.com']);
        $meetings = [
            // Google lists the account itself among the attendees.
            'google'    => [$this->pulledMeeting($this->team, 'google-1', 'ORGANIZER:mailto:team@example.com', 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:team@example.com', 'ATTENDEE:mailto:guest@example.com'), [$this->work, $this->local, $teamTwo]],
            // A shared mailbox's own address is not a guest; a room is.
            'microsoft' => [$this->pulledMeeting($outlook, 'graph-1', 'ORGANIZER:mailto:desk@example.com', 'ATTENDEE;CUTYPE=RESOURCE:mailto:room@example.com'), [$this->local, $this->team]],
            // The organizer often lists itself as the chair.
            'caldav'    => [$this->pulledMeeting($this->work, 'dav-1', 'ORGANIZER:mailto:organizer@example.com', 'ATTENDEE;ROLE=CHAIR:mailto:organizer@example.com', 'ATTENDEE:mailto:agent@example.com'), [$this->team, $this->local]],
        ];

        foreach ($meetings as $provider => [$meeting, $targets]) {
            foreach ($targets as $target) {
                try {
                    $this->service->update($meeting, $this->user, ['calendar_id' => $target->id, 'title' => 'Planning moved']);
                    $this->fail('A ' . $provider . ' meeting was moved to ' . $target->provider);
                } catch (ValidationException $e) {
                    $this->assertSame(['calendar_id' => ['This is a meeting with attendees. Move it in the calendar app it comes from, so the attendees are not sent a cancellation.']], $e->errors());
                }
                $this->assertSame([$meeting->calendar_id, 'Planning'], [$meeting->fresh()->calendar_id, $meeting->fresh()->summary]);
            }
        }
        foreach ([$this->remote, $this->other, $microsoft] as $fake) {
            $this->assertSame([], array_column($fake->calls, 'method'), 'no provider was asked anything');
        }
    }

    public function test_an_event_whose_only_attendee_is_its_own_account_still_moves(): void
    {
        $event = $this->pulledMeeting($this->team, 'google-2', 'ORGANIZER:mailto:team@example.com', 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:team@example.com');
        $this->remote->onCreate(self::ref(self::HREF, '"v1"'));

        $moved = $this->service->update($event, $this->user, ['calendar_id' => $this->work->id]);

        $this->assertSame([$this->work->id, self::HREF], [$moved->calendar_id, $moved->remote_id]);
        $this->assertSame([$event->id], array_column($this->other->calls('delete'), 'event_id'));
    }

    private function giveUid(CalendarEvent $event, string $uid): void
    {
        DB::table('calendar_events')->where('id', $event->id)->update(['uid' => $uid, 'uid_hash' => CalendarEvent::hashUid($uid), 'ics' => str_replace($event->uid, $uid, $event->ics)]);
    }

    public function test_a_move_to_a_calendar_that_already_has_the_event_is_refused_before_any_provider_call(): void
    {
        $event = $this->remoteEvent();
        // An earlier move to the local calendar whose delete at the source failed, then relisted: the same UID in both.
        $copy = $this->service->create($this->local, $this->user, ['title' => 'Visit', 'start' => '2026-10-20T10:00']);
        $this->giveUid($copy, $event->uid);
        $other = Calendar::create(['name' => 'Local two ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $twin = $this->service->create($other, $this->user, ['title' => 'Visit', 'start' => '2026-10-20T10:00']);
        $this->giveUid($twin, $event->uid);

        foreach ([[$event, $this->local], [$copy->fresh(), $other]] as [$moving, $target]) {
            try {
                $this->service->update($moving, $this->user, ['calendar_id' => $target->id]);
                $this->fail('The event was moved next to its own copy.');
            } catch (ValidationException $e) {
                $this->assertSame(['calendar_id' => ['That calendar already has this event.']], $e->errors());
            }
        }
        $this->assertSame($this->work->id, $event->fresh()->calendar_id);
        $this->assertSame(['create'], array_column($this->remote->calls, 'method'), 'only the create of the first event reached the provider');
    }

    public function test_a_rule_the_provider_cant_write_is_refused_on_create_and_on_a_series_edit(): void
    {
        // The form's rules all map onto Graph (spec section 7); this guards Task 11's provider all the same.
        $this->other->writableRecurrence = false;

        try {
            $this->service->create($this->team, $this->user, ['title' => 'Standup', 'start' => '2026-10-20T10:00', 'recurrence' => ['freq' => 'DAILY', 'count' => 3]]);
            $this->fail('The create should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['recurrence' => ['This calendar can\'t store this repeat rule.']], $e->errors());
        }
        $this->other->onCreate(self::ref('team-single', '"s1"'));
        $single = $this->service->create($this->team, $this->user, ['title' => 'Single', 'start' => '2026-10-20T10:00']);
        try {
            $this->service->update($single, $this->user, ['recurrence' => ['freq' => 'DAILY', 'count' => 3]]);
            $this->fail('The rule should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['recurrence' => ['This calendar can\'t store this repeat rule.']], $e->errors());
        }

        $this->assertCount(1, $this->other->calls('create'), 'only the single event reached the provider');
        $this->assertSame([], $this->other->calls('update'));
    }

    public function test_an_unwritable_rule_is_refused_before_the_agent_is_asked_to_reset_the_series(): void
    {
        // Writes daily series, but no weekly ones.
        $picky = new class (true) extends FakeProvider {
            public function writableRecurrence(\Sabre\VObject\Component\VEvent $master): bool
            {
                return strpos((string) ($master->RRULE ?? ''), 'FREQ=WEEKLY') === false;
            }
        };
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_GOOGLE, $picky);
        $picky->onCreate(self::ref('team-daily', '"d1"'));
        $series = $this->service->create($this->team, $this->user, ['title' => 'Standup', 'start' => '2026-10-20T10:00', 'recurrence' => ['freq' => 'DAILY', 'count' => 3]]);
        $picky->onUpdate(self::ref('team-daily', '"d2"'));
        $this->service->delete($series, EventService::SCOPE_OCCURRENCE, '20261021T080000Z');

        try {
            $this->service->update($series->fresh(), $this->user, ['recurrence' => ['freq' => 'WEEKLY', 'byday' => ['TU'], 'count' => 3]]);
            $this->fail('The rule should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['recurrence' => ['This calendar can\'t store this repeat rule.']], $e->errors());
        }
        $this->assertCount(1, $picky->calls('update'), 'only the occurrence delete reached the provider');
    }

    public function test_a_target_that_refuses_the_event_changes_nothing(): void
    {
        $event = $this->remoteEvent();
        $this->other->onCreate(new ProviderUnavailable(null, 502));

        try {
            $this->service->update($event, $this->user, ['calendar_id' => $this->team->id]);
            $this->fail('The move should have failed.');
        } catch (ProviderUnavailable $e) {
            $stored = $event->fresh();
            $this->assertSame([$this->work->id, self::HREF, '"v1"'], [$stored->calendar_id, $stored->remote_id, $stored->etag]);
            $this->assertSame([], $this->remote->calls('delete'));
        }
    }

    public function test_a_series_whose_rule_the_provider_cant_write_changes_one_occurrence_at_a_time(): void
    {
        $this->remote->writableRecurrence = false;
        $series = $this->pulledSeries();
        $this->assertFalse($series->read_only, 'only the series is locked, its occurrences stay editable');

        foreach ([['title' => 'Renamed'], ['calendar_id' => $this->local->id], ['reminder_minutes' => 15]] as $input) {
            try {
                $this->service->update($series->fresh(), $this->user, $input);
                $this->fail('A series change should have been refused: ' . json_encode($input));
            } catch (SeriesNotWritable $e) {
                $this->assertSame('This series can only be changed one occurrence at a time.', $e->getMessage());
            }
        }
        $this->assertSame([], array_merge($this->remote->calls('update'), $this->remote->calls('delete')));

        $key = CalendarEvent::find($series->id)->occurrences()->orderBy('starts_at')->value('recurrence_id');
        $this->remote->onUpdate(self::ref(self::HREF, '"odd-v2"'));
        $this->service->update($series->fresh(), $this->user, ['scope' => EventService::SCOPE_OCCURRENCE, 'recurrence_id' => $key, 'title' => 'Odd (once)']);
        $this->service->delete($series->fresh(), EventService::SCOPE_SERIES, null);

        $this->assertSame($key, $this->remote->calls('update')[0]['occurrence_key']);
        $this->assertCount(1, $this->remote->calls('delete'), 'deleting the whole series writes no rule');
        $this->assertNull(CalendarEvent::find($series->id));
    }

    public function test_a_move_to_a_calendar_that_cant_store_the_rule_is_refused(): void
    {
        $this->other->writableRecurrence = false;
        $series = $this->service->create($this->local, $this->user, ['title' => 'Standup', 'start' => '2026-10-20T10:00', 'recurrence' => ['freq' => 'DAILY', 'count' => 3]]);

        try {
            $this->service->update($series, $this->user, ['calendar_id' => $this->team->id]);
            $this->fail('The move should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['calendar_id' => ['That calendar can\'t store the repeat rule of this series.']], $e->errors());
        }
        $this->assertSame([], $this->other->calls('create'));
    }

    public function test_saves_during_an_unfinished_listing_are_never_swept_by_it(): void
    {
        $edited = $this->remoteEvent();
        $series = $this->remoteSeries();
        $untouched = app(EventImporter::class)->upsert($this->work, new RemoteEvent('https://dav.example.com/cal/old.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Remote//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:old@example.com\r\nDTSTAMP:20261001T080000Z\r\nDTSTART:20261019T080000Z\r\nSUMMARY:Old\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", 'https://dav.example.com/cal/old.ics', '"old"'), 0);
        DB::table('calendar_events')->where('calendar_id', $this->work->id)->update(['updated_at' => '2026-09-01 00:00:00', 'sync_generation' => 0]);
        $this->work->update(['sync_state' => ['_generation' => 3, '_listing' => true, '_listing_started' => '2026-10-01 00:00:00', '_seen' => 1, '_empty_listings' => 0]]);

        // FreeScout writes while the listing is unfinished; the listing never mentions these events.
        $this->remote->onUpdate(self::ref(self::HREF, '"v2"'), self::ref(self::SERIES, '"v3"'));
        $this->service->update($edited->fresh(), $this->user, ['title' => 'Edited meanwhile']);
        $this->service->delete($series->fresh(), EventService::SCOPE_OCCURRENCE, '20261021T080000Z');
        $this->remote->onCreate(self::ref('https://dav.example.com/cal/new.ics', '"n1"'));
        $created = $this->service->create($this->work, $this->user, ['title' => 'Created meanwhile', 'start' => '2026-10-22T10:00']);
        $local = $this->service->create($this->local, $this->user, ['title' => 'Moved in meanwhile', 'start' => '2026-10-23T10:00']);
        $this->remote->onCreate(self::ref('https://dav.example.com/cal/moved.ics', '"m1"'));
        $movedIn = $this->service->update($local, $this->user, ['calendar_id' => $this->work->id]);
        $this->remote->onPull(new ChangeSet([], [], [], true, true, ['https://dav.example.com/cal/listed.ics']));

        app(SyncService::class)->syncCalendar($this->work->fresh(), SyncBudget::seconds(30));

        $this->assertNull(CalendarEvent::find($untouched->id), 'the sweep ran');
        $this->assertNotNull(CalendarEvent::find($edited->id));
        $this->assertNotNull(CalendarEvent::find($series->id));
        $this->assertNotNull(CalendarEvent::find($created->id));
        $this->assertNotNull(CalendarEvent::find($movedIn->id));
    }
}
