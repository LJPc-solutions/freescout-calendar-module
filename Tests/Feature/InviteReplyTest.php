<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\InviteSender;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\Support\InviteMail;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

/** An attendee's answer to an invite we sent, processed when the mail arrives (spec section 13; decision 2026-10-19). */
class InviteReplyTest extends TestCase
{
    use InviteMail;

    private User $agent;
    private Mailbox $mailbox;
    private Conversation $conversation;
    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::getDiskName());
        Queue::fake();
        app(OccurrenceService::class)->moveWindow(new \DateTimeImmutable('2026-10-05', new \DateTimeZone('UTC')));
        $this->agent = $this->makeUser();
        $this->mailbox = $this->makeMailbox([$this->agent->id]);
        $this->mailbox->email = 'support@example.org';
        $this->mailbox->save();
        $customer = Customer::create('dana@example.com', ['first_name' => 'Dana', 'last_name' => 'Example']);
        $this->conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id, 'status' => Conversation::STATUS_CLOSED, 'customer_email' => 'dana@example.com', 'customer_id' => $customer->id,
        ]);
        $calendar = Calendar::create(['name' => 'Intake', 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $this->agent->id, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $this->event = app(EventService::class)->create($calendar, $this->agent, [
            'title' => 'Intake call', 'start' => '2026-11-10T10:00', 'end' => '2026-11-10T11:00',
            'attendees' => InviteSender::attendees(['dana@example.com'], $this->conversation), 'organizer' => InviteSender::organizer($this->conversation),
        ], $this->conversation);
    }

    /** A mail with the answer arrives, the way FetchEmails runs core's hooks for a reply in the conversation. */
    private function arrives(string $from, array $replace = []): Thread
    {
        \Eventy::filter('conversation.status_changing', Conversation::STATUS_ACTIVE, $this->conversation);
        $this->conversation->status = Conversation::STATUS_ACTIVE;
        $this->conversation->save();
        $ics = strtr(self::inviteFixture('reply-accepted.ics'), $replace + ['freescout-intake-381@example.org' => $this->event->uid]);
        $thread = $this->inviteThread($this->conversation, ['invite.ics' => $ics], $from);
        $thread->status = Conversation::STATUS_ACTIVE;
        $thread->save();
        $conversation = \Eventy::filter('conversation.customer_replied', $this->conversation, $thread, Customer::getByEmail($from));
        $conversation->save();

        return $thread->fresh();
    }

    private function partstat(CalendarEvent $event): string
    {
        return (string) EventIcs::master(Reader::read($event->ics), $event->uid)->ATTENDEE['PARTSTAT'];
    }

    public function test_an_attendees_answer_is_recorded_and_leaves_the_conversation_closed(): void
    {
        $thread = $this->arrives('dana@example.com');

        $event = $this->event->fresh();
        $this->assertSame('ACCEPTED', $event->attendees[0]['partstat']);
        $this->assertSame('ACCEPTED', $this->partstat($event));
        $invite = CalendarInvite::where('thread_id', $thread->id)->firstOrFail();
        $this->assertSame([CalendarInvite::STATUS_PROCESSED, $event->id, 'accepted'], [$invite->status, $invite->event_id, $invite->response]);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $this->conversation->fresh()->status);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $thread->status);
        $item = Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_LINEITEM)->orderBy('id', 'desc')->firstOrFail();
        $this->assertSame(['attendee_answered', 'Dana Example', 'accepted'], [$item->getMeta('invite_action'), $item->getMeta('name'), $item->getMeta('answer')]);

        $this->actingAs($this->agent);
        ob_start();
        \Eventy::action('thread.attachments_list_append', $thread, $this->conversation, $this->mailbox);
        $this->assertStringContainsString(e(__('This answer is recorded with the event.')), (string) ob_get_clean());
    }

    public function test_an_answer_that_starts_a_conversation_is_recorded(): void
    {
        // FetchEmails asks for the status of a new conversation before saving it, so it has no id yet (issue #94).
        $conversation = factory(Conversation::class)->make([
            'mailbox_id' => $this->mailbox->id, 'customer_email' => 'dana@example.com', 'customer_id' => $this->conversation->customer_id,
        ]);
        $conversation->status = \Eventy::filter('conversation.status_changing', Conversation::STATUS_ACTIVE, $conversation);
        $conversation->save();
        $ics = strtr(self::inviteFixture('reply-accepted.ics'), ['freescout-intake-381@example.org' => $this->event->uid]);
        $thread = $this->inviteThread($conversation, ['invite.ics' => $ics], 'dana@example.com');
        \Eventy::filter('conversation.created_by_customer', $conversation, $thread, Customer::getByEmail('dana@example.com'))->save();

        $this->assertSame('ACCEPTED', $this->event->fresh()->attendees[0]['partstat']);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status, 'a new conversation has no status to keep');
    }

    public function test_an_answer_from_another_address_or_to_someone_elses_event_changes_nothing(): void
    {
        $this->arrives('mallory@example.net', ['mailto:dana@example.com' => 'mailto:mallory@example.net']);

        $this->assertSame('NEEDS-ACTION', $this->event->fresh()->attendees[0]['partstat'], 'only someone we invited answers');
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $this->conversation->fresh()->status, 'any other mail reopens as usual');

        $this->arrives('dana@example.com', ['freescout-intake-381@example.org' => 'unknown@example.org']);
        $this->assertSame('NEEDS-ACTION', $this->event->fresh()->attendees[0]['partstat']);
    }
}
