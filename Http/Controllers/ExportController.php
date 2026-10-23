<?php

namespace Modules\LJPcCalendarModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Services\ExportService;

class ExportController extends Controller
{
    public function show(ExportService $export, $id, $token)
    {
        $calendar = Calendar::find($id);
        $token = (string) $token;
        $full = $calendar && $calendar->enabled && $calendar->export_token && hash_equals($calendar->export_token, $token);
        $busy = $calendar && $calendar->enabled && !$full && $calendar->export_busy_token && hash_equals($calendar->export_busy_token, $token);
        abort_unless($full || $busy, 404);

        return response($export->render($calendar, $busy), 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="calendar-' . $calendar->id . '.ics"',
            'Cache-Control'       => 'private, max-age=300',
        ]);
    }
}
