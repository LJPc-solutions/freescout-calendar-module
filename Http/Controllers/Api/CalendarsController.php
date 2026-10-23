<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Http\EventPresenter;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;

class CalendarsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json(CalendarPolicy::viewableCalendars($user)->map(function (Calendar $calendar) use ($user) {
            return EventPresenter::calendar($calendar, $user);
        })->values());
    }
}
