<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Attachment;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Database\QueryException;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;

/**
 * Invites in a conversation's attachments (spec section 13): finds their iTIP messages, stores one row per message,
 * and decides what each card offers. Nothing is ever applied here; the agent clicks (InviteActions).
 */
class InviteService
{
    public const STATE_NEW = 'new';
    public const STATE_UPDATE = 'update';
    /** The same version as the one applied: nothing to do. */
    public const STATE_CURRENT = 'current';
    public const STATE_OUTDATED = 'outdated';
    public const STATE_CANCELLATION = 'cancellation';
    public const STATE_NOTHING_TO_CANCEL = 'nothing_to_cancel';
    /** The event is in a calendar this agent may not view, so the card reveals nothing about it. */
    public const STATE_HIDDEN = 'hidden';
    /** An answer to one of our own invites (processed automatically). */
    public const STATE_REPLY = 'reply';

    private const INVITE_TYPES = ['text/calendar', 'application/ics'];

    private InviteParser $parser;

    /** Parsed messages per attachment id, for one request. */
    private array $parsed = [];

    public function __construct(InviteParser $parser)
    {
        $this->parser = $parser;
    }

    public static function isInviteFile(Attachment $attachment): bool
    {
        return str_ends_with(strtolower((string) $attachment->file_name), '.ics')
            || in_array(strtolower((string) $attachment->mime_type), self::INVITE_TYPES, true);
    }

