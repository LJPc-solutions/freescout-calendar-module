<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeImmutable;

/**
 * One iTIP message from an invite file (InviteParser): a whole event, or one occurrence of a series when the file holds
 * only that occurrence. Addresses are lowercased.
 */
final class InviteMessage
{
    /**
     * @param array<int, array{email: string, name: string, partstat: string, rsvp: bool}> $attendees rsvp is false only for RSVP=FALSE
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uid,
        /** EventIcs::recurrenceKey() of the occurrence, null for the whole event. */
        public readonly ?string $recurrenceId,
        /** RECURRENCE-ID;RANGE=THISANDFUTURE: the change applies from that occurrence on. */
        public readonly bool $thisAndFuture,
        public readonly int $sequence,
        /** UTC, Y-m-d H:i:s. */
        public readonly ?string $dtstamp,
        /** The address an answer goes to (ORGANIZER's mailto, an iCloud relay address included). */
        public readonly ?string $organizerEmail,
        public readonly ?string $organizerName,
        /** ORGANIZER's EMAIL parameter, the person's real address behind a relay; null when absent or the same. */
        public readonly ?string $organizerAlias,
        public readonly array $attendees,
        public readonly string $summary,
        public readonly string $location,
        public readonly bool $allDay,
        /** In the event's own zone; a date at 00:00 for an all-day event. */
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly ?string $rrule,
        /** A VCALENDAR with METHOD, the zones and only this message's components. */
        public readonly string $ics
    ) {
    }
}
