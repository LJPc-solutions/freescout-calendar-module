<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Google;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\LJPcCalendarModule\CalendarProviders\HtmlText;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\VTimezone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;

/**
 * Google Calendar event resources (API v3, events reference) to and from VEVENTs. A series is its master plus the
 * exceptions Google lists next to it: a modified instance becomes an override with the RECURRENCE-ID of its
 * originalStartTime, a cancelled one an EXDATE. Pure code: the provider does the requests.
 */
final class GoogleEventMapper
{
    /** The recurrence lines Google keeps in an event's `recurrence` list (RFC 5545 properties). */
    private const RECURRENCE = ['RRULE', 'EXRULE', 'RDATE', 'EXDATE'];
    private const STATUSES = ['confirmed', 'tentative', 'cancelled'];
    private const CLASSES = ['public' => 'PUBLIC', 'private' => 'PRIVATE', 'confidential' => 'CONFIDENTIAL'];
    private const PARTSTAT = ['needsAction' => 'NEEDS-ACTION', 'accepted' => 'ACCEPTED', 'declined' => 'DECLINED', 'tentative' => 'TENTATIVE'];
    private const UTC_NAMES = ['UTC', 'Z', '+00:00', 'Etc/UTC', 'GMT', 'Etc/GMT'];
    private const RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/i';

    /**
     * One VCALENDAR for a single event, or a series with its exceptions. Times with a `timeZone` keep that IANA zone as
     * TZID (with a VTIMEZONE); a time that only carries an offset is written in the calendar's zone. An exception whose
     * original start is a date for a timed series, or a time for an all-day one, belongs to an earlier form of the
     * series: it is left out and logged. Throws InvalidArgumentException for an event without a usable UID or start.
     */
    public static function toVCalendar(array $master, array $exceptions, string $calendarTimezone): string
    {
        $vcal = new VCalendar(['PRODID' => EventIcs::PRODID]);
        $vevent = self::vevent($vcal, $master, self::uid($master), $calendarTimezone);
        foreach (self::recurrenceProperties($master) as $property) {
            $vevent->add($property);
        }
        $zone = $vevent->DTSTART->hasTime() ? $vevent->DTSTART->getDateTime()->getTimezone() : new DateTimeZone($calendarTimezone);
        foreach ($exceptions as $exception) {
            $key = self::exceptionKey($exception, $zone->getName());
            if ((strlen($key) === 8) === $vevent->DTSTART->hasTime()) {
                Log::info('An occurrence of a Google series that does not match the series was skipped', ['uid_hash' => sha1((string) $vevent->UID), 'key' => $key]);
                continue;
            }
            if (($exception['status'] ?? null) === 'cancelled') {
                EventIcs::addExdate($vevent, $key, $zone);
                continue;
            }
            $override = self::vevent($vcal, $exception, (string) $vevent->UID, $calendarTimezone);
            self::addRecurrenceId($override, $key, $vevent->DTSTART->hasTime(), $zone);
        }
        VTimezone::addForEvents($vcal);

        return $vcal->serialize();
    }

    /**
     * The overrides of a stored series whose master is not in this answer: one VEVENT per modified instance, no master.
     * Cancelled instances are left out; the caller passes their keys on (RemoteEvent::$cancelledKeys).
     */
    public static function overridesToVCalendar(array $exceptions, string $uid, string $calendarTimezone): string
    {
        $vcal = new VCalendar(['PRODID' => EventIcs::PRODID]);
        foreach ($exceptions as $exception) {
            if (($exception['status'] ?? null) === 'cancelled') {
                continue;
            }
            $original = (array) ($exception['originalStartTime'] ?? []);
            $zone = self::zone($original['timeZone'] ?? null, $calendarTimezone);
            $override = self::vevent($vcal, $exception, $uid, $calendarTimezone);
            self::addRecurrenceId($override, self::exceptionKey($exception, $zone->getName()), !isset($original['date']), $zone);
        }
        VTimezone::addForEvents($vcal);

        return $vcal->serialize();
    }

