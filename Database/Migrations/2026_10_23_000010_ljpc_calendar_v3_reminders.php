<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Providers\LJPcCalendarModuleServiceProvider;

/**
 * Reminders (W5): one row per occurrence and person a reminder went to, so it goes once, also when two scans overlap.
 * The occurrence is its starts_at as stored (UTC, or the local date at 00:00 when all-day), so a moved event reminds
 * again. Checked first, so a run that stopped can run again.
 */
class LjpcCalendarV3Reminders extends Migration
{
    public function up()
    {
        // Like 000001: the refusal an old server shows. This file stays parseable on PHP 7.1 (no arrow functions).
        LJPcCalendarModuleServiceProvider::assertPlatformSupported(
            PHP_VERSION_ID,
            \Helper::checkAppVersion(LJPcCalendarModuleServiceProvider::MIN_APP_VERSION)
        );

        if (!Schema::hasTable('calendar_reminder_log')) {
            Schema::create('calendar_reminder_log', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event_id');
                $table->dateTime('occurrence_start');
                $table->unsignedInteger('user_id');
                $table->dateTime('sent_at');
                $table->unique(array('event_id', 'occurrence_start', 'user_id'), 'ljpc_cal_reminders_unique');
                $table->index('occurrence_start', 'ljpc_cal_reminders_start');
                $table->foreign('event_id')->references('id')->on('calendar_events')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('calendar_reminder_log');
    }
}
