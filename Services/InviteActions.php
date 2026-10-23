<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Attachment;
use App\Conversation;
use App\User;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ITip\Broker;
use Sabre\VObject\Reader;

/**
 * What an agent's click on an invite card does (spec section 13; decisions of 2026-10-19): a new invite is added to
 * the chosen calendar, an update replaces the event or one occurrence, a cancellation keeps the event struck through.
 * Everything is written through EventService, so the provider gets it first. The caller checks the agent's rights.
 */
class InviteActions
{
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_DECLINED = 'declined';

    /** Our answers (the card's buttons) and the PARTSTAT each one sends. */
    public const ANSWERS = ['accepted' => 'ACCEPTED', 'tentative' => 'TENTATIVE', 'declined' => 'DECLINED'];

    private InviteService $invites;

    private EventService $events;

    public function __construct(InviteService $invites, EventService $events)
    {
        $this->invites = $invites;
        $this->events = $events;
    }

    /** The invite's card as it is now (InviteService::classify), read again from its file. */
    public function card(CalendarInvite $invite, User $user): array
    {
        return $this->invites->classify($invite, $this->message($invite), $user);
    }

    public function apply(CalendarInvite $invite, User $user, ?Calendar $calendar): CalendarEvent
    {
        $card = $this->card($invite, $user);
        $message = $card['message'];
        $conversation = Conversation::find($invite->conversation_id);
        switch ($card['state']) {
            case InviteService::STATE_NEW:
                if ($message->recurrenceId !== null) {
                    throw ValidationException::withMessages(['invite' => __('This invite changes an event that is in no calendar yet.')]);
                }
                if ($calendar === null) {
                    throw ValidationException::withMessages(['calendar_id' => __('Choose a calendar.')]);
                }
                $event = $this->events->createFromIcs($calendar, $user, Reader::read($message->ics), $message->uid, $conversation);
                $action = 'added';
                break;
            case InviteService::STATE_UPDATE:
                $event = $this->update($card['event'], $message);
                $action = 'updated';
                break;
            case InviteService::STATE_CANCELLATION:
                $event = $this->mark($card['event'], $message->recurrenceId, self::STATE_CANCELLED);
                $action = 'cancelled';
                break;
            case InviteService::STATE_CURRENT:
                throw ValidationException::withMessages(['invite' => $message->method === 'CANCEL'
                    ? __('This cancellation is applied already.')
                    : __('This invite is already in a calendar.')]);
            case InviteService::STATE_OUTDATED:
                throw ValidationException::withMessages(['invite' => __('A newer version of this invite is in the calendar already.')]);
            default:
                throw ValidationException::withMessages(['invite' => __('There is nothing to apply for this invite.')]);
        }
        $invite->update(['status' => CalendarInvite::STATUS_APPLIED, 'event_id' => $event->id, 'applied_by' => $user->id, 'applied_at' => gmdate('Y-m-d H:i:s')]);
        if ($conversation) {
            InviteLineItem::add($conversation, $user, $action, $event, ['title' => $message->summary]);
        }

        return $event;
    }

