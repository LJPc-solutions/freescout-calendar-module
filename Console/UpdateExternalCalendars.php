<?php

namespace Modules\LJPcCalendarModule\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Jobs\UpdateExternalCalendarJob;

class UpdateExternalCalendars extends Command {
    private const REFRESH_INTERVALS = [
        '1 minute'   => 60,
        '5 minutes'  => 300,
        '15 minutes' => 900,
        '30 minutes' => 1800,
        '1 hour'     => 3600,
        '2 hours'    => 7200,
        '6 hours'    => 21600,
        '12 hours'   => 43200,
        'daily'      => 86400,
    ];
    /**
     * The signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:update-external-calendars {--force}';
    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Update external calendars from ICS and CalDAV sources.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle() {
        date_default_timezone_set( Config::get( 'app.timezone' ) );

        $force = $this->option( 'force' );

        $calendars = Calendar::all();
        foreach ( $calendars as $calendar ) {
            if ( $calendar->type !== 'ics' && $calendar->type !== 'caldav' ) {
                continue;
            }

            $customFields = $calendar->custom_fields;

            $refresh = $customFields['refresh'] ?? null;
            if ( $refresh === null && ! $force ) {
                continue;
            }
            $interval   = self::REFRESH_INTERVALS[ $refresh ] ?? 86400;
            $lastUpdate = $customFields['last_update'] ?? 0;
            if ( ! $force && time() - $lastUpdate < $interval ) {
                continue;
            }

            UpdateExternalCalendarJob::dispatch( $calendar->id );
        }
    }
}
