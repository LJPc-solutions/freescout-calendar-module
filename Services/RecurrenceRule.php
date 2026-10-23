<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * Converts the event form's recurrence options to RRULE and back. Every option maps onto Graph's patternedRecurrence,
 * except a monthly day 29 to 31 (Outlook moves those to the last day of shorter months) and weekdays that leave out the
 * start's own; MicrosoftProvider locks such a series at series level (GraphRecurrence).
 */
final class RecurrenceRule
{
    public const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    public const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /**
     * The RRULE for the form's options. A timed UNTIL is the end of that day in $timezone: in UTC for a start with a zone,
     * and in local time for a floating start, which RFC 5545 (section 3.3.10) wants floating too.
     */
    public static function toRrule(array $input, bool $allDay, string $timezone, bool $floating = false): string
    {
        $freq = strtoupper((string) ($input['freq'] ?? ''));
        if (!in_array($freq, self::FREQUENCIES, true)) {
            self::fail('recurrence.freq', __('Choose how often the event repeats.'));
        }
        $parts = ['FREQ=' . $freq];

        foreach (['interval', 'byday', 'bysetpos', 'bymonthday', 'until', 'count'] as $key) {
            if (($input[$key] ?? null) === '' || ($input[$key] ?? null) === null) {
                unset($input[$key]);
            }
        }

        $interval = 1;
        if (isset($input['interval'])) {
            $interval = self::integer($input['interval'], 1, 999, 'recurrence.interval', __('The repeat interval must be between 1 and 999.'));
        }
        if ($interval > 1) {
            $parts[] = 'INTERVAL=' . $interval;
        }

        $byday = [];
        if (isset($input['byday'])) {
            if (!is_array($input['byday']) || array_filter($input['byday'], static fn ($d) => !is_string($d))) {
                self::fail('recurrence.byday', __('Invalid weekday.'));
            }
            $byday = array_values(array_unique(array_map('strtoupper', $input['byday'])));
            if (array_diff($byday, self::WEEKDAYS)) {
                self::fail('recurrence.byday', __('Invalid weekday.'));
            }
        }
        $hasSetpos = isset($input['bysetpos']);
        $hasMonthday = isset($input['bymonthday']);

        if ($freq !== 'MONTHLY') {
            if ($hasSetpos) {
                self::fail('recurrence.bysetpos', __('A position in the month only applies to monthly events.'));
            }
            if ($hasMonthday) {
                self::fail('recurrence.bymonthday', __('A day of the month only applies to monthly events.'));
            }
            if ($byday && $freq !== 'WEEKLY') {
                self::fail('recurrence.byday', __('Weekdays only apply to weekly and monthly events.'));
            }
        }

        if ($freq === 'WEEKLY' && $byday) {
            $parts[] = 'BYDAY=' . implode(',', $byday);
        } elseif ($freq === 'MONTHLY') {
            if ($hasSetpos && $hasMonthday) {
                self::fail('recurrence.bymonthday', __('Choose a day of the month or a weekday position, not both.'));
            }
            if ($byday && !$hasSetpos) {
                self::fail('recurrence.bysetpos', __('Choose one weekday and its position in the month.'));
            }
            if ($hasSetpos) {
                $setpos = self::integer($input['bysetpos'], -1, 4, 'recurrence.bysetpos', __('Choose one weekday and its position in the month.'));
                if (count($byday) !== 1 || $setpos === 0) {
                    self::fail('recurrence.bysetpos', __('Choose one weekday and its position in the month.'));
                }
                $parts[] = 'BYDAY=' . $setpos . $byday[0];
            } elseif ($hasMonthday) {
                $day = self::integer($input['bymonthday'], 1, 31, 'recurrence.bymonthday', __('The day of the month must be between 1 and 31.'));
                $parts[] = 'BYMONTHDAY=' . $day;
            }
        }

        if (isset($input['until']) && isset($input['count'])) {
            self::fail('recurrence.until', __('Choose an end date or a number of times, not both.'));
        }
        if (isset($input['count'])) {
            $parts[] = 'COUNT=' . self::integer($input['count'], 1, 999, 'recurrence.count', __('The number of times must be between 1 and 999.'));
        } elseif (isset($input['until'])) {
            $until = is_string($input['until'])
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $input['until'], new DateTimeZone($timezone))
                : false;
            if ($until === false || $until->format('Y-m-d') !== $input['until']) {
                self::fail('recurrence.until', __('Invalid end date.'));
            }
            if ($allDay) {
                $parts[] = 'UNTIL=' . $until->format('Ymd');
            } elseif ($floating) {
                $parts[] = 'UNTIL=' . $until->format('Ymd') . 'T235959';
            } else {
                $parts[] = 'UNTIL=' . $until->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            }
        }