    /** @return CalendarInvite[] the rows of all invite files of the thread, created on the first scan */
    public function scanThread(Thread $thread): array
    {
        $rows = [];
        foreach ($thread->attachments as $attachment) {
            foreach ($this->scan($attachment, $thread) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return CalendarInvite[] */
    public function scan(Attachment $attachment, Thread $thread): array
    {
        if (!self::isInviteFile($attachment)) {
            return [];
        }
        $existing = CalendarInvite::where('attachment_id', $attachment->id)->orderBy('id')->get();
        if ($existing->isNotEmpty()) {
            return $existing->all();
        }
        $rows = [];
        foreach ($this->messages($attachment) as $message) {
            $keys = ['attachment_id' => $attachment->id, 'uid_hash' => CalendarEvent::hashUid($message->uid), 'recurrence_id' => (string) $message->recurrenceId];
            $values = [
                'conversation_id' => $thread->conversation_id,
                'thread_id'       => $thread->id,
                'uid'             => $message->uid,
                'method'          => $message->method,
                'sequence'        => $message->sequence,
                'dtstamp'         => $message->dtstamp,
                'organizer_email' => $message->organizerEmail,
                'organizer_name'  => $message->organizerName !== null ? mb_substr($message->organizerName, 0, 191) : null,
                'sender_email'    => self::sender($thread),
                'summary'         => mb_substr($message->summary, 0, 500),
                'status'          => CalendarInvite::STATUS_PENDING, // also on the model just created, not only in the table
            ];
            try {
                $rows[] = CalendarInvite::firstOrCreate($keys, $values);
            } catch (QueryException $e) {
                // Another request (two agents opening the conversation) stored it at the same moment.
                $rows[] = CalendarInvite::where($keys)->firstOrFail();
            }
        }

        return $rows;
    }

    /**
     * The cards of a thread, one per (UID, occurrence): Gmail's calendar.ics and invite.ics pair is one card. Each card is
     * ['invite' => CalendarInvite, 'message' => InviteMessage, 'state' => STATE_*, 'event' => ?CalendarEvent, 'warnings' => string[]].
     */
    public function cards(Thread $thread, User $user): array
    {
        $cards = [];
        foreach ($thread->attachments as $attachment) {
            foreach ($this->scan($attachment, $thread) as $invite) {
                $key = $invite->uid_hash . '|' . $invite->recurrence_id;
                $message = $this->messageOf($attachment, $invite);
                if (!isset($cards[$key]) && $message !== null) {
                    $cards[$key] = $this->classify($invite, $message, $user);
                }
            }
        }

        return array_values($cards);
    }

    /** The parsed message an invite row stands for, read again from its attachment. */
    public function messageOf(Attachment $attachment, CalendarInvite $invite): ?InviteMessage
    {
        foreach ($this->messages($attachment) as $message) {
            if (CalendarEvent::hashUid($message->uid) === $invite->uid_hash && (string) $message->recurrenceId === $invite->recurrence_id) {
                return $message;
            }
        }

        return null;
    }

    public function classify(CalendarInvite $invite, InviteMessage $message, User $user): array
    {
        $card = ['invite' => $invite, 'message' => $message, 'state' => self::STATE_NEW, 'event' => null, 'warnings' => self::warnings($invite, $message)];
        $event = CalendarEvent::where('uid_hash', $invite->uid_hash)->with('calendar')->orderBy('id')->first();
        if ($event !== null && !$user->can('view', $event->calendar)) {
            return ['state' => self::STATE_HIDDEN] + $card;
        }
        $card['event'] = $event;
        if ($message->method === 'REPLY') {
            return ['state' => self::STATE_REPLY] + $card;
        }
        if ($message->method === 'CANCEL') {
            if ($event === null) {
                return ['state' => self::STATE_NOTHING_TO_CANCEL] + $card;
            }
            $cancelled = $invite->status === CalendarInvite::STATUS_APPLIED || $this->applied($invite)->where('method', 'CANCEL')->exists();

            return ['state' => $cancelled ? self::STATE_CURRENT : self::STATE_CANCELLATION] + $card;
        }
        if ($event === null) {
            return $card;
        }
        $state = $this->compare($invite, $event);
        // An applied version stays current until a newer one is applied.
        if ($invite->status === CalendarInvite::STATUS_APPLIED && $state !== self::STATE_OUTDATED) {
            $state = self::STATE_CURRENT;
        }

        return ['state' => $state] + $card;
    }

    /**
     * iTIP ordering (RFC 5546): the higher SEQUENCE wins, then the later DTSTAMP. Compared with the last version applied
     * from a mail; an event that came another way (Google adding invites to the account's calendar by itself) is
     * compared by its own SEQUENCE.
     */
    private function compare(CalendarInvite $invite, CalendarEvent $event): string
    {
        $applied = $this->applied($invite)->whereIn('method', ['REQUEST', 'PUBLISH', 'ADD'])
            ->orderBy('sequence', 'desc')->orderBy('dtstamp', 'desc')->first();
        $sequence = $applied ? $applied->sequence : $event->sequence;
        $dtstamp = $applied ? $applied->dtstamp : null;
        if ($invite->sequence !== $sequence) {
            return $invite->sequence > $sequence ? self::STATE_UPDATE : self::STATE_OUTDATED;
        }
        if ($dtstamp === null || $invite->dtstamp === null || $invite->dtstamp === $dtstamp) {
            return self::STATE_CURRENT;
        }

        return $invite->dtstamp > $dtstamp ? self::STATE_UPDATE : self::STATE_OUTDATED;
    }

    private function applied(CalendarInvite $invite)
    {
        return CalendarInvite::where('uid_hash', $invite->uid_hash)
            ->where('recurrence_id', $invite->recurrence_id)
            ->where('status', CalendarInvite::STATUS_APPLIED)
            ->where('id', '<>', $invite->id);
    }

    private static function warnings(CalendarInvite $invite, InviteMessage $message): array
    {
        $warnings = [];
        $sender = strtolower((string) $invite->sender_email);
        // Outlook and Gmail apply invites from anyone who knows the UID; a mismatch is shown, never acted on.
        if ($message->organizerEmail !== null && $sender !== '' && !in_array($sender, array_filter([$message->organizerEmail, $message->organizerAlias]), true)) {
            $warnings[] = 'sender_not_organizer';
        }
        if ($message->thisAndFuture) {
            $warnings[] = 'this_and_following';
        }

        return $warnings;
    }

    /** The mailbox's own address (or alias) among the invite's attendees that may answer, as the invite lists it. */
    public static function ourAddress(InviteMessage $message, Mailbox $mailbox): ?string
    {
        $ours = array_map('strtolower', array_keys($mailbox->getAliases()));
        foreach ($message->attendees as $attendee) {
            if ($attendee['rsvp'] && in_array($attendee['email'], $ours, true)) {
                return $attendee['email'];
            }
        }

        return null;
    }

    private static function sender(Thread $thread): ?string
    {
        $from = strtolower(trim((string) $thread->from));

        return $from !== '' ? $from : null;
    }

    /** @return InviteMessage[] */
    private function messages(Attachment $attachment): array
    {
        if (!array_key_exists($attachment->id, $this->parsed)) {
            $this->parsed[$attachment->id] = $this->parser->parse((string) $attachment->getFileContents());
        }

        return $this->parsed[$attachment->id];
    }
}
