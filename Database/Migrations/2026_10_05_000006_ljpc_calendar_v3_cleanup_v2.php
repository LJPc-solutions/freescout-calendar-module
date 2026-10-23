<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Services\MigrationService;

class LjpcCalendarV3CleanupV2 extends Migration
{
    private const LEGACY_COLUMNS = ['type', 'permissions', 'custom_fields', 'sync_version', 'last_full_sync', 'force_legacy_mode'];

    public function up()
    {
        set_time_limit(0); // the self-heal below may repeat the full conversion, also from a web request (module updater)
        $service = app(MigrationService::class);
        // Self-heal first: the steps are idempotent. They convert items whose converted event was deleted in the
        // meantime (for example with its calendar, while the API was gated) and retry earlier retryable failures.
        $service->convertCalendars();
        $service->convertPermissions();
        $service->convertItems();
        if (!$service->isComplete()) {
            throw new RuntimeException('The calendar upgrade is incomplete, so the version 2 data was kept. See Settings > Calendar for the report, then run the module migrations again.');
        }

        Schema::dropIfExists('calendar_event_index');
        Schema::dropIfExists('calendar_items');
        foreach (self::LEGACY_COLUMNS as $column) {
            if (Schema::hasColumn('calendars', $column)) {
                Schema::table('calendars', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
        // Removes the v2 ICS feed cache files.
        File::deleteDirectory(storage_path('app/ljpccalendarmodule'));

        $service->finish();
    }

    /** Recreates the empty v2 schema. Only used to build the module's test database (Tests/setup-test-db.sh). */
    public function down()
    {
        if (!Schema::hasTable('calendar_items')) {
            Schema::create('calendar_items', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('calendar_id');
                $table->integer('author_id');
                $table->boolean('is_all_day');
                $table->boolean('is_private');
                $table->boolean('is_read_only')->default(false);
                $table->string('title');
                $table->text('body');
                $table->string('state');
                $table->string('location')->nullable();
                $table->dateTime('start');
                $table->dateTime('end');
                $table->json('custom_fields')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('calendar_event_index')) {
            Schema::create('calendar_event_index', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('calendar_id');
                $table->string('event_uid');
                $table->json('event_data');
                $table->timestamps();
            });
        }
        $columns = [
            'type'              => function (Blueprint $t) { $t->string('type')->default('normal'); },
            'permissions'       => function (Blueprint $t) { $t->json('permissions')->nullable(); },
            'custom_fields'     => function (Blueprint $t) { $t->json('custom_fields')->nullable(); },
            'sync_version'      => function (Blueprint $t) { $t->unsignedInteger('sync_version')->default(0); },
            'last_full_sync'    => function (Blueprint $t) { $t->timestamp('last_full_sync')->nullable(); },
            'force_legacy_mode' => function (Blueprint $t) { $t->boolean('force_legacy_mode')->default(false); },
        ];
        foreach ($columns as $name => $define) {
            if (!Schema::hasColumn('calendars', $name)) {
                Schema::table('calendars', $define);
            }
        }
    }
}
