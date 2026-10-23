<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Conversation;
use App\Option;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Jobs\SendReminder;

/**
 * Reminders (spec section 14): calendar:sync scans every minute for occurrences whose reminder time passed since the
 * last scan, and mails the event's author and the linked conversation's assignee while they can view the calendar.
 * Mail only, because core's bell shows nothing outside a conversation (section 24). An occurrence that has started
 * (an all-day one: whose day is over) gets none, so the first scan after downtime sends no stale reminders.
 */
final class ReminderService
{
    public const OPTION = 'ljpccalendarmodule.reminders';
    /** An all-day event reminds at this hour in the calendar's zone, that many whole days before (decision 2026-10-23). */
    public const ALL_DAY_HOUR = 9;
    /** Log rows of occurrences that started longer ago than this can't stop a reminder any more. */
    private const KEEP_DAYS = 2;

    /** @return int the reminders queued */
    public function scan(?DateTimeImmutable $now = null): int
    {
        $utc = new DateTimeZone('UTC');
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($utc);
        $stored = Option::get(self::OPTION, null, true, false);
        // The first scan only starts the clock: an install that just turned reminders on gets none from the past.
        $queued = is_array($stored) && isset($stored['scanned_at'])
            ? $this->remind(new DateTimeImmutable($stored['scanned_at'], $utc), $now)
            : 0;
        // Stored last, so a scan that failed is repeated; the log keeps it from mailing anyone twice.
        Option::set(self::OPTION, ['scanned_at' => $now->format('Y-m-d H:i:s')]);
        DB::table('calendar_reminder_log')
            ->where('occurrence_start', '<', $now->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s'))
            ->delete();

        return $queued;
    }

    private function remind(DateTimeImmutable $since, DateTimeImmutable $now): int
    {
        $longest = max((int) CalendarEvent::max('reminder_minutes'), (int) Calendar::max('default_reminder_minutes'));
        if ($longest <= 0) {
            return 0;
        }
        // All-day rows hold a local date at 00:00, so a day of slack on either side covers every zone.
        $rows = DB::table('calendar_occurrences as o')
            ->join('calendar_events as e', 'e.id', '=', 'o.event_id')
            ->join('calendars as c', 'c.id', '=', 'o.calendar_id')
            ->where('c.enabled', true)
            ->where('o.status', '!=', 'cancelled')
            ->where('e.status', '!=', 'cancelled')
            ->where(function ($query) {
                $query->whereNull('e.invite_state')->orWhere('e.invite_state', '!=', InviteActions::STATE_DECLINED);
            })
            ->where(function ($query) {
                $query->whereNotNull('e.author_id')->orWhereNotNull('e.conversation_id');
            })
            ->where('o.ends_at', '>', $now->modify('-1 day')->format('Y-m-d H:i:s'))
            ->where('o.starts_at', '<=', $now->modify('+' . ($longest + 1440) . ' minutes')->format('Y-m-d H:i:s'))
            ->get(['o.event_id', 'o.calendar_id', 'o.recurrence_id', 'o.summary', 'o.starts_at', 'o.ends_at', 'o.all_day',
                'e.reminder_minutes', 'e.author_id', 'e.conversation_id', 'c.default_reminder_minutes']);
        $calendars = Calendar::with('permissionRows')->whereIn('id', $rows->pluck('calendar_id')->unique()->all())->get()->keyBy('id');

        $due = [];
        foreach ($rows as $row) {
            // Null is the calendar's default; -1 is "No reminder" (EventsController).
            $minutes = $row->reminder_minutes === null ? $row->default_reminder_minutes : $row->reminder_minutes;
            if ($minutes === null || (int) $minutes < 0) {
                continue;
            }
            $tz = new DateTimeZone($calendars[$row->calendar_id]->timezoneName());
            $at = self::remindAt($row, (int) $minutes, $tz);
            if ($at > $since && $at <= $now && self::upcoming($row, $now, $tz)) {
                $due[] = $row;
            }
        }
        if (!$due) {
            return 0;
        }

        $assignees = Conversation::whereIn('id', array_filter(array_column($due, 'conversation_id')))->pluck('user_id', 'id');
        $users = User::whereIn('id', array_merge(array_column($due, 'author_id'), $assignees->values()->all()))->get()->keyBy('id');
        $queued = 0;
        foreach ($due as $row) {
            $ids = array_unique(array_filter([(int) $row->author_id, (int) ($assignees[$row->conversation_id] ?? 0)]));
            foreach ($ids as $id) {
                $user = $users[$id] ?? null;
                if (!$user || !$user->isActive() || !$user->can('view', $calendars[$row->calendar_id]) || !self::claim($row, $id, $now)) {
                    continue;
                }
                SendReminder::dispatch($id, [
                    'event_id'      => (int) $row->event_id,
                    'recurrence_id' => $row->recurrence_id,
                    'summary'       => $row->summary,
                    'starts_at'     => $row->starts_at,
                    'ends_at'       => $row->ends_at,
                    'all_day'       => (bool) $row->all_day,
                ])->onQueue('emails');
                $queued++;
            }
        }

        return $queued;
    }

    /** Timed: the minutes before the start; all-day: ALL_DAY_HOUR in the calendar's zone, the whole days before. */
    private static function remindAt(object $row, int $minutes, DateTimeZone $tz): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        if ($row->all_day) {
            $day = new DateTimeImmutable(substr($row->starts_at, 0, 10) . sprintf(' %02d:00:00', self::ALL_DAY_HOUR), $tz);

            return $day->modify('-' . intdiv($minutes, 1440) . ' days')->setTimezone($utc);
        }

        return (new DateTimeImmutable($row->starts_at, $utc))->modify('-' . $minutes . ' minutes');
    }

    private static function upcoming(object $row, DateTimeImmutable $now, DateTimeZone $tz): bool
    {
        return $row->all_day
            ? new DateTimeImmutable(substr($row->ends_at, 0, 10), $tz) > $now
            : new DateTimeImmutable($row->starts_at, new DateTimeZone('UTC')) > $now;
    }

    /**
     * Logs the reminder before it is queued; false when another scan logged it first (the unique key). The insert
     * has a transaction of its own: on PostgreSQL a failed statement aborts the transaction around it, which would
     * fail the check below when a caller holds one (a savepoint then).
     */
    private static function claim(object $row, int $userId, DateTimeImmutable $now): bool
    {
        $key = ['event_id' => (int) $row->event_id, 'occurrence_start' => $row->starts_at, 'user_id' => $userId];
        try {
            DB::transaction(function () use ($key, $now) {
                DB::table('calendar_reminder_log')->insert($key + ['sent_at' => $now->format('Y-m-d H:i:s')]);
            });
        } catch (QueryException $e) {
            if (!DB::table('calendar_reminder_log')->where($key)->exists()) {
                throw $e; // not a scan that logged it first
            }

            return false;
        }

        return true;
    }
}
