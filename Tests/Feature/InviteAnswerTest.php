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
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\InviteActions;
use Modules\LJPcCalendarModule\Services\InviteService;
use Modules\LJPcCalendarModule\Services\ItipMail;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\Support\InviteMail;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

/** Yes, Maybe and No on an invite card: the event, our stored answer and the REPLY to the organizer (spec section 13). */
class InviteAnswerTest extends TestCase
{
    use InviteMail;

    private const UID = '7kukuqrdgsj8e2a3l4nbo1m1fs@google.com';

    private User $agent;
    private Mailbox $mailbox;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::getDiskName());
        Queue::fake();
        app(OccurrenceService::class)->moveWindow(new \DateTimeImmutable('2026-10-05', new \DateTimeZone('UTC')));
        $this->agent = $this->makeUser();
        $this->mailbox = $this->makeMailbox([$this->agent->id]);
        $this->mailbox->email = 'support@example.org'; // an attendee of gmail-request.ics
        $this->mailbox->save();
        $this->conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id, 'status' => Conversation::STATUS_ACTIVE, 'customer_email' => 'anna@example.com',
            'customer_id' => Customer::create('anna@example.com')->id,
        ]);
    }

    private function calendar(string $provider = Calendar::PROVIDER_LOCAL, array $rights = ['can_view' => true, 'can_create' => true, 'can_edit' => true]): Calendar
    {
        $attributes = ['name' => 'Team ' . uniqid(), 'provider' => $provider];
        if ($provider !== Calendar::PROVIDER_LOCAL) {
            $connection = CalendarConnection::create(['provider' => $provider, 'account' => 'team@example.org']);
            $attributes += ['connection_id' => $connection->id, 'remote_id' => 'team@example.org'];
        }
        $calendar = Calendar::create($attributes);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $this->agent->id] + $rights);

        return $calendar;
    }

    private function invite(array $replace = []): CalendarInvite
    {
        $thread = $this->inviteThread($this->conversation, ['invite.ics' => strtr(self::inviteFixture('gmail-request.ics'), $replace)]);

        return app(InviteService::class)->scanThread($thread)[0];
    }

    private function answer(CalendarInvite $invite, array $body, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->agent)->postJson(route('ljpccalendarmodule.api.invites.answer', ['id' => $invite->id]), $body);
    }

    /** What core prints below the invite's message. */
    private function card(CalendarInvite $invite): string
    {
        $this->actingAs($this->agent);
        ob_start();
        \Eventy::action('thread.attachments_list_append', Thread::find($invite->thread_id), $this->conversation, $this->mailbox);

        return (string) ob_get_clean();
    }

    private function lastReply(): Thread
    {
        return Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_MESSAGE)->orderBy('id', 'desc')->firstOrFail();
    }

    private static function ourPartstat(CalendarEvent $event): string
    {
        foreach (EventIcs::master(Reader::read($event->ics), $event->uid)->select('ATTENDEE') as $attendee) {
            if ((string) $attendee === 'mailto:support@example.org') {
                return (string) $attendee['PARTSTAT'];
            }
        }

        return '';
    }

    private function lineItems(): array
    {
        return Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_LINEITEM)->orderBy('id')->get()
            ->map(function (Thread $thread) {
                return $thread->getMeta('invite_action');
            })->all();
    }

    public function test_yes_adds_the_event_and_answers_the_organizer_in_the_conversation(): void
    {
        $calendar = $this->calendar();
        $invite = $this->invite();
        $this->assertStringContainsString('name="response" value="accepted"', $this->card($invite));

        $answer = $this->answer($invite, ['response' => 'accepted', 'calendar_id' => $calendar->id])->assertStatus(200)->json();

        $this->assertSame(__('You accepted the invite. The organizer gets your answer.'), $answer['message']);
        $this->assertStringContainsString(e(__('You accepted this invite.')), $answer['html']);
        $event = CalendarEvent::where('calendar_id', $calendar->id)->firstOrFail();
        $this->assertSame('ACCEPTED', self::ourPartstat($event));
        $reply = $this->lastReply();
        $this->assertSame(['anna@example.com'], $reply->getToArray());
        $this->assertSame('anna@example.com', $reply->customer->getMainEmail());
        $itip = $reply->getMeta(ItipMail::META);
        $this->assertSame(['REPLY', __('Accepted: :title', ['title' => 'Contract review'])], [$itip['method'], $itip['subject']]);
        $vcal = Reader::read($itip['ics']);
        $this->assertSame(['REPLY', self::UID, 'mailto:anna@example.com'], [(string) $vcal->METHOD, (string) $vcal->VEVENT->UID, (string) $vcal->VEVENT->ORGANIZER]);
        $this->assertCount(1, $vcal->VEVENT->select('ATTENDEE'));
        $this->assertSame(EventIcs::PRODID, (string) $vcal->PRODID);
        $this->assertSame(['mailto:support@example.org', 'ACCEPTED'], [(string) $vcal->VEVENT->ATTENDEE, (string) $vcal->VEVENT->ATTENDEE['PARTSTAT']]);
        $this->assertSame('accepted', $invite->fresh()->response);
        $this->assertSame(['added', 'answered'], $this->lineItems());
        Queue::assertPushedOn('emails', SendReplyToCustomer::class);
    }

    public function test_no_keeps_the_event_struck_through_and_a_later_yes_takes_that_back(): void
    {
        $calendar = $this->calendar();
        $invite = $this->invite();
        app(InviteActions::class)->apply($invite, $this->agent, $calendar);

        $this->answer($invite->fresh(), ['response' => 'declined'])->assertStatus(200);

        $event = CalendarEvent::where('uid', self::UID)->firstOrFail();
        $this->assertSame([InviteActions::STATE_DECLINED, 'DECLINED'], [$event->invite_state, self::ourPartstat($event)]);
        $listed = $this->actingAs($this->agent)->getJson(route('ljpccalendarmodule.api.events', ['start' => '2026-11-01', 'end' => '2026-11-08']))->assertStatus(200)->json();
        $this->assertSame(['declined'], array_column($listed, 'status'), 'the calendar page strikes it through');
        $this->assertSame(__('Declined: :title', ['title' => 'Contract review']), $this->lastReply()->getMeta(ItipMail::META)['subject']);

        $this->answer($invite->fresh(), ['response' => 'tentative'])->assertStatus(200);

        $event = $event->fresh();
        $this->assertSame([null, 'TENTATIVE'], [$event->invite_state, self::ourPartstat($event)]);
        $this->assertSame(['added', 'answered', 'answered'], $this->lineItems());
    }

    public function test_no_to_an_invite_in_no_calendar_only_answers(): void
    {
        $invite = $this->invite(['SUMMARY:Contract review' => 'SUMMARY:Contract\\nreview']);

        $this->answer($invite, ['response' => 'declined'])->assertStatus(200);

        $this->assertSame(0, CalendarEvent::where('uid', self::UID)->count());
        $this->assertSame('declined', $invite->fresh()->response);
        $this->assertSame(__('Declined: :title', ['title' => 'Contract review']), $this->lastReply()->getMeta(ItipMail::META)['subject'], 'one line');
        $this->assertSame(['answered'], $this->lineItems());
    }

    public function test_google_and_microsoft_say_declined_in_the_title(): void
    {
        $provider = new FakeProvider();
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_GOOGLE, $provider);
        $invite = $this->invite();
        $actions = app(InviteActions::class);
        $actions->apply($invite, $this->agent, $this->calendar(Calendar::PROVIDER_GOOGLE));

        $actions->answer($invite->fresh(), $this->agent, 'declined', null);
        $actions->answer($invite->fresh(), $this->agent, 'accepted', null);

        $updates = array_map(function (array $call) {
            $vevent = EventIcs::master(Reader::read($call['ics']), self::UID);

            return [(string) $vevent->SUMMARY, (string) $vevent->TRANSP];
        }, $provider->calls('update'));
        $this->assertSame([[__('Declined: :title', ['title' => 'Contract review']), 'TRANSPARENT'], ['Contract review', 'OPAQUE']], $updates);
    }

    public function test_only_an_invited_mailbox_answers_and_an_alias_answers_as_itself(): void
    {
        $this->calendar();
        $this->assertStringNotContainsString('name="response"', $this->card($this->invite(['RSVP=TRUE;CN=support@example.org' => 'RSVP=FALSE;CN=support@example.org'])));
        $this->mailbox->email = 'help@example.org';
        $this->mailbox->save();
        $invite = $this->invite();
        $this->assertStringNotContainsString('name="response"', $this->card($invite));
        $this->answer($invite, ['response' => 'declined'])->assertStatus(422);

        $this->mailbox->aliases = 'Support@example.org';
        $this->mailbox->save();
        $this->answer($invite, ['response' => 'declined'])->assertStatus(200);

        // Core sends from the thread's address only when it is one of the mailbox's aliases, as written there.
        $this->assertArrayHasKey($this->lastReply()->from, $this->mailbox->fresh()->getAliases(false));
        $this->assertSame('support@example.org', strtolower($this->lastReply()->from));
    }

    public function test_answers_check_the_agents_rights(): void
    {
        $viewOnly = $this->calendar(Calendar::PROVIDER_LOCAL, ['can_view' => true]);
        $invite = $this->invite();

        $this->answer($invite, ['response' => 'accepted'], $this->makeUser())->assertStatus(404); // not in the mailbox
        $this->answer($invite, ['response' => 'accepted', 'calendar_id' => $viewOnly->id])->assertStatus(403);
        $this->answer($invite, ['response' => 'yes'])->assertStatus(422);
        $this->assertSame(0, Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_MESSAGE)->count());

        // An event in a calendar records the answer, which needs the right to edit that calendar.
        $editable = $this->calendar();
        app(InviteActions::class)->apply($invite, $this->agent, $editable);
        CalendarPermission::where('calendar_id', $editable->id)->update(['can_edit' => false]);
        $this->answer($invite->fresh(), ['response' => 'declined'])->assertStatus(403);
    }

    public function test_the_reply_mail_carries_the_itip_part_and_its_subject(): void
    {
        $thread = new Thread();
        $thread->setMeta(ItipMail::META, ['method' => 'REPLY', 'subject' => 'Accepted: Contract review', 'ics' => "BEGIN:VCALENDAR\r\nMETHOD:REPLY\r\nEND:VCALENDAR\r\n"]);
        $message = new \Swift_Message('Re: Printer', '<p>Answer</p>', 'text/html');
        $message->attach(new \Swift_Attachment("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'invite.ics', 'text/calendar'));

        \Eventy::action('email.reply_to_customer.swiftmessage', $message, '', $thread, $this->mailbox);

        $raw = $message->toString();
        $this->assertStringContainsString('Content-Type: multipart/alternative', $raw);
        $this->assertStringContainsString('Content-Type: text/calendar; charset=utf-8; method=REPLY', $raw);
        $this->assertStringContainsString('METHOD:REPLY', $raw);
        $this->assertStringContainsString('Content-Type: application/ics; name=invite.ics', $raw);
        $this->assertSame('Accepted: Contract review', \Eventy::filter('email.reply_to_customer.subject', 'Re: Printer', $this->conversation, $thread));
        $this->assertSame('Re: Printer', \Eventy::filter('email.reply_to_customer.subject', 'Re: Printer', $this->conversation, new Thread()));
    }
}
