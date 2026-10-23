<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

/**
 * Reads an invite file (an attachment named *.ics or of type text/calendar) into iTIP messages, without applying
 * anything. FreeScout drops the MIME "method=" parameter when it stores the attachment, so the METHOD inside the file
 * decides; a file without one is a PUBLISH (RFC 5546). A file holds one message per UID: the whole event when it has
 * the series' main component, otherwise one per occurrence it lists (an update or cancellation of single occurrences).
 */
final class InviteParser
{
    /** @return InviteMessage[] */
    public function parse(string $ics): array
    {
        try {
            $vcal = Reader::read($ics, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (ParseException $e) {
            Log::info('An attachment that looks like a calendar invite is not iCalendar', ['error' => $e->getMessage()]);

            return [];
        }
        if (!$vcal instanceof VCalendar) {
            return [];
        }
        $method = strtoupper(trim((string) ($vcal->METHOD ?? ''))) ?: 'PUBLISH';
        $byUid = [];
        foreach ($vcal->select('VEVENT') as $vevent) {
            $uid = trim((string) ($vevent->UID ?? ''));
            if ($uid !== '' && isset($vevent->DTSTART)) {
                $byUid[$uid][] = $vevent;
            }
        }
        $messages = [];
        foreach ($byUid as $uid => $components) {
            $masters = array_values(array_filter($components, function (VEvent $vevent) {
                return !isset($vevent->{'RECURRENCE-ID'});
            }));
            if ($masters) {
                $messages[] = self::message($vcal, $method, (string) $uid, $masters[0], $components);
                continue;
            }
            foreach ($components as $occurrence) {
                $messages[] = self::message($vcal, $method, (string) $uid, $occurrence, [$occurrence]);
            }
        }

        return $messages;
    }

    /** @param VEvent[] $components */
    private static function message(VCalendar $source, string $method, string $uid, VEvent $vevent, array $components): InviteMessage
    {
        $utc = new DateTimeZone('UTC');
        $recurrence = $vevent->{'RECURRENCE-ID'} ?? null;
        $organizer = $vevent->ORGANIZER ?? null;
        $organizerEmail = $organizer ? EventIcs::email((string) $organizer) : null;
        $alias = $organizer && isset($organizer['EMAIL']) ? EventIcs::email((string) $organizer['EMAIL']) : null;
        $attendees = [];
        foreach ($vevent->select('ATTENDEE') as $attendee) {
            $email = EventIcs::email((string) $attendee);
            if ($email === null) {
                continue;
            }
            $attendees[] = [
                'email'    => $email,
                'name'     => isset($attendee['CN']) ? (string) $attendee['CN'] : $email,
                'partstat' => isset($attendee['PARTSTAT']) ? strtoupper((string) $attendee['PARTSTAT']) : 'NEEDS-ACTION',
                // Spec section 13: an answer is offered unless the invite says RSVP=FALSE.
                'rsvp'     => !isset($attendee['RSVP']) || strtoupper((string) $attendee['RSVP']) !== 'FALSE',
            ];
        }

        return new InviteMessage(
            $method,
            $uid,
            $recurrence ? EventIcs::recurrenceKey($recurrence, $utc) : null,
            $recurrence && isset($recurrence['RANGE']) && strtoupper((string) $recurrence['RANGE']) === 'THISANDFUTURE',
            isset($vevent->SEQUENCE) ? (int) (string) $vevent->SEQUENCE : 0,
            isset($vevent->DTSTAMP) ? $vevent->DTSTAMP->getDateTime()->setTimezone($utc)->format('Y-m-d H:i:s') : null,
            $organizerEmail,
            $organizer && isset($organizer['CN']) ? (string) $organizer['CN'] : null,
            $alias !== $organizerEmail ? $alias : null,
            $attendees,
            (string) ($vevent->SUMMARY ?? ''),
            (string) ($vevent->LOCATION ?? ''),
            !$vevent->DTSTART->hasTime(),
            $vevent->DTSTART->getDateTime($utc),
            EventIcs::endOf($vevent, $utc),
            isset($vevent->RRULE) ? (string) $vevent->RRULE : null,
            self::isolate($source, $method, $components)
        );
    }

    /** @param VEvent[] $components */
    private static function isolate(VCalendar $source, string $method, array $components): string
    {
        $vcal = new VCalendar(['PRODID' => (string) ($source->PRODID ?? EventIcs::PRODID), 'METHOD' => $method]);
        foreach ($source->select('VTIMEZONE') as $zone) {
            $vcal->add(clone $zone);
        }
        foreach ($components as $vevent) {
            $vcal->add(clone $vevent);
        }

        return $vcal->serialize();
    }
}
