<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Conversation;
use App\Thread;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Sabre\VObject\Reader;

/**
 * Answers to the invites we send (spec section 13), processed when the mail arrives: an attendee's REPLY, from that
 * attendee's own address, updates their answer on our event and adds a line item. Such an answer leaves the
 * conversation's status as it was (decision 2026-10-19), so core's "a customer reply makes it active" is undone.
 */
class InviteReplies
{
    private const ANSWERS = ['ACCEPTED' => 'accepted', 'TENTATIVE' => 'tentative', 'DECLINED' => 'declined'];

    /** The status each conversation had before core made it active for a customer's mail, per conversation id. */
    private static array $statusBefore = [];

    private InviteService $invites;

    private EventService $events;

    public function __construct(InviteService $invites, EventService $events)
    {
        $this->invites = $invites;
        $this->events = $events;
    }

    /** conversation.status_changing: runs before core makes the conversation active, while it still has its status. */
    public static function rememberStatus(Conversation $conversation): void
    {
        // A new conversation has no id yet, and no status to keep (PHP 8.5 deprecates null as an array key, issue #94).
        if ($conversation->id === null) {
            return;
        }
        self::$statusBefore[$conversation->id] = (int) $conversation->status;
    }

    /**
     * conversation.created_by_customer and conversation.customer_replied (filters, before core saves the conversation):
     * reads the thread's invites, processes answers to ours, and keeps the status when the mail answered one.
     */
    public function receive(Conversation $conversation, Thread $thread): void
    {
        $answered = false;
        foreach ($thread->attachments as $attachment) {
            foreach ($this->invites->scan($attachment, $thread) as $invite) {
                $message = $this->invites->messageOf($attachment, $invite);
                if ($message !== null && $this->process($invite, $message)) {
                    $answered = true;
                }
            }
        }
        $before = self::$statusBefore[$conversation->id] ?? null;
        unset(self::$statusBefore[$conversation->id]);
        if ($answered && $before !== null && $before !== (int) $conversation->status) {
            $conversation->status = $before;
            $conversation->updateFolder();
            $thread->status = $before;
            $thread->save();
        }
    }

    /** Whether this message is an answer to one of our invites, processed now or before. */
    private function process(CalendarInvite $invite, InviteMessage $message): bool
    {
        if ($invite->status === CalendarInvite::STATUS_PROCESSED) {
            return true;
        }
        // One answer for the whole event: our attendees answer per event, not per occurrence.
        if ($message->method !== 'REPLY' || $message->recurrenceId !== null || $invite->status !== CalendarInvite::STATUS_PENDING) {
            return false;
        }
        $event = CalendarEvent::where('uid_hash', $invite->uid_hash)->whereNotNull('attendees')->orderBy('id')->first();
        $sender = strtolower((string) $invite->sender_email);
        $answer = null;
        foreach ($message->attendees as $attendee) {
            if ($attendee['email'] === $sender) {
                $answer = self::ANSWERS[$attendee['partstat']] ?? null;
            }
        }
        if ($event === null || $answer === null || !InviteSender::canInvite($event)) {
            return false;
        }
        $attendees = (array) $event->attendees;
        $name = null;
        foreach ($attendees as $index => $attendee) {
            if ($attendee['email'] === $sender) {
                $attendees[$index]['partstat'] = strtoupper($answer);
                $name = $attendee['name'] !== '' ? $attendee['name'] : $attendee['email'];
            }
        }
        // Only someone we invited answers, and only for themselves.
        if ($name === null) {
            return false;
        }

        $vcal = Reader::read($event->ics);
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ((string) $vevent->UID !== $event->uid) {
                continue;
            }
            foreach ($vevent->select('ATTENDEE') as $attendee) {
                if (EventIcs::email((string) $attendee) === $sender) {
                    $attendee['PARTSTAT'] = strtoupper($answer);
                }
            }
        }
        try {
            // CalDAV stores the answer with its attendee; Google and Microsoft show it in the readable block.
            $this->events->replaceIcs($event, $vcal, null, ['attendees' => $attendees]);
        } catch (ProviderException | SeriesNotWritable $e) {
            report($e); // the calendar catches up on the next change; FreeScout keeps the answer
            $event->attendees = $attendees;
            $event->save();
        }
        $invite->update(['status' => CalendarInvite::STATUS_PROCESSED, 'event_id' => $event->id, 'response' => $answer]);
        if ($conversation = Conversation::find($invite->conversation_id)) {
            InviteLineItem::add($conversation, null, 'attendee_answered', $event, ['title' => (string) $event->summary, 'name' => $name, 'answer' => $answer]);
        }

        return true;
    }
}
