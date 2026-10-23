<?php

namespace Modules\LJPcCalendarModule\Console;

use Illuminate\Console\Command;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Services\HealthService;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\ReminderService;
use Modules\LJPcCalendarModule\Services\SyncService;

class SyncCalendars extends Command
{
    protected $signature = 'calendar:sync
        {--force : Also sync calendars that are not due yet}
        {--calendar= : Only the calendar with this id}';

    protected $description = 'Queues the reminders that are due, pulls changes from ICS feeds and connected calendars that are due, then checks the connection health';

    public function handle(SyncService $sync, MigrationService $migration, HealthService $health, ReminderService $reminders)
    {
        if (!$migration->isFinished()) {
            $this->line('The calendar upgrade to version 3 has not finished, so no calendars were synced.');

            return 0;
        }
        $calendarId = $this->option('calendar');
        if ($calendarId !== null && !ctype_digit((string) $calendarId)) {
            $this->error('--calendar takes the id of a calendar.');

            return 1;
        }

        // Before the sync, which may take its whole budget, so reminders go out on the minute.
        $this->info(sprintf('Queued %d reminders.', $reminders->scan()));
        $counts = $sync->runDue(SyncBudget::seconds(SyncService::RUN_SECONDS), $calendarId !== null ? (int) $calendarId : null, (bool) $this->option('force'));
        $this->info(sprintf('Synced %d calendars, %d failed, %d skipped because another run holds them.', $counts['synced'], $counts['failed'], $counts['skipped']));
        // After the sync, so a calendar that failed in this run counts; at most every HealthService::CHECK_MINUTES.
        $health->checkIfDue();

        return 0;
    }
}
