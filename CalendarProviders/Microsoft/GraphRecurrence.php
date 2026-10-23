<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Microsoft;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Graph's patternedRecurrence (a recurrencePattern and a recurrenceRange, Graph v1.0 reference) to an RRULE and back.
 * Graph holds fewer rules than RFC 5545 (research question 4); fromRrule() answers null for a rule Graph can't hold
 * exactly, and MicrosoftProvider::writableRecurrence() then locks the series at series level.
 *
 * Two Outlook rules shape the mapping. A day of the month that a month lacks falls on its last day ("monthly on the
 * 31st" is 30 April), which RFC 5545 writes as BYMONTHDAY=28,29,30,31;BYSETPOS=-1; a plain BYMONTHDAY=31 skips those
 * months and has no Graph pattern. And Graph starts a series at its first occurrence, so a start that is no occurrence
 * of the rule (allowed, though undefined, in RFC 5545) has no Graph pattern either. UNVERIFIED that Graph expands day 29
 * to 31 (and 29 February) this way; a difference shows up as occurrences the next sync marks as deleted.
 */
final class GraphRecurrence
{
    private const DAYS = ['sunday' => 'SU', 'monday' => 'MO', 'tuesday' => 'TU', 'wednesday' => 'WE', 'thursday' => 'TH', 'friday' => 'FR', 'saturday' => 'SA'];
    private const INDEX = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'last' => -1];
    /** The days each month has at least and at most. */
    private const MIN_DAYS = [1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];
    private const MAX_DAYS = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];
    /** The rule parts this class reads and writes, in the order it writes them. */
    private const PARTS = ['FREQ', 'INTERVAL', 'BYMONTH', 'BYDAY', 'BYMONTHDAY', 'BYSETPOS', 'WKST', 'COUNT', 'UNTIL'];

    /**
     * The RRULE for a patternedRecurrence, or null for one FreeScout can't read. Fields the pattern type doesn't use are
     * ignored: Graph's answers fill them with defaults. The range's end date is in its recurrenceTimeZone, else in
     * $timezone (the series' zone); a timed series' UNTIL is the end of that day there, in UTC, as RecurrenceRule writes
     * it, and an all-day series' UNTIL is the date.
     */
    public static function toRrule(array $recurrence, string $timezone, bool $allDay = false): ?string
    {
        $pattern = $recurrence['pattern'] ?? null;
        $range = $recurrence['range'] ?? null;
        if (!is_array($pattern) || !is_array($range)) {
            return null;
        }
        $interval = $pattern['interval'] ?? 1;
        if (!is_int($interval) || $interval < 1) {
            return null;
        }
        $parts = self::patternParts($pattern, $interval, self::date($range['startDate'] ?? null));
        if ($parts === null) {
            return null;
        }
        $parts['INTERVAL'] = $interval > 1 ? (string) $interval : null;

        switch ($range['type'] ?? null) {
            case 'noEnd':
                break;
            case 'numbered':
                $count = $range['numberOfOccurrences'] ?? null;
                if (!is_int($count) || $count < 1) {
                    return null;
                }
                $parts['COUNT'] = (string) $count;
                break;
            case 'endDate':
                $end = self::date($range['endDate'] ?? null);
                if ($end === null) {
                    return null;
                }
                $zone = WindowsZones::toIana((string) ($range['recurrenceTimeZone'] ?? '')) ?? $timezone;
                $parts['UNTIL'] = $allDay
                    ? $end->format('Ymd')
                    : (new DateTimeImmutable($end->format('Y-m-d') . ' 23:59:59', new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
                break;
            default:
                return null;
        }
        $rule = [];
        foreach (self::PARTS as $name) {
            if (isset($parts[$name])) {
                $rule[] = $name . '=' . $parts[$name];
            }
        }

        return implode(';', $rule);
    }

    /**
     * Graph's patternedRecurrence for an RRULE whose series starts at $start (in the series' zone), or null when Graph
     * can't hold it exactly. The pattern and range carry only the fields their type needs (Graph refuses other values);
     * the range has no recurrenceTimeZone, so it is the event's own zone.
     */
    public static function fromRrule(string $rrule, DateTimeImmutable $start, bool $allDay): ?array
    {
        $parts = self::parse($rrule);
        if ($parts === null || (isset($parts['COUNT']) && isset($parts['UNTIL']))) {
            return null;
        }
        $interval = isset($parts['INTERVAL']) ? self::number($parts['INTERVAL']) : 1;
        $days = isset($parts['BYDAY']) ? self::byDay($parts['BYDAY']) : null;
        $monthDays = isset($parts['BYMONTHDAY']) ? self::numbers($parts['BYMONTHDAY']) : null;
        $months = isset($parts['BYMONTH']) ? self::numbers($parts['BYMONTH']) : null;
        $positions = isset($parts['BYSETPOS']) ? self::numbers($parts['BYSETPOS']) : null;
        $weekStart = $parts['WKST'] ?? 'MO';
        if ($interval === null || $interval < 1 || $days === [] || $monthDays === [] || $months === [] || $positions === [] || !in_array($weekStart, self::DAYS, true)) {
            return null;
        }

        $pattern = null;
        switch ($parts['FREQ'] ?? null) {
            case 'DAILY':
                if ($months !== null || $monthDays !== null || $positions !== null) {
                    return null;
                }
                if ($days === null) {
                    $pattern = ['type' => 'daily', 'interval' => $interval];
                } elseif ($interval === 1) {
                    // "Every weekday": Graph has it as a weekly pattern.
                    $pattern = self::weekly(1, $days, $weekStart);
                }
                break;
            case 'WEEKLY':
                if ($months === null && $monthDays === null && $positions === null) {
                    $pattern = self::weekly($interval, $days ?? [[null, self::DAYS[strtolower($start->format('l'))]]], $weekStart);
                }
                break;
            case 'MONTHLY':
                if ($months === null) {
                    $pattern = self::dayPattern('Monthly', $interval, $days, $monthDays, $positions, (int) $start->format('j'), 28, 31);
                }
                break;
            case 'YEARLY':
                if ($months === null) {
                    // Only the start says which day of which month.
                    $month = (int) $start->format('n');
                    $pattern = $days === null && $monthDays === null && $positions === null
                        ? self::dayPattern('Yearly', $interval, null, null, null, (int) $start->format('j'), self::MIN_DAYS[$month], self::MAX_DAYS[$month])
                        : null;
                } elseif (count($months) === 1 && $months[0] >= 1 && $months[0] <= 12) {
                    $month = $months[0];
                    $pattern = self::dayPattern('Yearly', $interval, $days, $monthDays, $positions, (int) $start->format('j'), self::MIN_DAYS[$month], self::MAX_DAYS[$month]);
                }
                if ($pattern !== null) {
                    $pattern['month'] = $month;
                }
                break;
        }
        if ($pattern === null || !self::startsOnPattern($pattern, $start)) {
            return null;
        }
        $range = self::range($parts, $start, $allDay);

        return $range === null ? null : ['pattern' => $pattern, 'range' => $range];
    }

    /**
     * Whether the rule repeats monthly on day 29, 30 or 31 as RFC 5545 means it, skipping the months without that day:
     * Outlook moves those occurrences to the month's last day instead, so Graph can't hold the rule. Outlook's own form
     * (BYMONTHDAY=28,...,31;BYSETPOS=-1) is not meant; fromRrule() maps that one.
     */
    public static function onLateMonthDay(string $rrule, DateTimeImmutable $start): bool
    {
        $parts = self::parse($rrule);
        if ($parts === null || ($parts['FREQ'] ?? null) !== 'MONTHLY' || isset($parts['BYDAY']) || isset($parts['BYSETPOS']) || isset($parts['BYMONTH'])) {
            return false;
        }
        if (!isset($parts['BYMONTHDAY'])) {
            return (int) $start->format('j') > 28;
        }
        $days = self::numbers($parts['BYMONTHDAY']);

        return count($days) === 1 && $days[0] > 28 && $days[0] <= 31;
    }

    /** The FREQ and BY parts of a recurrencePattern; $startDate is the range's start, which a yearly pattern may repeat. */
    private static function patternParts(array $pattern, int $interval, ?DateTimeImmutable $startDate): ?array
    {
        switch ($pattern['type'] ?? null) {
            case 'daily':
                return ['FREQ' => 'DAILY'];
            case 'weekly':
                $days = self::graphDays($pattern['daysOfWeek'] ?? null);
                $weekStart = self::DAYS[$pattern['firstDayOfWeek'] ?? 'sunday'] ?? null;
                if ($days === null || $weekStart === null) {
                    return null;
                }
                // The week's first day only matters for a rule that skips weeks and repeats on several days.
                $significant = $interval > 1 && count($days) > 1 && $weekStart !== 'MO';

                return ['FREQ' => 'WEEKLY', 'BYDAY' => implode(',', $days), 'WKST' => $significant ? $weekStart : null];
            case 'absoluteMonthly':
                $day = $pattern['dayOfMonth'] ?? null;

                return is_int($day) && $day >= 1 && $day <= 31 ? ['FREQ' => 'MONTHLY'] + self::monthDay($day, 28) : null;
            case 'relativeMonthly':
                $relative = self::relative($pattern);

                return $relative === null ? null : ['FREQ' => 'MONTHLY'] + $relative;
            case 'absoluteYearly':
                $month = $pattern['month'] ?? null;
                $day = $pattern['dayOfMonth'] ?? null;
                if (!is_int($month) || !isset(self::MAX_DAYS[$month]) || !is_int($day) || $day < 1 || $day > self::MAX_DAYS[$month]) {
                    return null;
                }
                // On the day the series starts: the start says it, as FreeScout's form writes a yearly rule.
                if ($startDate !== null && (int) $startDate->format('n') === $month && (int) $startDate->format('j') === $day && $day <= self::MIN_DAYS[$month]) {
                    return ['FREQ' => 'YEARLY'];
                }

                return ['FREQ' => 'YEARLY', 'BYMONTH' => (string) $month] + self::monthDay($day, self::MIN_DAYS[$month]);
            case 'relativeYearly':
                $month = $pattern['month'] ?? null;
                $relative = self::relative($pattern);
                if (!is_int($month) || !isset(self::MAX_DAYS[$month]) || $relative === null) {
                    return null;
                }

                return ['FREQ' => 'YEARLY', 'BYMONTH' => (string) $month] + $relative;
        }

        return null;
    }

    /** BYMONTHDAY for a day of the month; a day some months lack falls on the last day of those months, as Outlook does. */
    private static function monthDay(int $day, int $minDays): array
    {
        if ($day <= $minDays) {
            return ['BYMONTHDAY' => (string) $day];
        }

        return ['BYMONTHDAY' => implode(',', range($minDays, $day)), 'BYSETPOS' => '-1'];
    }

    /** BYDAY (and BYSETPOS) for a relative pattern: one day as "2TU", several as "SA,SU" with the position. */
    private static function relative(array $pattern): ?array
    {
        $days = self::graphDays($pattern['daysOfWeek'] ?? null);
        $index = self::INDEX[$pattern['index'] ?? 'first'] ?? null;
        if ($days === null || $index === null) {
            return null;
        }
        if (count($days) === 1) {
            return ['BYDAY' => $index . $days[0]];
        }

        return ['BYDAY' => implode(',', $days), 'BYSETPOS' => (string) $index];
    }

    /** RFC 5545 day names for Graph's daysOfWeek; null when the list is empty or holds something else. */
    private static function graphDays($daysOfWeek): ?array
    {
        if (!is_array($daysOfWeek) || $daysOfWeek === []) {
            return null;
        }
        $days = [];
        foreach ($daysOfWeek as $day) {
            if (!is_string($day) || !isset(self::DAYS[$day])) {
                return null;
            }
            $days[] = self::DAYS[$day];
        }

        return array_values(array_unique($days));
    }

    /** A weekly pattern; days with an ordinal ("1MO") have none. */
    private static function weekly(int $interval, array $days, string $weekStart): ?array
    {
        $names = [];
        foreach ($days as [$ordinal, $day]) {
            if ($ordinal !== null) {
                return null;
            }
            $names[] = array_search($day, self::DAYS, true);
        }

        return ['type' => 'weekly', 'interval' => $interval, 'daysOfWeek' => array_values(array_unique($names)), 'firstDayOfWeek' => array_search($weekStart, self::DAYS, true)];
    }

    /**
     * An absolute or relative pattern ($kind "Monthly" or "Yearly") from the BY parts of a monthly rule or of a yearly
     * rule's month, which has $minDays to $maxDays days. Without BY parts the start's day of the month is the day.
     */
    private static function dayPattern(string $kind, int $interval, ?array $days, ?array $monthDays, ?array $positions, int $startDay, int $minDays, int $maxDays): ?array
    {
        $absolute = function (int $day) use ($kind, $interval) {
            return ['type' => 'absolute' . $kind, 'interval' => $interval, 'dayOfMonth' => $day];
        };
        if ($days !== null && $monthDays !== null) {
            return null;
        }
        if ($monthDays !== null) {
            if ($positions === null && count($monthDays) === 1) {
                $day = $monthDays[0];
                if ($day === -1) {
                    return $absolute($maxDays); // the last day of the month
                }

                return $day >= 1 && $day <= $minDays ? $absolute($day) : null;
            }
            $last = max($monthDays);
            if ($positions === [-1] && $last > $minDays && $last <= $maxDays && $monthDays === range($minDays, $last)) {
                return $absolute($last);
            }

            return null;
        }
        if ($days !== null) {
            if ($positions === null && count($days) === 1) {
                [$ordinal, $day] = $days[0];
                $index = array_search($ordinal, self::INDEX, true);

                return $index === false ? null : ['type' => 'relative' . $kind, 'interval' => $interval, 'daysOfWeek' => [array_search($day, self::DAYS, true)], 'index' => $index];
            }
            $index = $positions !== null && count($positions) === 1 ? array_search($positions[0], self::INDEX, true) : false;
            if ($index === false) {
                return null;
            }
            $names = [];
            foreach ($days as [$ordinal, $day]) {
                if ($ordinal !== null) {
                    return null;
                }
                $names[] = array_search($day, self::DAYS, true);
            }

            return ['type' => 'relative' . $kind, 'interval' => $interval, 'daysOfWeek' => array_values(array_unique($names)), 'index' => $index];
        }

        return $positions === null && $startDay <= $minDays ? $absolute($startDay) : null;
    }

    /** Whether the series' start is an occurrence of the pattern, as Graph requires. */
    private static function startsOnPattern(array $pattern, DateTimeImmutable $start): bool
    {
        if (isset($pattern['month']) && (int) $start->format('n') !== $pattern['month']) {
            return false;
        }
        switch ($pattern['type']) {
            case 'weekly':
                return in_array(strtolower($start->format('l')), $pattern['daysOfWeek'], true);
            case 'absoluteMonthly':
            case 'absoluteYearly':
                return (int) $start->format('j') === min($pattern['dayOfMonth'], (int) $start->format('t'));
            case 'relativeMonthly':
            case 'relativeYearly':
                $matches = [];
                $first = $start->modify('first day of this month');
                for ($day = 0; $day < (int) $start->format('t'); $day++) {
                    $date = $first->modify('+' . $day . ' days');
                    if (in_array(strtolower($date->format('l')), $pattern['daysOfWeek'], true)) {
                        $matches[] = $date->format('Y-m-d');
                    }
                }
                $index = self::INDEX[$pattern['index']];
                $match = $index === -1 ? end($matches) : ($matches[$index - 1] ?? null);

                return $match === $start->format('Y-m-d');
        }

        return true;
    }

    /**
     * The recurrenceRange for COUNT, UNTIL or neither. Graph's end date is inclusive, so a timed UNTIL that falls before
     * that day's occurrence ends the series the day before.
     */
    private static function range(array $parts, DateTimeImmutable $start, bool $allDay): ?array
    {
        $startDate = $start->format('Y-m-d');
        if (isset($parts['COUNT'])) {
            $count = self::number($parts['COUNT']);

            return $count !== null && $count >= 1 ? ['type' => 'numbered', 'startDate' => $startDate, 'numberOfOccurrences' => $count] : null;
        }
        if (!isset($parts['UNTIL'])) {
            return ['type' => 'noEnd', 'startDate' => $startDate];
        }
        $value = $parts['UNTIL'];
        $zone = $start->getTimezone();
        if (preg_match('/^\d{8}$/', $value)) {
            $until = DateTimeImmutable::createFromFormat('!Ymd', $value, $zone);
            $end = $until;
        } else {
            $until = preg_match('/^\d{8}T\d{6}Z$/', $value)
                ? DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'))
                : DateTimeImmutable::createFromFormat('Ymd\THis', $value, $zone);
            if ($until === false) {
                return null;
            }
            $end = $until->setTimezone($zone);
            if (!$allDay && new DateTimeImmutable($end->format('Y-m-d') . ' ' . $start->format('H:i:s'), $zone) > $until) {
                $end = $end->modify('-1 day');
            }
        }
        if ($until === false || $end->format('Y-m-d') < $startDate) {
            return null;
        }

        return ['type' => 'endDate', 'startDate' => $startDate, 'endDate' => $end->format('Y-m-d')];
    }

    /** The rule's parts by name, or null for a rule with a part this class doesn't know, or with a part twice. */
    private static function parse(string $rrule): ?array
    {
        $parts = [];
        foreach (explode(';', strtoupper(trim($rrule))) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (!in_array($name, self::PARTS, true) || $value === '' || isset($parts[$name])) {
                return null;
            }
            $parts[$name] = $value;
        }

        return isset($parts['FREQ']) ? $parts : null;
    }

    /** BYDAY as [ordinal or null, day] pairs; [] when a value is not a day. */
    private static function byDay(string $value): array
    {
        $days = [];
        foreach (explode(',', $value) as $item) {
            if (!preg_match('/^([+-]?\d{1,2})?(SU|MO|TU|WE|TH|FR|SA)$/', $item, $match)) {
                return [];
            }
            $days[] = [$match[1] !== '' ? (int) $match[1] : null, $match[2]];
        }

        return $days;
    }

    /** A list of integers; [] when a value is not one. */
    private static function numbers(string $value): array
    {
        $numbers = [];
        foreach (explode(',', $value) as $item) {
            $number = self::number($item);
            if ($number === null) {
                return [];
            }
            $numbers[] = $number;
        }

        return $numbers;
    }

    private static function number(string $value): ?int
    {
        return preg_match('/^[+-]?\d{1,9}$/', $value) ? (int) $value : null;
    }

    /** A Y-m-d date (Graph's Date type), or null. */
    private static function date($value): ?DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
