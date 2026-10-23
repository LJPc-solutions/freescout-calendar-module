<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Http\CalendarForm;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Http\SyncReport;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\SyncService;

/**
 * Form actions of Settings > Calendar. Each one redirects to a settings page with a flash message or the errors; the
 * settings script also calls sync and status for JSON, to show a calendar's first sync live.
 */
class CalendarsController extends Controller
{
    public function store(Request $request)
    {
        $data = CalendarForm::validateNew($request);
        if ($data['provider'] === Calendar::PROVIDER_ICS) {
            $data['next_sync_at'] = gmdate('Y-m-d H:i:s'); // without the page's script, the next run of calendar:sync reads the feed
        }
        $calendar = Calendar::create($data);
        $back = redirect(SettingsPage::url($calendar->id));
        if ($calendar->isLocal()) {
            return $back->with('flash_success_floating', __('Calendar created. Choose who can use it below.'));
        }

        return $back->with('ljpccalendarmodule.sync_start', [$calendar->id])
            ->with('flash_success_floating', __('Calendar created. Its events are being read now. Choose who can use it below.'));
    }

    public function update(Request $request, OccurrenceService $occurrences, SyncService $sync, $id)
    {
        $calendar = Calendar::findOrFail($id);
        self::assertConverted($calendar);
        $data = CalendarForm::validate($request, $calendar);
        $zone = $calendar->timezoneName();
        $address = $calendar->remote_id;
        $interval = $calendar->sync_interval;
        DB::transaction(function () use ($calendar, $data, $zone, $occurrences) {
            $calendar->update(array_diff_key($data, ['permissions' => true]));
            CalendarForm::syncPermissions($calendar, $data['permissions']);
            // Floating events are read in the calendar's zone, so their times move with it.
            if ($calendar->timezoneName() !== $zone) {
                $occurrences->refreshFloating($calendar);
            }
        });
        // A feed at a new address is listed in full: the old feed's ETag and hash must not answer for it.
        if ($calendar->remote_id !== $address) {
            $sync->relist($calendar);
        } elseif ($calendar->sync_interval !== $interval) {
            $sync->requestSync($calendar);
        }

        return redirect(SettingsPage::url($calendar->id))->with('flash_success_floating', __('Calendar saved.'));
    }

    /** "Sync now": the next run of calendar:sync, within a minute, syncs this calendar. */
    /** "Sync now": syncs right away and says what changed. For JSON (the page's own script) it answers with the card's status. */
    public function sync(Request $request, SyncService $sync, $id)
    {
        $calendar = Calendar::findOrFail($id);
        abort_if($calendar->isLocal(), 404);
        $result = $sync->syncByHand($calendar, SyncBudget::seconds(SyncService::BY_HAND_SECONDS));
        $calendar = Calendar::with('calendarConnection')->findOrFail($id);
        $message = SyncReport::message($result, $calendar->last_error);
        if ($request->wantsJson()) {
            return response()->json(self::syncStatus($calendar) + ['state' => $result['state'], 'message' => $message]);
        }
        $back = redirect($request->input('back') === 'list' ? SettingsPage::url() : SettingsPage::url($calendar->id));

        // Core prints floating flashes as HTML, and the message may hold the provider's error.
        return $back->with(SyncReport::isError($result) ? 'flash_error_floating' : 'flash_success_floating', e($message));
    }

    /** The status of the calendars a page shows as syncing: {calendars: {id: {syncing, html}}}. */
    public function status(Request $request)
    {
        $ids = array_slice(array_filter(array_map('intval', (array) $request->query('ids', []))), 0, 100);
        $calendars = Calendar::with('calendarConnection')->whereIn('id', $ids)->get();
        $statuses = [];
        foreach ($calendars as $calendar) {
            $statuses[$calendar->id] = self::syncStatus($calendar);
        }

        return response()->json(['calendars' => (object) $statuses]);
    }

    private static function syncStatus(Calendar $calendar): array
    {
        return [
            'syncing' => SettingsPage::isSyncing($calendar),
            'html'    => trim(view('calendar::settings.card_status', ['item' => $calendar])->render()),
        ];
    }

    public function destroy($id)
    {
        Calendar::findOrFail($id)->delete();

        return redirect(SettingsPage::url())->with('flash_success_floating', __('Calendar deleted.'));
    }

    public function regenerateToken(Request $request, $id)
    {
        $request->validate(['which' => 'required|in:full,busy']);
        $calendar = Calendar::findOrFail($id);
        self::assertConverted($calendar);
        $column = $request->input('which') === 'full' ? 'export_token' : 'export_busy_token';
        $calendar->update([$column => Calendar::newToken()]);

        return redirect(SettingsPage::url($calendar->id))->with('flash_success_floating', __('A new link was made. The old link no longer works.'));
    }

    public function skipItem(Request $request, MigrationService $migration, $id)
    {
        $migration->skipItem((int) $id, $request->user()->getFullName());

        return redirect(SettingsPage::url())->with('flash_success_floating', __('The event was skipped. Its data stays in the report.'));
    }

    /**
     * export_token is the v2 conversion's done-marker. A token or an edit on a calendar whose conversion failed would
     * stop the upgrade from retrying it, or be overwritten by that retry.
     */
    private static function assertConverted(Calendar $calendar): void
    {
        if ($calendar->export_token === null) {
            throw ValidationException::withMessages(['calendar' => __('This calendar has not been upgraded to version 3 yet. See the upgrade report above, then run the upgrade again.')]);
        }
    }
}
