<?php

use Illuminate\Database\Migrations\Migration;
use Modules\LJPcCalendarModule\Services\MigrationService;

class LjpcCalendarV3ScheduleResync extends Migration
{
    public function up()
    {
        app(MigrationService::class)->scheduleExternalResync();
    }

    public function down()
    {
    }
}
