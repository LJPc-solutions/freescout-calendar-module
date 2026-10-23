<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Http\SyncReport;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\SyncService;

/**
 * "Sync now" on the calendar page: every external calendar the user can view, within one shared budget. What the
 * budget doesn't reach syncs at the scheduler's next run. Agents never read a calendar's error, only that it failed.
 */
class SyncController extends Controller
{
    public function store(Request $request, SyncService $sync)
    {
        $user = $request->user();
        $budget = SyncBudget::seconds(SyncService::BY_HAND_SECONDS);
        $results = [];
        foreach (CalendarPolicy::viewableCalendars($user) as $calendar) {
            if ($calendar->isLocal()) {
                continue;
            }
            if ($budget->exhausted()) {
                $sync->requestSync($calendar);
                $result = ['state' => 'queued', 'created' => 0, 'updated' => 0, 'deleted' => 0];
            } else {
                $result = $sync->syncByHand($calendar, $budget);
            }
            $error = $user->isAdmin() ? Calendar::whereKey($calendar->id)->value('last_error') : null;
            $results[] = ['id' => $calendar->id, 'name' => $calendar->name] + $result + ['message' => SyncReport::message($result, $error)];
        }

        return response()->json(['calendars' => $results]);
    }
}
