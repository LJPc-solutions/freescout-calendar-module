<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

/** Builds, reads and changes VCALENDAR text, the source of truth for every event. */
final class EventIcs
{
    public const PRODID = '-//LJPc//FreeScout Calendar 3//EN';
    private const TZID_PROPERTIES = ['DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE'];

    public static function build(array $data): VCalendar
    {
        $vcal = new VCalendar(['PRODID' => self::PRODID]);
        $vevent = $vcal->add('VEVENT', ['UID' => $data['uid']]);
        $vevent->SEQUENCE = (int) ($data['sequence'] ?? 0);
        self::apply($vevent, $data);

        return $vcal;
    }

    public static function apply(VEvent $vevent, array $data): void
    {
        $vevent->DTSTAMP = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if (array_key_exists('title', $data)) {
            $vevent->SUMMARY = (string) $data['title'];
        }
        foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION'] as $key => $property) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            unset($vevent->{$property});
            if ((string) $data[$key] !== '') {
                $vevent->{$property} = (string) $data[$key];
            }
        }
        if (array_key_exists('start', $data)) {
            if (!array_key_exists('all_day', $data) || !array_key_exists('end', $data)) {
                throw new InvalidArgumentException('apply() needs all_day and end together with start');
            }
            unset($vevent->DTSTART, $vevent->DTEND, $vevent->DURATION);
            if ($data['all_day']) {
                $vevent->add('DTSTART', str_replace('-', '', $data['start']), ['VALUE' => 'DATE']);
                $vevent->add('DTEND', str_replace('-', '', $data['end']), ['VALUE' => 'DATE']);
            } elseif (!empty($data['floating'])) {
                self::addDateTime($vevent, 'DTSTART', $data['start'], true);
                self::addDateTime($vevent, 'DTEND', $data['end'], true);
            } else {
                $vevent->add('DTSTART', $data['start']);
                $vevent->add('DTEND', $data['end']);
            }
        }
        if (array_key_exists('rrule', $data)) {
            unset($vevent->RRULE);
            if ($data['rrule']) {
                $vevent->RRULE = $data['rrule'];
            }
        }
        // The people we invite (InviteSender): the mailbox organizes, each attendee with the answer stored for them.
        if (array_key_exists('attendees', $data)) {
            unset($vevent->ORGANIZER, $vevent->ATTENDEE);
            if ($data['attendees']) {
                $vevent->add('ORGANIZER', 'mailto:' . $data['organizer']['email'], array_filter(['CN' => $data['organizer']['name']]));
                foreach ($data['attendees'] as $attendee) {
                    $vevent->add('ATTENDEE', 'mailto:' . $attendee['email'], array_filter([
                        'CN'       => $attendee['name'],
                        'ROLE'     => 'REQ-PARTICIPANT',
                        'PARTSTAT' => $attendee['partstat'],
                        'RSVP'     => 'TRUE',
                    ]));
                }
            }
        }
    }

    public static function master(VCalendar $vcal, string $uid): VEvent
    {
        $first = null;
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ((string) $vevent->UID !== $uid) {
                continue;
            }
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $vevent;
            }
            $first = $first ?? $vevent;
        }
        if ($first === null) {
            throw new InvalidArgumentException('No VEVENT with UID ' . $uid);
        }

        return $first;
    }

    public static function read(VEvent $vevent, DateTimeZone $tz): array
    {
        $allDay = !$vevent->DTSTART->hasTime();
        $start = $vevent->DTSTART->getDateTime($tz);
        $end = self::endOf($vevent, $tz);

        return [
            'title'       => (string) ($vevent->SUMMARY ?? ''),
            'location'    => (string) ($vevent->LOCATION ?? ''),
            'description' => (string) ($vevent->DESCRIPTION ?? ''),
            'all_day'     => $allDay,
            'start'       => $allDay ? $start->format('Y-m-d') : $start,
            'end'         => $allDay ? $end->format('Y-m-d') : $end,
            'rrule'       => isset($vevent->RRULE) ? (string) $vevent->RRULE : null,
        ];
    }

    public static function endOf(VEvent $vevent, DateTimeZone $tz): DateTimeImmutable
    {
        if (isset($vevent->DTEND)) {
            return $vevent->DTEND->getDateTime($tz);
        }
        $start = $vevent->DTSTART->getDateTime($tz);
        if (isset($vevent->DURATION)) {
            return $start->add(DateTimeParser::parseDuration((string) $vevent->DURATION));
        }

        return $vevent->DTSTART->hasTime() ? $start : $start->modify('+1 day');
    }

    public static function derive(VCalendar $vcal, string $uid, string $fallbackTimezone): array
    {
        $master = self::master($vcal, $uid);
        self::warnUnknownTimezones($vcal, $uid);
        $tz = new DateTimeZone($fallbackTimezone);
        $utc = new DateTimeZone('UTC');
        $allDay = !$master->DTSTART->hasTime();
        $start = $master->DTSTART->getDateTime($tz);
        $end = max($start, self::endOf($master, $tz));
        $class = strtoupper((string) ($master->CLASS ?? 'PUBLIC'));

        return [
            'summary'         => mb_substr((string) ($master->SUMMARY ?? ''), 0, 500),
            'all_day'         => $allDay,
            'timezone'        => $allDay ? null : $start->getTimezone()->getName(),
            'starts_at'       => $allDay ? $start->format('Y-m-d 00:00:00') : $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at'         => $allDay ? $end->format('Y-m-d 00:00:00') : $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            'is_recurring'    => isset($master->RRULE) || isset($master->RDATE),
            'status'          => strtolower((string) ($master->STATUS ?? 'CONFIRMED')),
            'is_private'      => in_array($class, ['PRIVATE', 'CONFIDENTIAL'], true),
            'sequence'        => (int) (string) ($master->SEQUENCE ?? 0),
            'organizer_email' => isset($master->ORGANIZER) ? self::email((string) $master->ORGANIZER) : null,
        ];
    }

    /**
     * The zone a series' rule is read in (the day its UNTIL falls on): the zone of a start with a TZID or in UTC, the
     * calendar's zone for a floating or all-day start. The details show the rule in it and EventService compares a
     * resent rule in it, so a rule another app wrote round-trips unchanged.
     */
    public static function ruleZone(VEvent $master, string $calendarTimezone): DateTimeZone
    {
        if ($master->DTSTART->hasTime() && !$master->DTSTART->isFloating()) {
            return $master->DTSTART->getDateTime()->getTimezone();
        }

        return new DateTimeZone($calendarTimezone);
    }

    public static function email(string $calAddress): ?string
    {
        $email = preg_replace('/^mailto:/i', '', trim($calAddress));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
    }

    public static function recurrenceKey(DateTimeProperty $property, DateTimeZone $tz): string
    {
        $dateTime = $property->getDateTime($tz);

        return $property->hasTime()
            ? $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')
            : $dateTime->format('Ymd');
    }

    public static function findOverride(VCalendar $vcal, string $uid, string $key, DateTimeZone $tz): ?VEvent
    {
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ((string) $vevent->UID === $uid && isset($vevent->{'RECURRENCE-ID'})
                && self::recurrenceKey($vevent->{'RECURRENCE-ID'}, $tz) === $key) {
                return $vevent;
            }
        }

        return null;
    }

    /** Adds an override for one occurrence, starting as a copy of the master at that occurrence's time. */
    public static function addOverride(VCalendar $vcal, VEvent $master, string $key, DateTimeZone $tz): VEvent
    {
        $override = clone $master;
        foreach (['RRULE', 'RDATE', 'EXDATE', 'EXRULE', 'RECURRENCE-ID', 'DTSTART', 'DTEND', 'DURATION'] as $name) {
            unset($override->{$name});
        }
        [$value, $params, $floating] = self::keyValue($master, $key, $tz);
        $masterStart = $master->DTSTART->getDateTime($tz);
        $masterEnd = self::endOf($master, $tz);

        if ($master->DTSTART->hasTime()) {
            $seconds = $masterEnd->getTimestamp() - $masterStart->getTimestamp();
            self::addDateTime($override, 'DTSTART', $value, $floating);
            self::addDateTime($override, 'DTEND', $value->modify('+' . $seconds . ' seconds'), $floating);
        } else {
            $days = (int) $masterStart->diff($masterEnd)->days;
            $start = DateTimeImmutable::createFromFormat('!Ymd', $value, $tz);
            $override->add('DTSTART', $value, ['VALUE' => 'DATE']);
            $override->add('DTEND', $start->modify('+' . max(1, $days) . ' days')->format('Ymd'), ['VALUE' => 'DATE']);
        }
        if ($value instanceof DateTimeImmutable) {
            self::addDateTime($override, 'RECURRENCE-ID', $value, $floating);
        } else {
            $override->add('RECURRENCE-ID', $value, $params);
        }
        $vcal->add($override);

        return $override;
    }

    public static function removeOverride(VCalendar $vcal, string $uid, string $key, DateTimeZone $tz): void
    {
        $override = self::findOverride($vcal, $uid, $key, $tz);
        if ($override !== null) {
            $vcal->remove($override);
        }
    }

    public static function addExdate(VEvent $master, string $key, DateTimeZone $tz): void
    {
        foreach ($master->select('EXDATE') as $exdate) {
            foreach ($exdate->getDateTimes($tz) as $existing) {
                $existingKey = $exdate->hasTime()
                    ? $existing->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')
                    : $existing->format('Ymd');
                if ($existingKey === $key) {
                    return;
                }
            }
        }
        [$value, $params, $floating] = self::keyValue($master, $key, $tz);
        if ($value instanceof DateTimeImmutable) {
            self::addDateTime($master, 'EXDATE', $value, $floating);
        } else {
            $master->add('EXDATE', $value, $params);
        }
    }

    /** The occurrence keys of a master's EXDATEs. */
    public static function exdateKeys(VEvent $master, DateTimeZone $tz): array
    {
        $keys = [];
        foreach ($master->select('EXDATE') as $exdate) {
            foreach ($exdate->getDateTimes($tz) as $dateTime) {
                $keys[] = $exdate->hasTime() ? $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') : $dateTime->format('Ymd');
            }
        }

        return $keys;
    }

    /** Whether dropExceptions() would remove anything: an EXDATE on the master, or an override. */
    public static function hasExceptions(VCalendar $vcal, VEvent $master): bool
    {
        return isset($master->EXDATE) || self::overrides($vcal, $master) !== [];
    }

    /** Removes all overrides and EXDATEs; used when a series' start or rule changes. */
    public static function dropExceptions(VCalendar $vcal, VEvent $master): void
    {
        unset($master->EXDATE);
        foreach (self::overrides($vcal, $master) as $override) {
            $vcal->remove($override);
        }
    }

    public static function bumpSequence(VEvent $vevent): void
    {
        $vevent->SEQUENCE = (int) (string) ($vevent->SEQUENCE ?? 0) + 1;
    }

    /** The series' overrides: every other VEVENT with the master's UID. */
    private static function overrides(VCalendar $vcal, VEvent $master): array
    {
        $overrides = [];
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ($vevent !== $master && (string) $vevent->UID === (string) $master->UID) {
                $overrides[] = $vevent;
            }
        }

        return $overrides;
    }

    /** Every TZID of the event that sabre can't map onto a PHP zone is read in PHP's default zone; that is logged once per request. */
    private static function warnUnknownTimezones(VCalendar $vcal, string $uid): void
    {
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ((string) $vevent->UID !== $uid) {
                continue;
            }
            foreach (self::TZID_PROPERTIES as $name) {
                foreach ($vevent->select($name) as $property) {
                    $tzid = isset($property['TZID']) ? (string) $property['TZID'] : '';
                    if ($tzid !== '' && !VTimezone::isKnown($tzid, $vcal)) {
                        VTimezone::warnUnknown($tzid);
                    }
                }
            }
        }
    }

    /** Adds a date-time property; a floating one is written as local wall time without TZID or Z. */
    private static function addDateTime(Component $component, string $name, DateTimeImmutable $value, bool $floating): void
    {
        $property = $component->add($name, null);
        $property->setDateTime($value, $floating);
    }

    /**
     * The occurrence key as a RECURRENCE-ID/EXDATE value: [value, params, floating].
     * A TZID master gets its own timezone, a UTC master stays UTC, a floating master gets the local time in $tz.
     */
    private static function keyValue(VEvent $master, string $key, DateTimeZone $tz): array
    {
        if (!$master->DTSTART->hasTime()) {
            return [$key, ['VALUE' => 'DATE'], false];
        }
        $utc = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $key, new DateTimeZone('UTC'));
        if ($utc === false) {
            throw new InvalidArgumentException('Invalid recurrence id ' . $key);
        }
        if ($master->DTSTART->isFloating()) {
            return [$utc->setTimezone($tz), [], true];
        }

        return [$utc->setTimezone($master->DTSTART->getDateTime()->getTimezone()), [], false];
    }
}
