<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Providers\LJPcCalendarModuleServiceProvider;

/**
 * Marks a calendar the connected account may only read (CalDAV privileges, Google's reader roles, Graph's canEdit),
 * so FreeScout never offers a write the provider refuses. Checked first, so a run that stopped can run again.
 */
class LjpcCalendarV3RemoteReadOnly extends Migration
{
    public function up()
    {
        // Like 000001: the refusal an old server shows. This file stays parseable on PHP 7.1 (no arrow functions).
        LJPcCalendarModuleServiceProvider::assertPlatformSupported(
            PHP_VERSION_ID,
            \Helper::checkAppVersion(LJPcCalendarModuleServiceProvider::MIN_APP_VERSION)
        );

        if (!Schema::hasColumn('calendars', 'remote_read_only')) {
            Schema::table('calendars', function (Blueprint $table) {
                $table->boolean('remote_read_only')->default(false);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('calendars', 'remote_read_only')) {
            Schema::table('calendars', function (Blueprint $table) {
                $table->dropColumn('remote_read_only');
            });
        }
    }
}
