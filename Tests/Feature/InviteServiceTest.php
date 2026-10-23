<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Thread;
use App\User;
use Illuminate\Support\Facades\Storage;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\InviteService;
use Modules\LJPcCalendarModule\Tests\Support\InviteMail;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** Finding invites in a conversation's attachments and what their card offers (spec section 13). */
class InviteServiceTest extends TestCase
{
    use InviteMail;

    private const GMAIL_UID = '7kukuqrdgsj8e2a3l4nbo1m1fs@google.com';

    private InviteService $invites;
    private User $agent;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::getDiskName());
        $this->invites = app(InviteService::class);
        $this->agent = $this->makeUser();
        $this->conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->makeMailbox([$this->agent->id])->id, 'status' => Conversation::STATUS_ACTIVE, 'customer_email' => 'anna@example.com',
        ]);
    }

    private static function fixture(string $name): string
    {
        return self::inviteFixture($name);
    }

    private function thread(array $files, string $from = 'anna@example.com'): Thread
    {
        return $this->inviteThread($this->conversation, $files, $from);
    }

    private function calendar(bool $viewable = true): Calendar
    {
        $calendar = Calendar::create(['name' => 'Invites ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        if ($viewable) {
            CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $this->agent->id, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);
        }

        return $calendar;
    }

    private function storedEvent(Calendar $calendar, string $uid, int $sequence = 0): CalendarEvent
    {
        return CalendarEvent::create([
            'calendar_id' => $calendar->id, 'uid' => $uid, 'ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\nBEGIN:VEVENT\r\nUID:" . $uid . "\r\nDTSTART:20261103T090000Z\r\nDTEND:20261103T100000Z\r\nSUMMARY:Stored\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            'sequence' => $sequence, 'summary' => 'Stored', 'starts_at' => '2026-11-03 09:00:00', 'ends_at' => '2026-11-03 10:00:00',
        ]);
    }

    /** @return array<string, string> card state by "uid|recurrence" */
    private function states(Thread $thread): array
    {
        $states = [];
        foreach ($this->invites->cards($thread, $this->agent) as $card) {
            $states[$card['invite']->uid . '|' . $card['invite']->recurrence_id] = $card['state'];
        }

        return $states;
    }

    public function test_scanning_stores_each_message_once_with_its_sender(): void
    {
        $thread = $this->thread(['invite.ics' => self::fixture('gmail-request.ics'), 'terms.pdf' => '%PDF-1.4']);

        $first = $this->invites->scanThread($thread);
        $again = $this->invites->scanThread($thread);

        $this->assertCount(1, $first);
        $this->assertSame([$first[0]->id], array_map(function (CalendarInvite $invite) {
            return $invite->id;
        }, $again));
        $invite = $first[0]->fresh();
        $this->assertSame(
            [$this->conversation->id, $thread->id, self::GMAIL_UID, '', 'REQUEST', 0, '2026-10-19 12:00:00', 'anna@example.com', 'anna@example.com', 'Contract review', CalendarInvite::STATUS_PENDING],
            [$invite->conversation_id, $invite->thread_id, $invite->uid, $invite->recurrence_id, $invite->method, $invite->sequence, $invite->dtstamp, $invite->organizer_email, $invite->sender_email, $invite->summary, $invite->status]
        );
    }

    public function test_invite_files_are_known_by_name_or_type(): void
    {
        $thread = factory(Thread::class)->create(['conversation_id' => $this->conversation->id, 'type' => Thread::TYPE_CUSTOMER, 'from' => 'bram@example.net']);
        Attachment::create('MEETING.ICS', 'application/octet-stream', Attachment::TYPE_APPLICATION, self::fixture('outlook-request.ics'), null, false, $thread->id);
        Attachment::create('calendar', 'text/calendar', Attachment::TYPE_TEXT, self::fixture('cancel-occurrence.ics'), null, false, $thread->id);
        Attachment::create('notes.txt', 'text/plain', Attachment::TYPE_TEXT, self::fixture('gmail-request.ics'), null, false, $thread->id);

        $methods = array_map(function (CalendarInvite $invite) {
            return $invite->method . '|' . $invite->recurrence_id;
        }, $this->invites->scanThread($thread->fresh()));
        sort($methods);

        $this->assertSame(['CANCEL|20261117T090000Z', 'REQUEST|'], $methods);
    }

    public function test_new_mail_is_scanned_when_it_arrives(): void
    {
        $thread = $this->thread(['invite.ics' => self::fixture('gmail-request.ics')]);

        // As FetchEmails runs it: the filter, before core saves the conversation.
        $this->assertSame($this->conversation, \Eventy::filter('conversation.customer_replied', $this->conversation, $thread, Customer::create('anna@example.com')));

        $this->assertSame(1, CalendarInvite::where('thread_id', $thread->id)->count());
    }

    public function test_gmails_pair_of_files_is_one_card(): void
    {
        $thread = $this->thread(['calendar.ics' => self::fixture('gmail-request.ics'), 'invite.ics' => self::fixture('gmail-request.ics')]);

        $this->assertSame([self::GMAIL_UID . '|' => InviteService::STATE_NEW], $this->states($thread));
    }

    public function test_an_invite_for_an_event_we_have_is_an_update_until_it_is_applied_and_an_older_one_is_outdated(): void
    {
        $calendar = $this->calendar();
        $event = $this->storedEvent($calendar, self::GMAIL_UID);
        $first = $this->thread(['invite.ics' => self::fixture('gmail-request.ics')]);
        $applied = $this->invites->scanThread($first)[0];
        $applied->update(['status' => CalendarInvite::STATUS_APPLIED, 'event_id' => $event->id]);
        $this->assertSame([self::GMAIL_UID . '|' => InviteService::STATE_CURRENT], $this->states($first));

        // The organizer moved it: a higher sequence.
        $moved = $this->thread(['invite.ics' => str_replace('SEQUENCE:0', 'SEQUENCE:1', self::fixture('gmail-request.ics'))]);
        $this->assertSame([self::GMAIL_UID . '|' => InviteService::STATE_UPDATE], $this->states($moved));
        // Same sequence, but sent later: an update too.
        $later = $this->thread(['invite.ics' => str_replace('DTSTAMP:20261019T120000Z', 'DTSTAMP:20261020T080000Z', self::fixture('gmail-request.ics'))]);
        $this->assertSame([self::GMAIL_UID . '|' => InviteService::STATE_UPDATE], $this->states($later));

        $this->invites->scanThread($moved)[0]->update(['status' => CalendarInvite::STATUS_APPLIED, 'event_id' => $event->id]);
        $this->assertSame([self::GMAIL_UID . '|' => InviteService::STATE_OUTDATED], $this->states($first));
    }

    public function test_an_event_that_arrived_another_way_is_compared_by_its_own_sequence(): void
    {
        // Google puts invites in the account's calendar by itself; the sync brought it in with sequence 3.
        $this->storedEvent($this->calendar(), self::GMAIL_UID, 3);

        $this->assertSame([self::GMAIL_UID . '|' => InviteService::STATE_OUTDATED], $this->states($this->thread(['invite.ics' => self::fixture('gmail-request.ics')])));
    }

    public function test_a_cancellation_needs_an_event_to_cancel(): void
    {
        $nothing = $this->thread(['invite.ics' => self::fixture('cancel-series.ics')]);
        $this->assertSame([self::GMAIL_UID . '|' => InviteService::STATE_NOTHING_TO_CANCEL], $this->states($nothing));

        $this->storedEvent($this->calendar(), self::GMAIL_UID);
        $this->assertSame([self::GMAIL_UID . '|' => InviteService::STATE_CANCELLATION], $this->states($nothing));
    }

    public function test_an_event_in_a_calendar_the_agent_cannot_view_is_not_revealed(): void
    {
        $this->storedEvent($this->calendar(false), self::GMAIL_UID);

        $card = $this->invites->cards($this->thread(['invite.ics' => self::fixture('gmail-request.ics')]), $this->agent)[0];

        $this->assertSame([InviteService::STATE_HIDDEN, null], [$card['state'], $card['event']]);
    }

    public function test_the_card_warns_when_the_sender_is_not_the_organizer_and_for_this_and_following(): void
    {
        $spoofed = $this->invites->cards($this->thread(['invite.ics' => self::fixture('gmail-request.ics')], 'someone@example.net'), $this->agent)[0];
        $icloud = $this->invites->cards($this->thread(['invite.ics' => self::fixture('icloud-request.ics')], 'Chris@Example.com'), $this->agent)[0];
        $following = $this->invites->cards($this->thread(['invite.ics' => self::fixture('request-occurrence.ics')], 'bram@example.net'), $this->agent)[0];

        $this->assertSame(['sender_not_organizer'], $spoofed['warnings']);
        $this->assertSame([], $icloud['warnings'], 'iCloud sends from the address behind its relay');
        $this->assertSame(['this_and_following'], $following['warnings']);
        $this->assertSame('Ticket sync (moved)', $following['message']->summary);
    }
}
