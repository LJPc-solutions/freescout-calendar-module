<?php

use Illuminate\Database\Migrations\Migration;
use Modules\LJPcCalendarModule\Services\MigrationService;

class LjpcCalendarV3ConvertCalendars extends Migration
{
    public function up()
    {
        set_time_limit(0);
        app(MigrationService::class)->convertCalendars();
    }

    public function down()
    {
    }
}
