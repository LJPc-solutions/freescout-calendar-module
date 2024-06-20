<?php

namespace Modules\LJPcCalendarModule\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\LJPcCalendarModule\Entities\Calendar;

class UpdateExternalCalendarJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $calendarId;

    public function __construct( int $calendarId ) {
        $this->calendarId = $calendarId;
    }

    public function handle(): void {
        $calendar = Calendar::find( $this->calendarId );
        if ( $calendar === null ) {
            return;
        }
        if ( $calendar->type !== 'ics' && $calendar->type !== 'caldav' ) {
            return;
        }

        $calendar->getExternalContent( true );

        $customFields                = $calendar->custom_fields;
        $customFields['last_update'] = time();
        $calendar->custom_fields     = $customFields;
        $calendar->save();
    }
}