    /**
     * The occurrence key (EventIcs::recurrenceKey format) of an instance: its originalStartTime as a UTC date-time, or
     * its date for an all-day series. A time without an offset is read in its own timeZone, else in $timezone.
     */
    public static function exceptionKey(array $instance, string $timezone): string
    {
        $original = $instance['originalStartTime'] ?? null;
        if (!is_array($original)) {
            throw new InvalidArgumentException('The instance has no originalStartTime');
        }
        if (isset($original['date'])) {
            return self::date($original['date'])->format('Ymd');
        }

        return self::dateTime($original['dateTime'] ?? null, self::zone($original['timeZone'] ?? null, $timezone))
            ->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * The event resource for events.insert and events.update: iCalUID (masters, for an insert), summary, description,
     * location, start and end, recurrence (masters only; null for a single event), status and visibility. A field the
     * VEVENT lacks is null, so the caller can drop it from what it sends. Attendees and the organizer are never
     * written. A floating time is read in $floatingTimezone; a zone Google doesn't know (an offset) is sent as UTC.
     */
    public static function fromVEvent(VEvent $vevent, bool $isMaster, string $floatingTimezone = 'UTC'): array
    {
        $json = [];
        if ($isMaster) {
            $json['iCalUID'] = (string) $vevent->UID;
        }
        $json['summary'] = (string) ($vevent->SUMMARY ?? '');
        $json['description'] = isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null;
        $json['location'] = isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null;
        $floating = new DateTimeZone($floatingTimezone);
        $zone = null;
        if ($vevent->DTSTART->hasTime()) {
            $start = $vevent->DTSTART->getDateTime($floating);
            $zone = self::googleZone($vevent->DTSTART->isFloating() ? $floating : $start->getTimezone());
            $json['start'] = self::googleDateTime($start, $zone);
            $end = EventIcs::endOf($vevent, $floating);
            $endZone = isset($vevent->DTEND) && !$vevent->DTEND->isFloating() ? self::googleZone($end->getTimezone()) : $zone;
            $json['end'] = self::googleDateTime($end, $endZone);
        } else {
            $start = $vevent->DTSTART->getDateTime($floating);
            $json['start'] = ['date' => $start->format('Y-m-d')];
            $json['end'] = ['date' => max(EventIcs::endOf($vevent, $floating), $start->modify('+1 day'))->format('Y-m-d')];
        }
        if ($isMaster) {
            $json['recurrence'] = self::recurrenceLines($vevent, $zone, $floating) ?: null;
        }
        $status = strtolower((string) ($vevent->STATUS ?? ''));
        $json['status'] = in_array($status, self::STATUSES, true) ? $status : null;
        $class = strtoupper((string) ($vevent->CLASS ?? ''));
        $visibility = array_search($class, self::CLASSES, true);
        $json['visibility'] = $visibility === false ? 'default' : $visibility;

        return $json;
    }

    /**
     * Whether an instance (events.instances) is an exception of its series: cancelled, at other times than the series
     * gives that occurrence, or with another title, description, place, status, visibility or transparency. Both sides
     * are Google's own JSON, so equal fields compare equal; attendees and reminders don't count.
     */
    public static function isException(array $instance, array $master, string $calendarTimezone): bool
    {
        if (($instance['status'] ?? null) === 'cancelled') {
            return true;
        }
        // Google leaves out fields that have their default value.
        $defaults = ['summary' => '', 'description' => '', 'location' => '', 'status' => 'confirmed', 'visibility' => 'default', 'transparency' => 'opaque'];
        foreach ($defaults as $field => $default) {
            if (($instance[$field] ?? $default) !== ($master[$field] ?? $default)) {
                return true;
            }
        }
        $original = (array) ($instance['originalStartTime'] ?? []);
        $start = isset($original['date']) ? ['date' => $original['date']] : $original;
        $expected = self::shifted((array) ($master['start'] ?? []), (array) ($master['end'] ?? []), $start, $calendarTimezone);

        return $expected !== [self::moment((array) ($instance['start'] ?? []), $calendarTimezone), self::moment((array) ($instance['end'] ?? []), $calendarTimezone)];
    }

    /**
     * Whether an instance is still the occurrence FreeScout stored, its override or else the series at that time: the
     * same start, end, title, place and status. A write to an occurrence Google changed since the last sync is a
     * conflict. The description is left out: Google's carries FreeScout's readable block, the stored one doesn't.
     */
    public static function sameOccurrence(array $instance, VCalendar $stored, string $uid, string $key, string $calendarTimezone): bool
    {
        $override = EventIcs::findOverride($stored, $uid, $key, new DateTimeZone($calendarTimezone));
        $json = self::fromVEvent($override ?? EventIcs::master($stored, $uid), false, $calendarTimezone);
        $times = [self::moment($json['start'], $calendarTimezone), self::moment($json['end'], $calendarTimezone)];
        if ($override === null) {
            $at = strlen($key) === 8
                ? ['date' => DateTimeImmutable::createFromFormat('!Ymd', $key, new DateTimeZone('UTC'))->format('Y-m-d')]
                : ['dateTime' => DateTimeImmutable::createFromFormat('Ymd\THis\Z', $key, new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
            $times = self::shifted($json['start'], $json['end'], $at, $calendarTimezone);
        }
        $expected = array_merge($times, [$json['summary'], (string) $json['location'], $json['status'] ?? 'confirmed']);
        $actual = [
            self::moment((array) ($instance['start'] ?? []), $calendarTimezone), self::moment((array) ($instance['end'] ?? []), $calendarTimezone),
            (string) ($instance['summary'] ?? ''), (string) ($instance['location'] ?? ''), (string) ($instance['status'] ?? 'confirmed'),
        ];

        return $expected === $actual;
    }

    /**
     * Google's id of one instance of a series: the series id, an underscore and the occurrence key (the original start
     * in UTC, or the date of an all-day series). UNVERIFIED: Google's reference doesn't document this form; it is the
     * one its API returns. GoogleProvider looks the instance up through events.instances when this id is unknown.
     */
    public static function instanceId(string $masterId, string $occurrenceKey): string
    {
        return $masterId . '_' . $occurrenceKey;
    }

    private static function vevent(VCalendar $vcal, array $item, string $uid, string $calendarTimezone): VEvent
    {
        $vevent = $vcal->add('VEVENT', ['UID' => $uid]);
        $updated = self::instant($item['updated'] ?? null);
        $vevent->DTSTAMP = $updated ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($updated !== null) {
            $vevent->{'LAST-MODIFIED'} = $updated;
        }
        self::addTimes($vevent, $item, $calendarTimezone);
        foreach (['summary' => 'SUMMARY', 'location' => 'LOCATION'] as $field => $name) {
            if (is_string($item[$field] ?? null) && $item[$field] !== '') {
                $vevent->{$name} = $item[$field];
            }
        }
        if (is_string($item['description'] ?? null) && $item['description'] !== '') {
            $vevent->DESCRIPTION = self::plainText($item['description']);
        }
        if (in_array($item['status'] ?? null, self::STATUSES, true)) {
            $vevent->STATUS = strtoupper($item['status']);
        }
        if (isset(self::CLASSES[$item['visibility'] ?? ''])) {
            $vevent->CLASS = self::CLASSES[$item['visibility']];
        }
        if (($item['transparency'] ?? null) === 'transparent') {
            $vevent->TRANSP = 'TRANSPARENT';
        }
        if (is_numeric($item['sequence'] ?? null)) {
            $vevent->SEQUENCE = (int) $item['sequence'];
        }
        $organizer = $item['organizer'] ?? null;
        if (is_array($organizer) && self::isAddress($organizer['email'] ?? null)) {
            $vevent->add('ORGANIZER', 'mailto:' . $organizer['email'], array_filter(['CN' => $organizer['displayName'] ?? null], 'is_string'));
        }
        foreach ((array) ($item['attendees'] ?? []) as $attendee) {
            if (!is_array($attendee) || !self::isAddress($attendee['email'] ?? null)) {
                continue;
            }
            $vevent->add('ATTENDEE', 'mailto:' . $attendee['email'], array_filter([
                'CN'       => $attendee['displayName'] ?? null,
                'CUTYPE'   => !empty($attendee['resource']) ? 'RESOURCE' : null,
                'ROLE'     => !empty($attendee['optional']) ? 'OPT-PARTICIPANT' : 'REQ-PARTICIPANT',
                'PARTSTAT' => self::PARTSTAT[$attendee['responseStatus'] ?? ''] ?? 'NEEDS-ACTION',
            ], 'is_string'));
        }

        return $vevent;
    }

    private static function addTimes(VEvent $vevent, array $item, string $calendarTimezone): void
    {
        $start = $item['start'] ?? null;
        $end = $item['end'] ?? null;
        if (!is_array($start)) {
            throw new InvalidArgumentException('The event has no start');
        }
        if (isset($start['date'])) {
            $from = self::date($start['date']);
            $until = is_array($end) && isset($end['date']) ? self::date($end['date']) : $from;
            // Google's end date is exclusive, as DTEND's is.
            $vevent->add('DTSTART', $from->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', max($until, $from->modify('+1 day'))->format('Ymd'), ['VALUE' => 'DATE']);

            return;
        }
        $startZone = self::zone($start['timeZone'] ?? null, $calendarTimezone);
        $vevent->add('DTSTART', self::dateTime($start['dateTime'] ?? null, $startZone)->setTimezone($startZone));
        if (is_array($end) && isset($end['dateTime'])) {
            $endZone = self::zone($end['timeZone'] ?? null, $startZone->getName());
            $vevent->add('DTEND', self::dateTime($end['dateTime'], $endZone)->setTimezone($endZone));
        }
    }

    /**
     * The start and end of the occurrence that starts at $at, with the length of the series ($start to $end), each as
     * moment(). An all-day series keeps its number of days. A timed one adds its length in seconds of wall-clock time
     * in the series' zone, as sabre/vobject's expansion does (EventIterator::getDtEnd()), so a night that spans a DST
     * change keeps its end time there too.
     */
    private static function shifted(array $start, array $end, array $at, string $calendarTimezone): array
    {
        if (isset($start['date'])) {
            $days = (int) self::date($start['date'])->diff(self::date($end['date'] ?? $start['date']))->days;
            $from = self::date($at['date'] ?? null);

            return ['date:' . $from->format('Y-m-d'), 'date:' . $from->modify('+' . $days . ' days')->format('Y-m-d')];
        }
        $zone = self::zone($start['timeZone'] ?? null, $calendarTimezone);
        $length = self::timestamp($end, $calendarTimezone) - self::timestamp($start, $calendarTimezone);
        $from = self::dateTime($at['dateTime'] ?? null, self::zone($at['timeZone'] ?? null, $calendarTimezone))->setTimezone($zone);

        return ['time:' . $from->getTimestamp(), 'time:' . $from->modify('+' . $length . ' seconds')->getTimestamp()];
    }

    /** A Google start or end as a comparable value: its date, or its moment in seconds. */
    private static function moment(array $time, string $calendarTimezone): string
    {
        return isset($time['date']) ? 'date:' . self::date($time['date'])->format('Y-m-d') : 'time:' . self::timestamp($time, $calendarTimezone);
    }

    private static function timestamp(array $time, string $calendarTimezone): int
    {
        return self::dateTime($time['dateTime'] ?? null, self::zone($time['timeZone'] ?? null, $calendarTimezone))->getTimestamp();
    }

    /** A RECURRENCE-ID in the series' own form: a date for an all-day series, else the original start in $zone. */
    private static function addRecurrenceId(VEvent $override, string $key, bool $timed, DateTimeZone $zone): void
    {
        $utc = new DateTimeZone('UTC');
        $moment = strlen($key) === 8
            ? DateTimeImmutable::createFromFormat('!Ymd', $key, $zone)
            : DateTimeImmutable::createFromFormat('Ymd\THis\Z', $key, $utc);
        if ($timed) {
            $override->add('RECURRENCE-ID', $moment->setTimezone($zone));
        } else {
            $override->add('RECURRENCE-ID', $moment->setTimezone($zone)->format('Ymd'), ['VALUE' => 'DATE']);
        }
    }

    /**
     * The event's RRULE, EXRULE, RDATE and EXDATE lines, each read as an iCalendar property. A line with a line break
     * or another property is skipped: it could add components of its own.
     *
     * @return \Sabre\VObject\Property[]
     */
    private static function recurrenceProperties(array $item): array
    {
        $properties = [];
        foreach ((array) ($item['recurrence'] ?? []) as $line) {
            if (!is_string($line) || preg_match('/[\r\n]/', $line) || !preg_match('/^(RRULE|EXRULE|RDATE|EXDATE)[;:]/i', $line)) {
                continue;
            }
            $parsed = Reader::read("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\n" . $line . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", Reader::OPTION_FORGIVING);
            foreach ($parsed->VEVENT->children() as $property) {
                if (in_array($property->name, self::RECURRENCE, true)) {
                    $properties[] = clone $property;
                }
            }
        }

        return $properties;
    }

    /**
     * Google's recurrence lines for a master: rules as they are (a floating UNTIL in UTC), and every RDATE and EXDATE
     * value on a line of its own, in the zone the start is sent in.
     */
    private static function recurrenceLines(VEvent $vevent, ?string $zone, DateTimeZone $floating): array
    {
        $lines = [];
        foreach (['RRULE', 'EXRULE'] as $name) {
            foreach ($vevent->select($name) as $rule) {
                $value = (string) $rule->getValue();
                if ($zone !== null && $vevent->DTSTART->isFloating() && preg_match('/UNTIL=(\d{8}T\d{6})(?!Z)/', $value, $match)) {
                    $until = DateTimeImmutable::createFromFormat('Ymd\THis', $match[1], $floating)->setTimezone(new DateTimeZone('UTC'));
                    $value = str_replace('UNTIL=' . $match[1], 'UNTIL=' . $until->format('Ymd\THis\Z'), $value);
                }
                $lines[] = $name . ':' . $value;
            }
        }
        foreach (['RDATE', 'EXDATE'] as $name) {
            foreach ($vevent->select($name) as $property) {
                if (!$property instanceof DateTimeProperty) {
                    continue; // an RDATE period: Google's events carry none
                }
                foreach ($property->getDateTimes($floating) as $dateTime) {
                    if (!$property->hasTime()) {
                        $lines[] = $name . ';VALUE=DATE:' . $dateTime->format('Ymd');
                    } elseif ($zone === null || $zone === 'UTC') {
                        $lines[] = $name . ':' . $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
                    } else {
                        $lines[] = $name . ';TZID=' . $zone . ':' . $dateTime->setTimezone(new DateTimeZone($zone))->format('Ymd\THis');
                    }
                }
            }
        }

        return $lines;
    }

    /** Google's descriptions may be HTML (events reference); it becomes plain text with its line breaks and link targets. */
    public static function plainText(string $text): string
    {
        if (!preg_match('~<(br|p|div|span|b|i|u|strong|em|a|ul|ol|li|h[1-6])\b[^>]*>~i', $text)) {
            return $text;
        }

        return HtmlText::toPlain($text);
    }

    private static function uid(array $item): string
    {
        foreach (['iCalUID', 'id'] as $field) {
            if (is_string($item[$field] ?? null) && trim($item[$field]) !== '' && !preg_match('/[\r\n]/', $item[$field])) {
                return $item[$field];
            }
        }

        throw new InvalidArgumentException('The event has no iCalUID');
    }

    /** The zone a Google time is written in: its named timeZone, else $fallback (the calendar's zone). */
    private static function zone($name, string $fallback): DateTimeZone
    {
        if (is_string($name) && VTimezone::isNamedZone($name)) {
            return new DateTimeZone($name);
        }

        return new DateTimeZone($fallback);
    }

    /** The zone Google gets for a start or end: a name PHP lists, or UTC for anything else (an offset). */
    private static function googleZone(DateTimeZone $zone): string
    {
        $name = $zone->getName();
        if (in_array($name, self::UTC_NAMES, true)) {
            return 'UTC';
        }

        return VTimezone::isNamedZone($name) ? $name : 'UTC';
    }

    private static function googleDateTime(DateTimeImmutable $moment, string $zone): array
    {
        return ['dateTime' => $moment->setTimezone(new DateTimeZone($zone))->format('Y-m-d\TH:i:sP'), 'timeZone' => $zone];
    }

    /** An RFC 3339 date-time; without an offset it is read in $zone. */
    private static function dateTime($value, DateTimeZone $zone): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match(self::RFC3339, $value)) {
            throw new InvalidArgumentException('Not an RFC 3339 date-time');
        }

        return new DateTimeImmutable($value, $zone);
    }

    private static function date($value): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Not a date');
        }

        return $date;
    }

    /** A UTC time such as `updated`; null when the event has none. */
    private static function instant($value): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return is_string($value) && preg_match(self::RFC3339, $value) ? (new DateTimeImmutable($value, $utc))->setTimezone($utc) : null;
    }

    private static function isAddress($email): bool
    {
        return is_string($email) && $email !== '' && !preg_match('/[\s"]/', $email);
    }
}
