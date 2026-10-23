<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Conversation;
use App\Customer;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Invites we send (spec section 13, outgoing; decisions of 2026-10-19). Only an event linked to a conversation gets
 * attendees, and the conversation's mailbox organizes it. Each mail is a reply in the conversation to the people it
 * concerns: an optional message from the agent, a fixed summary, and the iTIP message inline and as invite.ics.
 * The attendees and their answers live in calendar_events.attendees, which a sync from Google or Microsoft (they
 * never get attendees) leaves alone.
 */
class InviteSender
{
    public const MAX_ATTENDEES = 50;

    public static function canInvite(CalendarEvent $event): bool
    {
        // An invite someone sent us has an organizer of its own; only our own events get attendees.
        return $event->conversation_id !== null && Conversation::find($event->conversation_id) !== null
            && !CalendarInvite::where('uid_hash', $event->uid_hash)->where('method', '<>', 'REPLY')->exists();
    }

    /**
     * The addresses an agent typed, checked, with the answers of the people who were invited before.
     *
     * @param string[] $emails
     * @return array<int, array{email: string, name: string, partstat: string}>
     */
    public static function attendees(array $emails, Conversation $conversation, array $before = []): array
    {
        $ours = array_map('strtolower', array_keys($conversation->mailbox->getAliases()));
        $known = array_column($before, null, 'email');
        $list = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '' || isset($list[$email])) {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['attendees' => __(':email is not an email address.', ['email' => $email])]);
            }
            if (in_array($email, $ours, true)) {
                throw ValidationException::withMessages(['attendees' => __('The mailbox sends the invite, so leave its own address out.')]);
            }
            $customer = Customer::getByEmail($email);
            $list[$email] = $known[$email] ?? ['email' => $email, 'name' => $customer ? trim((string) $customer->getFullName()) : '', 'partstat' => 'NEEDS-ACTION'];
        }
        if (count($list) > self::MAX_ATTENDEES) {
            throw ValidationException::withMessages(['attendees' => __('Invite at most :count people.', ['count' => self::MAX_ATTENDEES])]);
        }

        return array_values($list);
    }

    public static function organizer(Conversation $conversation): array
    {
        return ['email' => strtolower((string) $conversation->mailbox->email), 'name' => (string) $conversation->mailbox->name];
    }

    /**
     * After an agent changed an event that has (or had) attendees: an invitation for the people added, an update for
     * the others when more than the list changed, a cancellation for the people taken off.
     */
    public function notify(CalendarEvent $event, User $user, array $before, bool $changed): void
    {
        $old = array_column($before, null, 'email');
        $new = array_column((array) $event->attendees, null, 'email');
        if ($added = array_values(array_diff_key($new, $old))) {
            $this->send($event, $user, 'REQUEST', $added, '', 'sent');
        }
        if ($changed && ($kept = array_values(array_intersect_key($new, $old)))) {
            $this->send($event, $user, 'REQUEST', $kept, '', 'sent_update');
        }
        if ($removed = array_values(array_diff_key($old, $new))) {
            $this->send($event, $user, 'CANCEL', $removed, '', 'sent_cancellation');
        }
    }

    /**
     * @param array $recipients attendees (as stored) the mail goes to
     * @param string $action the line item: sent, sent_update or sent_cancellation
     */
    public function send(CalendarEvent $event, User $user, string $method, array $recipients, string $note, string $action): void
    {
        $conversation = Conversation::find($event->conversation_id);
        if (!$conversation || !$recipients) {
            return;
        }
        $organizer = self::organizer($conversation);
        // A cancellation lists the people it cancels for; an invitation or update lists everyone invited.
        $ics = self::itip($event, $method, $method === 'CANCEL' ? $recipients : (array) $event->attendees, $organizer)->serialize();
        $to = [];
        foreach ($recipients as $attendee) {
            $to[$attendee['email']] = $attendee['name'];
        }
        // Customer-facing, so in the install's language rather than the agent's.
        $locale = config('app.locale');
        $title = (string) $event->summary;
        $subjects = [
            'sent'              => __('Invitation: :title', ['title' => $title], $locale),
            'sent_update'       => __('Updated invitation: :title', ['title' => $title], $locale),
            'sent_cancellation' => __('Cancelled: :title', ['title' => $title], $locale),
        ];
        ItipMail::send($conversation, $user, $to, null, $subjects[$action], self::body($event, $method, $note, $locale), $method, $ics, true);
        InviteLineItem::add($conversation, $user, $action, $event->exists ? $event : null, ['title' => $title, 'to' => implode(', ', array_keys($to))]);
    }

    /** The event as the iTIP message: its components with our organizer and attendees, without FreeScout's readable block. */
    private static function itip(CalendarEvent $event, string $method, array $attendees, array $organizer): VCalendar
    {
        $stored = Reader::read($event->ics);
        $vcal = new VCalendar(['PRODID' => EventIcs::PRODID, 'METHOD' => $method]);
        foreach ($stored->select('VEVENT') as $vevent) {
            // A cancellation of the whole event is its master alone.
            if ((string) $vevent->UID !== $event->uid || ($method === 'CANCEL' && isset($vevent->{'RECURRENCE-ID'}))) {
                continue;
            }
            $copy = clone $vevent;
            $description = ReadableBlock::strip((string) ($copy->DESCRIPTION ?? ''));
            unset($copy->DESCRIPTION);
            if (trim($description) !== '') {
                $copy->DESCRIPTION = $description;
            }
            EventIcs::apply($copy, ['attendees' => $attendees, 'organizer' => $organizer]);
            if ($method === 'CANCEL') {
                $copy->STATUS = 'CANCELLED';
                EventIcs::bumpSequence($copy);
            }
            $vcal->add($copy);
        }
        // Every TZID needs its VTIMEZONE (Outlook reads nothing else); a stored one stands in for a zone PHP doesn't know.
        VTimezone::addForEvents($vcal);
        $present = array_map(function ($zone) {
            return (string) $zone->TZID;
        }, $vcal->select('VTIMEZONE'));
        foreach ($stored->select('VTIMEZONE') as $zone) {
            if (!in_array((string) $zone->TZID, $present, true)) {
                $vcal->add(clone $zone);
            }
        }

        return $vcal;
    }

    /** The agent's message, then what, when and where; the time in the event's own zone, which it names. */
    private static function body(CalendarEvent $event, string $method, string $note, string $locale): string
    {
        $html = trim($note) !== '' ? '<p>' . nl2br(e(trim($note))) . '</p>' : '';
        if ($method === 'CANCEL') {
            $html .= '<p>' . e(__('This event is cancelled.', [], $locale)) . '</p>';
        }
        $zone = new DateTimeZone($event->timezone ?: $event->calendar->timezoneName());
        if ($event->all_day) {
            $start = new DateTimeImmutable(substr($event->starts_at, 0, 10));
            $last = (new DateTimeImmutable(substr($event->ends_at, 0, 10)))->modify('-1 day');
            $when = $start->format('d-m-Y') . ($last > $start ? ' – ' . $last->format('d-m-Y') : '');
        } else {
            $utc = new DateTimeZone('UTC');
            $start = (new DateTimeImmutable($event->starts_at, $utc))->setTimezone($zone);
            $end = (new DateTimeImmutable($event->ends_at, $utc))->setTimezone($zone);
            $when = $start->format('d-m-Y H:i') . ' – ' . ($end->format('Y-m-d') === $start->format('Y-m-d') ? $end->format('H:i') : $end->format('d-m-Y H:i'))
                . ' (' . $zone->getName() . ')';
        }
        if ($event->is_recurring) {
            $when .= ', ' . __('repeating', [], $locale);
        }
        $vevent = EventIcs::master(Reader::read($event->ics), $event->uid);
        $location = trim((string) ($vevent->LOCATION ?? ''));
        $html .= '<p><strong>' . e((string) $event->summary) . '</strong><br>' . e(__('When: :when', ['when' => $when], $locale));
        if ($location !== '') {
            $html .= '<br>' . e(__('Where: :location', ['location' => $location], $locale));
        }

        return $html . '</p>';
    }
}