        return implode(';', $parts);
    }

    /** The form's options for an RRULE, or null when the form can't show it. UNTIL becomes the day it falls on in $timezone. */
    public static function fromRrule(?string $rrule, string $timezone = 'UTC'): ?array
    {
        if (!$rrule) {
            return null;
        }
        $parts = [];
        foreach (explode(';', strtoupper(trim($rrule))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$key] = $value;
        }
        $supported = ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'COUNT', 'UNTIL', 'WKST'];
        if (array_diff(array_keys($parts), $supported) || !in_array($parts['FREQ'] ?? '', self::FREQUENCIES, true)) {
            return null;
        }

        if (isset($parts['WKST']) && $parts['WKST'] !== 'MO') {
            return null;
        }
        if (isset($parts['COUNT'], $parts['UNTIL'])) {
            return null;
        }
        if (isset($parts['BYDAY'], $parts['BYMONTHDAY'])) {
            return null;
        }
        foreach (['INTERVAL' => [1, 999], 'COUNT' => [1, 999], 'BYMONTHDAY' => [1, 31]] as $key => [$min, $max]) {
            if (isset($parts[$key]) && (!ctype_digit($parts[$key]) || (int) $parts[$key] < $min || (int) $parts[$key] > $max)) {
                return null;
            }
        }

        $result = ['freq' => $parts['FREQ'], 'interval' => (int) ($parts['INTERVAL'] ?? 1)];
        if (isset($parts['BYDAY'])) {
            if ($parts['FREQ'] === 'WEEKLY') {
                $days = explode(',', $parts['BYDAY']);
                if (array_diff($days, self::WEEKDAYS)) {
                    return null;
                }
                $result['byday'] = $days;
            } elseif ($parts['FREQ'] === 'MONTHLY' && preg_match('/^(-1|[1-4])(MO|TU|WE|TH|FR|SA|SU)$/', $parts['BYDAY'], $m)) {
                $result['byday'] = [$m[2]];
                $result['bysetpos'] = (int) $m[1];
            } else {
                return null;
            }
        }
        if (isset($parts['BYMONTHDAY'])) {
            if ($parts['FREQ'] !== 'MONTHLY') {
                return null;
            }
            $result['bymonthday'] = (int) $parts['BYMONTHDAY'];
        }
        if (isset($parts['COUNT'])) {
            $result['count'] = (int) $parts['COUNT'];
        }
        if (isset($parts['UNTIL'])) {
            // A date, a UTC date-time (with a zoned start), or a local date-time (with a floating start).
            $value = $parts['UNTIL'];
            if (strlen($value) === 8) {
                $until = DateTimeImmutable::createFromFormat('!Ymd', $value, new DateTimeZone($timezone));
            } elseif (substr($value, -1) === 'Z') {
                $until = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
            } else {
                $until = DateTimeImmutable::createFromFormat('Ymd\THis', $value, new DateTimeZone($timezone));
            }
            if ($until === false) {
                return null;
            }
            $result['until'] = $until->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
        }

        return $result;
    }

    /**
     * The rule as toRrule() writes it, with the weekdays in a fixed order, so two rules that mean the same compare
     * equal: an explicit INTERVAL=1, WKST=MO (the default), the order of the parts and the time of an UNTIL on the same
     * day make no difference. Null for a rule the form can't show (a custom rule): only its exact text matches it.
     */
    public static function canonical(string $rrule, bool $allDay, string $timezone): ?string
    {
        $rule = self::fromRrule($rrule, $timezone);
        if ($rule === null) {
            return null;
        }
        if (isset($rule['byday'])) {
            $rule['byday'] = array_values(array_intersect(self::WEEKDAYS, $rule['byday']));
        }

        return self::toRrule($rule, $allDay, $timezone);
    }

    /** Accepts ints and integer strings within the range; anything else fails under the given key. */
    private static function integer(mixed $value, int $min, int $max, string $key, string $message): int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            self::fail($key, $message);
        }

        return $value;
    }

    private static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
