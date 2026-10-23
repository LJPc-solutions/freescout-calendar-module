<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Columns of the sync engine. Every step checks first, so a migration that failed half-way can simply run again. */
class LjpcCalendarV3SyncColumns extends Migration
{
    const REMOTE_UNIQUE = 'ljpc_cal_events_remote_unique';

    public function up()
    {
        if (!Schema::hasColumn('calendars', 'sync_failures')) {
            Schema::table('calendars', function (Blueprint $table) {
                $table->unsignedInteger('sync_failures')->default(0);
            });
        }
        if (!Schema::hasColumn('calendar_events', 'sync_generation')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->unsignedInteger('sync_generation')->default(0);
            });
        }
        if (!Schema::hasColumn('calendar_events', 'remote_id_hash')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->char('remote_id_hash', 40)->nullable();
            });
        }
        // Checked on its own: the column and the index are two statements, and a run that stopped between them left the
        // column without it. Created before the backfill, while the hashes are still NULL, so a first run can't fail here.
        if (!self::hasIndex('calendar_events', self::REMOTE_UNIQUE)) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->unique(['calendar_id', 'remote_id_hash'], self::REMOTE_UNIQUE);
            });
        }

        DB::table('calendar_events')
            ->whereNotNull('remote_id')
            ->whereNull('remote_id_hash')
            ->select(['id', 'remote_id'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('calendar_events')->where('id', $row->id)->update(['remote_id_hash' => sha1($row->remote_id)]);
                }
            });
    }

    public function down()
    {
        // The index goes first: MySQL would otherwise keep it as a unique index on calendar_id alone.
        if (self::hasIndex('calendar_events', self::REMOTE_UNIQUE)) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->dropUnique(self::REMOTE_UNIQUE);
            });
        }
        if (Schema::hasColumn('calendar_events', 'remote_id_hash')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->dropColumn('remote_id_hash');
            });
        }
        if (Schema::hasColumn('calendar_events', 'sync_generation')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->dropColumn('sync_generation');
            });
        }
        if (Schema::hasColumn('calendars', 'sync_failures')) {
            Schema::table('calendars', function (Blueprint $table) {
                $table->dropColumn('sync_failures');
            });
        }
    }

    /** Laravel 5.5 has no Schema::hasIndex(); Doctrine's schema manager (core ships doctrine/dbal) lists them on every database. */
    private static function hasIndex($table, $index)
    {
        $connection = Schema::getConnection();
        $indexes = $connection->getDoctrineSchemaManager()->listTableIndexes($connection->getTablePrefix() . $table);

        return array_key_exists(strtolower($index), $indexes);
    }
}
