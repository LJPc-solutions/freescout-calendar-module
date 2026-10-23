<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\TimeZoneUtil;

/**
 * Builds a VTIMEZONE from PHP's tz database (RFC 5545 section 3.6.5): explicit transitions from $fromYear through
 * $toYear, and when the zone still observes DST in $toYear, the last pair as yearly recurring rules so later dates
 * keep the right offset.
 */
final class VTimezone
{
    /** The properties of an event that can carry a TZID. */
    public const TZID_PROPERTIES = ['DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE'];

    /** Container key of the TZIDs already warned about in this request. */
    private const WARNED = 'ljpccalendarmodule.unknown_tzids';

    /**
     * A VTIMEZONE for every TZID the events of $vcal use and resolve() knows, from the year before its first use until
     * two years from now. For VCALENDARs a provider's mapper builds.
     */
    public static function addForEvents(VCalendar $vcal): void
    {
        $firstYears = [];
        foreach ($vcal->select('VEVENT') as $vevent) {
            foreach (self::TZID_PROPERTIES as $name) {
                foreach ($vevent->select($name) as $property) {
                    $tzid = (string) ($property['TZID'] ?? '');
                    if ($tzid !== '') {
                        $firstYears[$tzid] = min($firstYears[$tzid] ?? PHP_INT_MAX, (int) substr((string) $property->getValue(), 0, 4));
                    }
                }
            }
        }
        ksort($firstYears);
        $lastYear = (int) gmdate('Y') + 2;
        foreach ($firstYears as $tzid => $year) {
            $zone = self::resolve($tzid);
            if ($zone !== null) {
                self::addTo($vcal, $tzid, $year - 1, max($lastYear, $year - 1), $zone);
            }
        }
    }

    /**
     * Adds the VTIMEZONE of $tzid, built from $zone, or from what resolve() finds for it; a name from sabre's maps (a
     * Windows zone) gets the rules of the zone it stands for. Throws InvalidArgumentException for a TZID it can't resolve.
     */
    public static function addTo(VCalendar $vcal, string $tzid, int $fromYear, int $toYear, ?DateTimeZone $zone = null): void
    {
        $tz = $zone ?? self::resolve($tzid);
        if ($tz === null) {
            throw new InvalidArgumentException('Unknown TZID ' . $tzid);
        }
        $begin = (new DateTimeImmutable($fromYear . '-01-01 00:00:00', $tz))->getTimestamp();
        $end = (new DateTimeImmutable(($toYear + 1) . '-01-01 00:00:00', $tz))->getTimestamp();
        // A fixed offset ("GMT+0100" is +01:00) has no transitions: one STANDARD part describes it.
        $transitions = $tz->getTransitions($begin, $end) ?: [['ts' => $begin, 'offset' => $tz->getOffset(new DateTimeImmutable('@' . $begin)), 'isdst' => false, 'abbr' => $tz->getName()]];

        $component = $vcal->createComponent('VTIMEZONE');
        $component->add('TZID', $tzid);

        // The first entry is the offset in force at $begin; it has no predecessor.
        $initial = array_shift($transitions);
        $rows = [['t' => $initial, 'from' => $initial['offset'], 'rule' => null]];
        $previous = $initial['offset'];
        foreach ($transitions as $transition) {
            $rows[] = ['t' => $transition, 'from' => $previous, 'rule' => null];
            $previous = $transition['offset'];
        }

        $count = count($rows);
        if ($count >= 3 && $rows[$count - 1]['t']['isdst'] !== $rows[$count - 2]['t']['isdst']
            && $rows[$count - 2]['t']['ts'] >= (new DateTimeImmutable($toYear . '-01-01 00:00:00', $tz))->getTimestamp()) {
            foreach ([$count - 2, $count - 1] as $index) {
                $local = $rows[$index]['t']['ts'] + $rows[$index]['from'];
                $rows[$index]['rule'] = self::rule((int) gmdate('n', $local), (int) gmdate('j', $local), (int) gmdate('t', $local), (int) gmdate('w', $local));
            }
        }

        foreach ($rows as $row) {
            $part = $vcal->createComponent($row['t']['isdst'] ? 'DAYLIGHT' : 'STANDARD');
            $part->add('DTSTART', gmdate('Ymd\THis', $row['t']['ts'] + $row['from']));
            if ($row['rule']) {
                $part->add('RRULE', $row['rule']);
            }
            $part->add('TZOFFSETFROM', self::offset($row['from']));
            $part->add('TZOFFSETTO', self::offset($row['t']['offset']));
            $part->add('TZNAME', $row['t']['abbr']);
            $component->add($part);
        }
        $vcal->add($component);
    }

    /**
     * The PHP zone sabre/vobject reads this TZID in: a zone name PHP knows (also an older one, through
     * PhpTimezoneFinder), a name from sabre's maps (Windows zones), or what a VTIMEZONE in $vcal says it is. Null when
     * sabre can't tell: it then reads the TZID in PHP's default zone.
     */
    public static function resolve(string $tzid, ?VCalendar $vcal = null): ?DateTimeZone
    {
        // Without a VCALENDAR the answer only depends on the name, so it is kept for the process.
        static $byName = [];
        if (!array_key_exists($tzid, $byName)) {
            $byName[$tzid] = self::sabre($tzid, null);
        }

        return $byName[$tzid] ?? ($vcal !== null ? self::sabre($tzid, $vcal) : null);
    }

    /** Whether sabre/vobject can read this TZID (resolve() finds its zone). */
    public static function isKnown(string $tzid, ?VCalendar $vcal = null): bool
    {
        return self::resolve($tzid, $vcal) !== null;
    }

    /**
     * Whether a series can be written in this zone: a name PHP lists (also an older one) that sabre reads back as that
     * zone. An offset such as "+02:00" or "GMT+0100" is no name: written as a TZID, other calendar apps can't resolve it.
     */
    public static function isNamedZone(string $tzid): bool
    {
        static $names = null;
        $names = $names ?? array_flip(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));

        return isset($names[$tzid]) && self::resolve($tzid) !== null;
    }

    /** Logs a TZID that isKnown() refused once per request (one PHP process), however many events carry it. */
    public static function warnUnknown(string $tzid, array $context = []): void
    {
        $warned = app()->bound(self::WARNED) ? app(self::WARNED) : [];
        if (isset($warned[$tzid])) {
            return;
        }
        $warned[$tzid] = true;
        app()->instance(self::WARNED, $warned);
        Log::warning('A calendar event uses a time zone PHP does not know; its times are read in FreeScout\'s time zone', ['tzid' => $tzid] + $context);
    }

    private static function sabre(string $tzid, ?VCalendar $vcal): ?DateTimeZone
    {
        try {
            return TimeZoneUtil::getTimeZone($tzid, $vcal, true);
        } catch (\Exception $e) {
            // Sabre says it can't tell with an InvalidArgumentException; its offset finder throws PHP's own exception for
            // an offset out of range ("GMT+9999"). Both mean the same: no zone, which resolve() answers with null.
            return null;
        }
    }

    private static function rule(int $month, int $day, int $daysInMonth, int $weekday): string
    {
        $nth = $day > $daysInMonth - 7 ? -1 : (int) ceil($day / 7);

        return sprintf('FREQ=YEARLY;BYMONTH=%d;BYDAY=%d%s', $month, $nth, ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'][$weekday]);
    }

    private static function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
