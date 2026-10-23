<?php

use Illuminate\Database\Migrations\Migration;
use Modules\LJPcCalendarModule\Services\MigrationService;

class LjpcCalendarV3ConvertItems extends Migration
{
    public function up()
    {
        set_time_limit(0);
        app(MigrationService::class)->convertItems();
    }

    public function down()
    {
    }
}