    /**
     * Yes, Maybe or No to the organizer, for the whole event (spec section 13). Yes and Maybe first add or update the
     * event as the card offers; No keeps an event that is in a calendar, declined. The answer is a reply in the
     * conversation to the organizer, with the REPLY that sabre/vobject builds.
     */
    public function answer(CalendarInvite $invite, User $user, string $response, ?Calendar $calendar): ?CalendarEvent
    {
        $card = $this->card($invite, $user);
        $message = $card['message'];
        $conversation = Conversation::find($invite->conversation_id);
        $address = $conversation ? InviteService::ourAddress($message, $conversation->mailbox) : null;
        if (!isset(self::ANSWERS[$response]) || $address === null || $message->method !== 'REQUEST' || $message->recurrenceId !== null
            || !filter_var((string) $message->organizerEmail, FILTER_VALIDATE_EMAIL)
            || !in_array($card['state'], [InviteService::STATE_NEW, InviteService::STATE_UPDATE, InviteService::STATE_CURRENT], true)) {
            throw ValidationException::withMessages(['invite' => __('This invite can\'t be answered.')]);
        }
        $partstat = self::ANSWERS[$response];
        $ics = self::reply($message, $address, $partstat);
        $event = $card['event'];
        if ($partstat !== 'DECLINED' && $card['state'] !== InviteService::STATE_CURRENT) {
            $event = $this->apply($invite, $user, $calendar);
        }
        if ($event !== null) {
            $event = $this->record($event, $address, $partstat);
        }

        // Customer-facing, so in the install's language rather than the agent's.
        $locale = config('app.locale');
        $mailbox = $conversation->mailbox;
        $words = ['name' => $mailbox->name, 'title' => $message->summary];
        switch ($partstat) {
            case 'ACCEPTED':
                $subject = __('Accepted: :title', $words, $locale);
                $text = __(':name accepted the invite ":title".', $words, $locale);
                break;
            case 'TENTATIVE':
                $subject = __('Tentative: :title', $words, $locale);
                $text = __(':name might attend ":title".', $words, $locale);
                break;
            default:
                $subject = __('Declined: :title', $words, $locale);
                $text = __(':name declined the invite ":title".', $words, $locale);
        }
        $from = null;
        foreach (array_keys($mailbox->getAliases(false)) as $alias) {
            if (strtolower($alias) === $address) {
                $from = $alias; // the organizer invited this alias, so the answer comes from it
            }
        }
        ItipMail::send($conversation, $user, [$message->organizerEmail => (string) $message->organizerName], $from, $subject, '<p>' . e($text) . '</p>', 'REPLY', $ics);
        $invite->update(['response' => $response]);
        InviteLineItem::add($conversation, $user, 'answered', $event, ['title' => $message->summary, 'answer' => $response]);

        return $event;
    }

    /**
     * Cancelled or declined: FreeScout and CalDAV calendars use STATUS:CANCELLED, which clients strike through. Google's
     * cancelled status deletes an event and Microsoft has none, so there the title says it and the time is free.
     */
    public function mark(CalendarEvent $event, ?string $occurrenceKey, string $state): CalendarEvent
    {
        $vcal = Reader::read($event->ics);
        $tz = new DateTimeZone($event->timezone ?: $event->calendar->timezoneName());
        $master = EventIcs::master($vcal, $event->uid);
        $components = $occurrenceKey === null
            ? self::components($vcal, $event->uid)
            : [EventIcs::findOverride($vcal, $event->uid, $occurrenceKey, $tz) ?? EventIcs::addOverride($vcal, $master, $occurrenceKey, $tz)];
        $inTitle = self::inTitle($event);
        foreach ($components as $vevent) {
            if ($inTitle) {
                $vevent->SUMMARY = self::prefixed((string) ($vevent->SUMMARY ?? ''), $state);
                $vevent->TRANSP = 'TRANSPARENT';
            } elseif ($state === self::STATE_CANCELLED) {
                $vevent->STATUS = 'CANCELLED';
            }
        }

        return $this->events->replaceIcs($event, $vcal, $occurrenceKey, $occurrenceKey === null ? ['invite_state' => $state] : []);
    }

    /**
     * Our answer in the stored event: our attendee's PARTSTAT where attendees are kept, on Google and Microsoft (no
     * attendees) the declined title, which a later Yes or Maybe takes away again.
     */
    private function record(CalendarEvent $event, string $address, string $partstat): CalendarEvent
    {
        $declined = $partstat === 'DECLINED';
        if (self::inTitle($event)) {
            if ($declined) {
                return $this->mark($event, null, self::STATE_DECLINED);
            }
            if ($event->invite_state !== self::STATE_DECLINED) {
                return $event;
            }
        }
        $vcal = Reader::read($event->ics);
        $prefix = __('Declined: :title', ['title' => ''], config('app.locale'));
        foreach (self::components($vcal, $event->uid) as $vevent) {
            foreach ($vevent->select('ATTENDEE') as $attendee) {
                if (EventIcs::email((string) $attendee) === $address) {
                    $attendee['PARTSTAT'] = $partstat;
                }
            }
            $title = (string) ($vevent->SUMMARY ?? '');
            if (self::inTitle($event) && $prefix !== '' && strpos($title, $prefix) === 0) {
                $vevent->SUMMARY = substr($title, strlen($prefix));
                $vevent->TRANSP = 'OPAQUE';
            }
        }

        return $this->events->replaceIcs($event, $vcal, null, ['invite_state' => $declined ? self::STATE_DECLINED : null]);
    }

