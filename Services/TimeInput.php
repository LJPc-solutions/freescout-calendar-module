<?php

namespace Modules\LJPcCalendarModule\Services;

use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Validation\ValidationException;

/** Turns API time input into event times in the agent's FreeScout timezone (never the browser's). */
final class TimeInput
{
    /** FreeScout runs MySQL without strict mode, so a year below 1000 would be stored as a zero date; the migration also starts at 1900. */
    public const MIN_YEAR = 1900;
    public const MAX_YEAR = 2200;

    public static function normalize(bool $allDay, string $start, ?string $end, string $timezone): array
    {
        if ($allDay) {
            $startDate = self::date($start, 'start');
            $endDate = ($end !== null && $end !== '') ? self::date($end, 'end') : $startDate->modify('+1 day');
            if ($endDate <= $startDate) {
                throw ValidationException::withMessages(['end' => __('The end must be after the start.')]);
            }

            return ['all_day' => true, 'start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d'), 'timezone' => $timezone];
        }

        $tz = new DateTimeZone($timezone);
        $startTime = self::dateTime($start, $tz, 'start');
        $endTime = ($end !== null && $end !== '') ? self::dateTime($end, $tz, 'end') : $startTime->modify('+1 hour');
        if ($endTime < $startTime) {
            throw ValidationException::withMessages(['end' => __('The end can\'t be before the start.')]);
        }

        return ['all_day' => false, 'start' => $startTime, 'end' => $endTime, 'timezone' => $timezone];
    }

    public static function userTimezone(?User $user): string
    {
        $timezone = ($user && $user->timezone) ? $user->timezone : (config('app.timezone') ?: 'UTC');

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    /**
     * "d-m-Y, H:i – H:i" in the user's zone and clock, with the end date when it falls on another day; an all-day
     * range is its dates, the exclusive end date minus one.
     */
    public static function range(DateTimeImmutable $start, DateTimeImmutable $end, bool $allDay, DateTimeZone $tz, User $user): string
    {
        if ($allDay) {
            $last = $end->modify('-1 day');

            return $last > $start ? $start->format(__('d-m-Y')) . ' – ' . $last->format(__('d-m-Y')) : $start->format(__('d-m-Y'));
        }
        $start = $start->setTimezone($tz);
        $end = $end->setTimezone($tz);
        $clock = self::userClock($user, 'H:i');
        $until = $end->format('Y-m-d') === $start->format('Y-m-d') ? $end->format($clock) : $end->format(__('d-m-Y')) . ', ' . $end->format($clock);

        return $start->format(__('d-m-Y')) . ', ' . $start->format($clock) . ' – ' . $until;
    }

    /**
     * A 24-hour date format on the user's own FreeScout clock, converted the way core's User::dateFormat() does
     * ("H:i" becomes "h:ia"). Without a user it stays on the 24-hour clock.
     */
    public static function userClock(?User $user, string $format): string
    {
        if (!$user || $user->time_format != User::TIME_FORMAT_12) {
            return $format;
        }

        return strtr($format, ['H' => 'h', 'G' => 'g', ':i' => ':ia', ':i:s' => ':i:sa', ':ia:s' => ':i:sa']);
    }

    private static function date(string $value, string $key): DateTimeImmutable
    {
        // A date, optionally followed by the time an ISO client sends along; all-day events ignore that time.
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value, $match)) {
            throw ValidationException::withMessages([$key => __('Invalid date.')]);
        }
        $day = $match[1];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $day || !self::yearInRange((int) substr($day, 0, 4))) {
            throw ValidationException::withMessages([$key => __('Invalid date.')]);
        }

        return $date;
    }

    private static function yearInRange(int $year): bool
    {
        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    private static function dateTime(string $value, DateTimeZone $tz, string $key): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            throw ValidationException::withMessages([$key => __('Invalid date and time.')]);
        }
        if (!checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            || !self::yearInRange((int) substr($value, 0, 4))
            || (int) substr($value, 11, 2) > 23 || (int) substr($value, 14, 2) > 59
            || (strlen($value) > 16 && $value[16] === ':' && (int) substr($value, 17, 2) > 59)) {
            throw ValidationException::withMessages([$key => __('Invalid date and time.')]);
        }
        try {
            $dateTime = new DateTimeImmutable($value, $tz);
        } catch (Exception $e) {
            throw ValidationException::withMessages([$key => __('Invalid date and time.')]);
        }

        return $dateTime->setTimezone($tz);
    }
}
