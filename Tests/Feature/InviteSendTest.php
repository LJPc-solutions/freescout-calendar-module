<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Jobs\SendReplyToCustomer;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\ItipMail;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

/** Invites we send for an event linked to a conversation (spec section 13, outgoing; decisions of 2026-10-19). */
class InviteSendTest extends TestCase
{
    private User $agent;
    private Mailbox $mailbox;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::getDiskName());
        Queue::fake();
        app(OccurrenceService::class)->moveWindow(new \DateTimeImmutable('2026-10-05', new \DateTimeZone('UTC')));
        $this->agent = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $this->mailbox = $this->makeMailbox([$this->agent->id]);
        $this->mailbox->email = 'support@example.org';
        $this->mailbox->name = 'Support';
        $this->mailbox->save();
        $customer = Customer::create('anna@example.com', ['first_name' => 'Anna', 'last_name' => 'Example']);
        $this->conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id, 'status' => Conversation::STATUS_ACTIVE, 'customer_email' => 'anna@example.com', 'customer_id' => $customer->id,
        ]);
    }

    private function calendar(string $provider = Calendar::PROVIDER_LOCAL): Calendar
    {
        $attributes = ['name' => 'Team ' . uniqid(), 'provider' => $provider];
        if ($provider !== Calendar::PROVIDER_LOCAL) {
            $connection = CalendarConnection::create(['provider' => $provider, 'account' => 'team@example.org']);
            $attributes += ['connection_id' => $connection->id, 'remote_id' => 'team@example.org'];
        }
        $calendar = Calendar::create($attributes);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $this->agent->id, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);

        return $calendar;
    }

    private function invite(Calendar $calendar, array $attendees, array $extra = [])
    {
        return $this->actingAs($this->agent)->postJson(route('ljpccalendarmodule.api.conversations.events.store', ['id' => $this->conversation->id]), $extra + [
            'calendar_id' => $calendar->id, 'title' => 'Contract review', 'start' => '2026-11-03T10:00', 'end' => '2026-11-03T11:00', 'attendees' => $attendees,
        ]);
    }

    private function change(CalendarEvent $event, array $body)
    {
        return $this->actingAs($this->agent)->patchJson(route('ljpccalendarmodule.api.events.update', ['id' => $event->id]), $body);
    }

    /** @return Thread[] our calendar mails in the conversation, oldest first */
    private function mails(): array
    {
        return Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_MESSAGE)->orderBy('id')->get()
            ->filter(function (Thread $thread) {
                return $thread->getMeta(ItipMail::META) !== null;
            })->values()->all();
    }

    private static function itip(Thread $thread)
    {
        return Reader::read($thread->getMeta(ItipMail::META)['ics']);
    }

    private function lineItems(): array
    {
        return Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_LINEITEM)->orderBy('id')->get()
            ->map(function (Thread $thread) {
                return $thread->getMeta('invite_action');
            })->filter()->values()->all();
    }

    public function test_inviting_from_the_conversation_mails_the_attendees_the_event_with_the_mailbox_as_organizer(): void
    {
        $this->invite($this->calendar(), ['anna@example.com', ' Bob@Example.net '], [
            'message' => "See you <b>there</b>.\nAnna", 'description' => "Bring the contract.\n\n[FreeScout]\nTicket #1: https://help.example.org/conversation/1",
        ])->assertStatus(201);

        $event = CalendarEvent::firstOrFail();
        $this->assertSame([
            ['email' => 'anna@example.com', 'name' => 'Anna Example', 'partstat' => 'NEEDS-ACTION'],
            ['email' => 'bob@example.net', 'name' => '', 'partstat' => 'NEEDS-ACTION'],
        ], $event->attendees);
        $master = EventIcs::master(Reader::read($event->ics), $event->uid);
        $this->assertSame(['mailto:support@example.org', 'Support'], [(string) $master->ORGANIZER, (string) $master->ORGANIZER['CN']]);
        $this->assertCount(2, $master->select('ATTENDEE'));

        [$mail] = $this->mails();
        $this->assertSame(['anna@example.com', 'bob@example.net'], $mail->getToArray());
        $this->assertSame(['REQUEST', __('Invitation: :title', ['title' => 'Contract review'])], [$mail->getMeta(ItipMail::META)['method'], $mail->getMeta(ItipMail::META)['subject']]);
        $vcal = self::itip($mail);
        $this->assertSame(['REQUEST', $event->uid, 'mailto:support@example.org'], [(string) $vcal->METHOD, (string) $vcal->VEVENT->UID, (string) $vcal->VEVENT->ORGANIZER]);
        $this->assertSame('Bring the contract.', (string) $vcal->VEVENT->DESCRIPTION, 'never the readable block with the ticket link');
        $this->assertSame(['Europe/Amsterdam'], array_map(function ($zone) {
            return (string) $zone->TZID;
        }, $vcal->select('VTIMEZONE')), 'Outlook needs the zone of every TZID');
        $this->assertSame(['invite.ics'], $mail->attachments->pluck('file_name')->all());
        $this->assertStringContainsString('See you &lt;b&gt;there&lt;/b&gt;.<br />', $mail->body);
        $this->assertStringContainsString(e(__('When: :when', ['when' => '03-11-2026 10:00 – 11:00 (Europe/Amsterdam)'])), $mail->body);
        $this->assertSame(['sent'], $this->lineItems());
        Queue::assertPushedOn('emails', SendReplyToCustomer::class);
    }

    public function test_a_change_updates_the_attendees_and_new_and_removed_ones_get_an_invitation_or_a_cancellation(): void
    {
        $this->invite($this->calendar(), ['anna@example.com'])->assertStatus(201);
        $event = CalendarEvent::firstOrFail();
        $event->update(['attendees' => [['email' => 'anna@example.com', 'name' => 'Anna Example', 'partstat' => 'ACCEPTED']]]);

        $this->change($event, ['start' => '2026-11-03T14:00', 'end' => '2026-11-03T15:00', 'attendees' => ['anna@example.com', 'carol@example.net']])->assertStatus(200);

        $mails = $this->mails();
        $this->assertSame([['anna@example.com'], ['carol@example.net'], ['anna@example.com']], array_map(function (Thread $mail) {
            return $mail->getToArray();
        }, $mails));
        $this->assertSame(['sent', 'sent', 'sent_update'], $this->lineItems());
        $this->assertSame('ACCEPTED', $event->fresh()->attendees[0]['partstat'], 'an answer stays');
        $this->assertGreaterThan((int) (string) self::itip($mails[0])->VEVENT->SEQUENCE, (int) (string) self::itip($mails[2])->VEVENT->SEQUENCE);

        // Only the list changes: Anna is taken off, Carol hears nothing new.
        $this->change($event->fresh(), ['scope' => 'series', 'attendees' => ['carol@example.net']])->assertStatus(200);
        $cancel = $this->mails()[3];
        $this->assertSame([['anna@example.com'], 'CANCEL'], [$cancel->getToArray(), (string) self::itip($cancel)->METHOD]);
        $this->assertSame(['mailto:anna@example.com'], array_map('strval', self::itip($cancel)->VEVENT->select('ATTENDEE')));
        $this->assertSame(['sent', 'sent', 'sent_update', 'sent_cancellation'], $this->lineItems());

        $this->change($event->fresh(), ['title' => 'Quiet change', 'notify' => false])->assertStatus(200);
        $this->assertCount(4, $this->mails());
    }

    public function test_deleting_cancels_for_the_attendees_unless_the_agent_says_not_to(): void
    {
        $calendar = $this->calendar();
        $this->invite($calendar, ['anna@example.com'])->assertStatus(201);
        $event = CalendarEvent::firstOrFail();

        $this->actingAs($this->agent)->deleteJson(route('ljpccalendarmodule.api.events.destroy', ['id' => $event->id]))->assertStatus(200);

        $cancel = $this->mails()[1];
        $vcal = self::itip($cancel);
        $this->assertSame(['CANCEL', 'CANCELLED', $event->uid], [(string) $vcal->METHOD, (string) $vcal->VEVENT->STATUS, (string) $vcal->VEVENT->UID]);
        $this->assertSame(__('Cancelled: :title', ['title' => 'Contract review']), $cancel->getMeta(ItipMail::META)['subject']);
        $this->assertStringContainsString(e(__('This event is cancelled.')), $cancel->body);
        $this->assertSame(['sent', 'sent_cancellation'], $this->lineItems());

        $this->invite($calendar, ['anna@example.com'])->assertStatus(201);
        $this->actingAs($this->agent)->deleteJson(route('ljpccalendarmodule.api.events.destroy', ['id' => CalendarEvent::firstOrFail()->id]), ['notify' => false])->assertStatus(200);
        $this->assertCount(3, $this->mails());
    }

    public function test_only_our_own_events_from_a_conversation_get_attendees(): void
    {
        $calendar = $this->calendar();
        $this->invite($calendar, ['support@example.org'])->assertStatus(422)->assertJsonFragment(['attendees' => [__('The mailbox sends the invite, so leave its own address out.')]]);
        $this->invite($calendar, ['not an address'])->assertStatus(422);
        $this->actingAs($this->agent)->postJson(route('ljpccalendarmodule.api.events.store'), [
            'calendar_id' => $calendar->id, 'title' => 'Plain', 'start' => '2026-11-03T10:00', 'attendees' => ['anna@example.com'],
        ])->assertStatus(422);
        $this->assertSame(0, CalendarEvent::count());

        $this->invite($calendar, [])->assertStatus(201);
        $event = CalendarEvent::firstOrFail();
        $this->assertNull($event->attendees);
        $this->assertSame([], $this->mails());
        // An invite someone sent us keeps its own organizer.
        CalendarInvite::create(['conversation_id' => $this->conversation->id, 'attachment_id' => 1, 'uid' => $event->uid, 'method' => 'REQUEST', 'sequence' => 0, 'summary' => 'Theirs']);
        $this->change($event, ['attendees' => ['anna@example.com']])->assertStatus(422);
    }

    public function test_google_and_microsoft_list_the_attendees_in_the_readable_block(): void
    {
        $provider = new FakeProvider();
        $provider->onCreate(new RemoteRef('g-1', null, '"1"'));
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_GOOGLE, $provider);

        $this->invite($this->calendar(Calendar::PROVIDER_GOOGLE), ['anna@example.com'])->assertStatus(201);

        $sent = EventIcs::master(Reader::read($provider->calls('create')[0]['ics']), CalendarEvent::firstOrFail()->uid);
        $this->assertStringContainsString(__('Attendees: :attendees', ['attendees' => 'Anna Example <anna@example.com> (' . __('no answer yet') . ')']), (string) $sent->DESCRIPTION);
    }
}
