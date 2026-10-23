<?php

namespace Modules\LJPcCalendarModule\Services;

use App\User;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\View;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;

/** The next three occurrences within seven days, read from the database only (no remote calls). */
class DashboardCard
{
    private OccurrenceService $occurrences;

    public function __construct(OccurrenceService $occurrences)
    {
        $this->occurrences = $occurrences;
    }

    public function render(User $user): string
    {
        $calendars = Calendar::where('enabled', true)->with('permissionRows')->get()
            ->filter(function (Calendar $calendar) use ($user) {
                return CalendarPolicy::rights($user, $calendar)['show_in_dashboard'];
            })
            ->keyBy('id');
        if ($calendars->isEmpty()) {
            return '';
        }

        $timezone = TimeInput::userTimezone($user);
        $tz = new DateTimeZone($timezone);
        $now = new DateTimeImmutable('now', $tz);
        $rows = $this->occurrences->between($calendars->keys()->all(), $now, $now->modify('+7 days'), $timezone);
        usort($rows, function (array $a, array $b) use ($tz) {
            return self::startsAt($a, $tz) <=> self::startsAt($b, $tz);
        });
        $rows = array_slice($rows, 0, 3);
        if (!$rows) {
            return '';
        }

        $events = CalendarEvent::whereIn('id', array_column($rows, 'event_id'))->get(['id', 'summary'])->keyBy('id');
        $items = [];
        foreach ($rows as $row) {
            if (!isset($events[$row['event_id']])) {
                continue;
            }
            $start = $row['all_day']
                ? new DateTimeImmutable(substr($row['starts_at'], 0, 10), $tz)
                : (new DateTimeImmutable($row['starts_at'], new DateTimeZone('UTC')))->setTimezone($tz);
            $calendar = $calendars[$row['calendar_id']];
            $items[] = [
                // The calendar page's permalink: an occurrence of a repeating event carries its recurrence key.
                'url'      => CalendarEvent::permalink((int) $row['event_id'], $row['recurrence_id']),
                // The Dashboard right doesn't imply View, and the calendar page only opens events the agent may view.
                'linkable' => $user->can('view', $calendar),
                'title'    => $row['summary'] ?? $events[$row['event_id']]->summary,
                'start'    => [
                    'time' => $row['all_day'] ? __('All day') : $start->format(TimeInput::userClock($user, 'H:i')),
                    'diff' => Carbon::createFromTimestamp($start->getTimestamp(), $timezone)->diffForHumans(),
                    'full' => $start->format(TimeInput::userClock($user, __('Y-m-d H:i'))),
                ],
                'calendar' => ['id' => $calendar->id, 'name' => $calendar->name, 'color' => \Modules\LJPcCalendarModule\Http\SettingsPage::color($calendar->color)],
            ];
        }

        return View::make('calendar::partials/dash_card', ['events' => $items])->render();
    }

    /** All-day rows hold a local date at 00:00 and timed rows a UTC time; both become a moment to compare. */
    private static function startsAt(array $row, DateTimeZone $tz): int
    {
        return $row['all_day']
            ? (new DateTimeImmutable(substr($row['starts_at'], 0, 10), $tz))->getTimestamp()
            : (new DateTimeImmutable($row['starts_at'], new DateTimeZone('UTC')))->getTimestamp();
    }
}
