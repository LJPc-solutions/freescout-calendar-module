<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSyncVersionToCalendarsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('calendars', function (Blueprint $table) {
            // Track sync version for change detection
            $table->unsignedInteger('sync_version')->default(0)->after('custom_fields');
            
            // Track last sync time for cache invalidation
            $table->timestamp('last_full_sync')->nullable()->after('sync_version');
            
            // Add force_legacy_mode per calendar
            $table->boolean('force_legacy_mode')->default(false)->after('last_full_sync');
            
            // Add index for sync tracking
            $table->index('sync_version');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('calendars', function (Blueprint $table) {
            $table->dropIndex(['sync_version']);
            $table->dropColumn(['sync_version', 'last_full_sync', 'force_legacy_mode']);
        });
    }
}