    /** The REPLY for our answer: our attendee's PARTSTAT changes, so sabre's broker writes the answer to the organizer. */
    private static function reply(InviteMessage $message, string $address, string $partstat): string
    {
        $calendars = [];
        foreach (['NEEDS-ACTION', $partstat] as $status) {
            $vcal = Reader::read($message->ics);
            foreach ($vcal->select('VEVENT') as $vevent) {
                foreach ($vevent->select('ATTENDEE') as $attendee) {
                    if (EventIcs::email((string) $attendee) === $address) {
                        $attendee->setValue('mailto:' . $address); // the broker compares addresses as written
                        $attendee['PARTSTAT'] = $status;
                    }
                }
            }
            $calendars[] = $vcal;
        }
        $broker = new Broker();
        $broker->scheduleAgentServerRules = false; // SCHEDULE-AGENT=CLIENT: we are the client that sends
        foreach ($broker->parseEvent($calendars[1], 'mailto:' . $address, $calendars[0]) as $itip) {
            if ($itip->method === 'REPLY') {
                $itip->message->PRODID = EventIcs::PRODID;

                return $itip->message->serialize();
            }
        }
        // The broker answers nothing for a cancelled event.
        throw ValidationException::withMessages(['invite' => __('This invite can\'t be answered.')]);
    }

    private static function inTitle(CalendarEvent $event): bool
    {
        return in_array($event->calendar->provider, [Calendar::PROVIDER_GOOGLE, Calendar::PROVIDER_MICROSOFT], true);
    }

    /** The organizer's new version: the whole event, or the one occurrence the invite names. */
    private function update(CalendarEvent $event, InviteMessage $message): CalendarEvent
    {
        $stored = Reader::read($event->ics);
        $incoming = Reader::read($message->ics);
        foreach ($incoming->select('VTIMEZONE') as $zone) {
            if (!self::hasZone($stored, (string) $zone->TZID)) {
                $stored->add(clone $zone);
            }
        }
        if ($message->thisAndFuture) {
            // Spec section 13: applied to the series, which then starts at that occurrence with its new details.
            $master = EventIcs::master($stored, $event->uid);
            $change = $incoming->select('VEVENT')[0];
            foreach (['DTSTART', 'DTEND', 'DURATION', 'SUMMARY', 'LOCATION', 'DESCRIPTION', 'SEQUENCE', 'DTSTAMP', 'STATUS'] as $property) {
                if (isset($change->{$property})) {
                    unset($master->{$property});
                    $master->add(clone $change->{$property});
                }
            }
            EventIcs::dropExceptions($stored, $master);

            return $this->events->replaceIcs($event, $stored, null);
        }
        if ($message->recurrenceId === null) {
            foreach (self::components($stored, $event->uid) as $vevent) {
                $stored->remove($vevent);
            }
        } else {
            $tz = new DateTimeZone($event->timezone ?: $event->calendar->timezoneName());
            EventIcs::removeOverride($stored, $event->uid, $message->recurrenceId, $tz);
        }
        foreach ($incoming->select('VEVENT') as $vevent) {
            $copy = clone $vevent;
            $copy->UID = $event->uid;
            $stored->add($copy);
        }

        return $this->events->replaceIcs($event, $stored, $message->recurrenceId);
    }

    private function message(CalendarInvite $invite): InviteMessage
    {
        $attachment = Attachment::find($invite->attachment_id);
        $message = $attachment ? $this->invites->messageOf($attachment, $invite) : null;
        if ($message === null) {
            throw ValidationException::withMessages(['invite' => __('The invite file can no longer be read.')]);
        }

        return $message;
    }

    /** @return VEvent[] */
    private static function components(VCalendar $vcal, string $uid): array
    {
        return array_values(array_filter($vcal->select('VEVENT'), function (VEvent $vevent) use ($uid) {
            return (string) $vevent->UID === $uid;
        }));
    }

    private static function hasZone(VCalendar $vcal, string $tzid): bool
    {
        foreach ($vcal->select('VTIMEZONE') as $zone) {
            if ((string) $zone->TZID === $tzid) {
                return true;
            }
        }

        return false;
    }

    private static function prefixed(string $title, string $state): string
    {
        // The calendar is shared, so in the install's language rather than the agent's.
        $locale = config('app.locale');
        $prefixed = $state === self::STATE_CANCELLED ? __('Cancelled: :title', ['title' => $title], $locale) : __('Declined: :title', ['title' => $title], $locale);
        $prefix = substr($prefixed, 0, strlen($prefixed) - strlen($title));

        return strpos($title, $prefix) === 0 ? $title : $prefixed;
    }
}
