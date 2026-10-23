<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Thread;
use App\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\InviteActions;
use Modules\LJPcCalendarModule\Services\InviteService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\Support\InviteMail;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

/** Adding an invite, applying its update or cancellation (spec section 13; decisions of 2026-10-19). */
class InviteActionsTest extends TestCase
{
    use InviteMail;

    private const GMAIL_UID = '7kukuqrdgsj8e2a3l4nbo1m1fs@google.com';
    private const OUTLOOK_UID = '040000008200E00074C5B7101A82E0080000000080D4F5A3B85FDB01000000000000000010000000A1B2C3D4E5F60718293A4B5C6D7E8F90';

    private InviteActions $actions;
    private User $agent;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::getDiskName());
        app(OccurrenceService::class)->moveWindow(new \DateTimeImmutable('2026-10-05', new \DateTimeZone('UTC')));
        $this->actions = app(InviteActions::class);
        $this->agent = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $this->conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->makeMailbox([$this->agent->id])->id, 'status' => Conversation::STATUS_ACTIVE, 'customer_email' => 'anna@example.com',
        ]);
    }

    private function calendar(string $provider = Calendar::PROVIDER_LOCAL): Calendar
    {
        $attributes = ['name' => 'Invites ' . uniqid(), 'provider' => $provider];
        if ($provider !== Calendar::PROVIDER_LOCAL) {
            $connection = CalendarConnection::create(['provider' => $provider, 'account' => 'team@example.org']);
            $attributes += ['connection_id' => $connection->id, 'remote_id' => 'team@example.org'];
        }
        $calendar = Calendar::create($attributes);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $this->agent->id, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);

        return $calendar;
    }

    private function invite(string $fixture, string $from = 'anna@example.com', array $replace = []): CalendarInvite
    {
        $thread = $this->inviteThread($this->conversation, ['invite.ics' => strtr(self::inviteFixture($fixture), $replace)], $from);

        return app(InviteService::class)->scanThread($thread)[0];
    }

    private static function master(CalendarEvent $event)
    {
        return EventIcs::master(Reader::read($event->ics), $event->uid);
    }

    /** @return string[] invite actions of the conversation's line items, oldest first */
    private function lineItems(): array
    {
        return Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_LINEITEM)->orderBy('id')->get()
            ->map(function (Thread $thread) {
                return (string) ($thread->getMetas()['invite_action'] ?? '');
            })->all();
    }

    public function test_a_new_invite_is_added_as_the_organizers_event_and_linked_to_the_conversation(): void
    {
        $calendar = $this->calendar();
        $invite = $this->invite('gmail-request.ics');

        $event = $this->actions->apply($invite, $this->agent, $calendar);

        $this->assertSame([self::GMAIL_UID, $calendar->id, $this->conversation->id, $this->agent->id, 'Contract review', '2026-11-03 09:00:00'], [$event->uid, $event->calendar_id, $event->conversation_id, $event->author_id, $event->summary, $event->starts_at]);
        $vcal = Reader::read($event->ics);
        $this->assertFalse(isset($vcal->METHOD), 'a stored calendar has no iTIP method');
        $this->assertSame('mailto:anna@example.com', (string) self::master($event)->ORGANIZER);
        $this->assertCount(2, self::master($event)->select('ATTENDEE'));
        $invite = $invite->fresh();
        $this->assertSame([CalendarInvite::STATUS_APPLIED, $event->id, $this->agent->id], [$invite->status, $invite->event_id, $invite->applied_by]);
        $this->assertNotNull($invite->applied_at);
        $this->assertSame(['added'], $this->lineItems());
    }

    public function test_google_and_microsoft_get_the_organizer_in_the_readable_block(): void
    {
        $provider = new FakeProvider();
        $provider->onCreate(new RemoteRef('g-1', null, '"1"'));
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_GOOGLE, $provider);

        $this->actions->apply($this->invite('gmail-request.ics'), $this->agent, $this->calendar(Calendar::PROVIDER_GOOGLE));

        $sent = EventIcs::master(Reader::read($provider->calls('create')[0]['ics']), self::GMAIL_UID);
        $this->assertStringContainsString("[FreeScout]\nOrganizer: Anna Example <anna@example.com>", (string) $sent->DESCRIPTION);
    }

    public function test_an_invite_is_never_added_twice_or_without_its_event(): void
    {
        $calendar = $this->calendar();
        $this->actions->apply($this->invite('gmail-request.ics'), $this->agent, $calendar);

        try {
            $this->actions->apply($this->invite('gmail-request.ics'), $this->agent, $calendar);
            $this->fail('The same version again must not be added');
        } catch (ValidationException $e) {
            $this->assertSame([__('This invite is already in a calendar.')], $e->errors()['invite']);
        }
        try {
            $this->actions->apply($this->invite('request-occurrence.ics', 'bram@example.net'), $this->agent, $calendar);
            $this->fail('An occurrence of an event that is in no calendar can\'t be added');
        } catch (ValidationException $e) {
            $this->assertSame([__('This invite changes an event that is in no calendar yet.')], $e->errors()['invite']);
        }
    }

    public function test_an_update_replaces_the_event_and_keeps_what_freescout_added(): void
    {
        $calendar = $this->calendar();
        $event = $this->actions->apply($this->invite('gmail-request.ics'), $this->agent, $calendar);
        $event->update(['field_values' => [], 'reminder_minutes' => 15]);

        $moved = $this->invite('gmail-request.ics', 'anna@example.com', ['SEQUENCE:0' => 'SEQUENCE:1', 'T100000' => 'T140000', 'T110000' => 'T150000', 'SUMMARY:Contract review' => 'SUMMARY:Contract review (moved)']);
        $updated = $this->actions->apply($moved, $this->agent, null);

        $this->assertSame([$event->id, 'Contract review (moved)', '2026-11-03 13:00:00', 1, 15, $this->conversation->id], [$updated->id, $updated->summary, $updated->starts_at, $updated->sequence, $updated->reminder_minutes, $updated->conversation_id]);
        $this->assertSame(['added', 'updated'], $this->lineItems());
    }

    public function test_an_update_of_one_occurrence_changes_only_that_occurrence(): void
    {
        $event = $this->actions->apply($this->invite('outlook-request.ics', 'bram@example.net'), $this->agent, $this->calendar());

        $this->actions->apply($this->invite('request-occurrence.ics', 'bram@example.net', [';RANGE=THISANDFUTURE' => '']), $this->agent, null);

        $vcal = Reader::read($event->fresh()->ics);
        $override = EventIcs::findOverride($vcal, self::OUTLOOK_UID, '20261124T090000Z', new \DateTimeZone('UTC'));
        $this->assertNotNull($override);
        $this->assertSame(['Ticket sync (moved)', '20261124T130000Z'], [(string) $override->SUMMARY, $override->DTSTART->getDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z')]);
        $this->assertSame('Ticket sync', (string) EventIcs::master($vcal, self::OUTLOOK_UID)->SUMMARY);
    }

    public function test_a_change_to_this_and_the_following_occurrences_updates_the_series_from_there(): void
    {
        // Spec section 13: RANGE=THISANDFUTURE is applied to the series, which then starts at that occurrence.
        $event = $this->actions->apply($this->invite('outlook-request.ics', 'bram@example.net'), $this->agent, $this->calendar());

        $this->actions->apply($this->invite('request-occurrence.ics', 'bram@example.net'), $this->agent, null);

        $vcal = Reader::read($event->fresh()->ics);
        $master = EventIcs::master($vcal, self::OUTLOOK_UID);
        $this->assertSame(['Ticket sync (moved)', '20261124T130000Z', '20261124T133000Z'], [(string) $master->SUMMARY, $master->DTSTART->getDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'), $master->DTEND->getDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z')]);
        $this->assertSame('FREQ=WEEKLY;UNTIL=20261215T090000Z;INTERVAL=1;BYDAY=TU;WKST=MO', (string) $master->RRULE);
        $this->assertCount(1, $vcal->select('VEVENT'), 'no separate occurrence');
    }

    public function test_a_cancellation_keeps_the_event_struck_through(): void
    {
        $event = $this->actions->apply($this->invite('gmail-request.ics'), $this->agent, $this->calendar());

        $cancelled = $this->actions->apply($this->invite('cancel-series.ics'), $this->agent, null);

        $this->assertSame([$event->id, 'cancelled', 'cancelled'], [$cancelled->id, $cancelled->status, $cancelled->invite_state]);
        $this->assertSame('CANCELLED', (string) self::master($cancelled)->STATUS);
        $this->assertSame(['added', 'cancelled'], $this->lineItems());
    }

    public function test_google_and_microsoft_mark_a_cancelled_event_in_its_title_and_free_its_time(): void
    {
        $provider = new FakeProvider();
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_MICROSOFT, $provider);
        $this->actions->apply($this->invite('gmail-request.ics'), $this->agent, $this->calendar(Calendar::PROVIDER_MICROSOFT));

        $cancelled = $this->actions->apply($this->invite('cancel-series.ics'), $this->agent, null);

        $sent = EventIcs::master(Reader::read($provider->calls('update')[0]['ics']), self::GMAIL_UID);
        $this->assertSame([__('Cancelled: :title', ['title' => 'Contract review']), 'TRANSPARENT'], [(string) $sent->SUMMARY, (string) $sent->TRANSP]);
        $this->assertFalse(isset($sent->STATUS) && (string) $sent->STATUS === 'CANCELLED', 'a cancelled status would delete it at Google');
        $this->assertSame('cancelled', $cancelled->invite_state);
    }

    public function test_a_cancelled_occurrence_stays_visible_and_struck_through(): void
    {
        $event = $this->actions->apply($this->invite('outlook-request.ics', 'bram@example.net'), $this->agent, $this->calendar());

        $this->actions->apply($this->invite('cancel-occurrence.ics', 'bram@example.net'), $this->agent, null);

        $override = EventIcs::findOverride(Reader::read($event->fresh()->ics), self::OUTLOOK_UID, '20261117T090000Z', new \DateTimeZone('UTC'));
        $this->assertSame('CANCELLED', (string) $override->STATUS);
        $this->assertNull($event->fresh()->invite_state, 'only that occurrence is cancelled');
    }

    public function test_an_outdated_or_current_invite_changes_nothing(): void
    {
        $calendar = $this->calendar();
        $first = $this->invite('gmail-request.ics');
        $this->actions->apply($first, $this->agent, $calendar);
        $this->actions->apply($this->invite('gmail-request.ics', 'anna@example.com', ['SEQUENCE:0' => 'SEQUENCE:1']), $this->agent, null);

        $this->expectException(ValidationException::class);
        $this->actions->apply($first->fresh(), $this->agent, null);
    }
}
