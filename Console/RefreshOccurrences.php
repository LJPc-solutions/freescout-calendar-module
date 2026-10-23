<?php

namespace Modules\LJPcCalendarModule\Console;

use Illuminate\Console\Command;
use Modules\LJPcCalendarModule\Services\OccurrenceService;

class RefreshOccurrences extends Command
{
    protected $signature = 'calendar:refresh-occurrences';

    protected $description = 'Moves the calendar occurrence window and re-expands recurring events';

    public function handle(OccurrenceService $occurrences)
    {
        $occurrences->moveWindow();
        $count = $occurrences->refreshRecurring();
        $this->info('Refreshed ' . $count . ' recurring events.');
    }
}
