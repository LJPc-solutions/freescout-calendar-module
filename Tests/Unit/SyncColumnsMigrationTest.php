<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Tests\TestCase;

class SyncColumnsMigrationTest extends TestCase
{
    /** MySQL commits the open transaction on DDL, so this class runs without one and removes its own rows. */
    protected $connectionsToTransact = [];

    private ?int $calendarId = null;

    protected function tearDown(): void
    {
        if ($this->calendarId !== null) {
            Calendar::where('id', $this->calendarId)->delete(); // events cascade
        }
        parent::tearDown();
    }

    private function migration()
    {
        if (!class_exists('LjpcCalendarV3SyncColumns', false)) {
            require __DIR__ . '/../../Database/Migrations/2026_10_07_000007_ljpc_calendar_v3_sync_columns.php';
        }

        return new \LjpcCalendarV3SyncColumns();
    }

    /**
     * MariaDB drops and adds columns instantly (innodb_instant_alter_column_allowed=add_drop_reorder): a dropped column
     * stays in the row format and counts toward the 8126-byte row limit until the table is rebuilt. Each run of these
     * tests drops and adds remote_id_hash and sync_generation, so after about fifteen runs dropping a column failed with
     * "Row size too large". The touched tables are rebuilt before every down(); other databases rebuild as they go.
     */
    private static function rebuildTables(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        foreach (['calendars', 'calendar_events'] as $table) {
            // The schema builder has no rebuild; this is test-only DDL.
            DB::statement('ALTER TABLE ' . DB::connection()->getTablePrefix() . $table . ' FORCE');
        }
    }

    private static function hasRemoteIndex(): bool
    {
        $indexes = DB::connection()->getDoctrineSchemaManager()->listTableIndexes(DB::connection()->getTablePrefix() . 'calendar_events');

        return isset($indexes['ljpc_cal_events_remote_unique']);
    }

    private function insertEvent(string $uid, ?string $remoteId): void
    {
        DB::table('calendar_events')->insert([
            'calendar_id' => $this->calendarId,
            'uid'         => $uid,
            'uid_hash'    => sha1($uid),
            'ics'         => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
            'remote_id'   => $remoteId,
            'starts_at'   => '2026-10-20 08:00:00',
            'ends_at'     => '2026-10-20 09:00:00',
            'created_at'  => '2026-10-07 10:00:00',
            'updated_at'  => '2026-10-07 10:00:00',
        ]);
    }

    public function test_runs_down_and_up_again_and_backfills_remote_id_hashes(): void
    {
        $migration = $this->migration();
        try {
            self::rebuildTables();
            $migration->down();
            $migration->down();
            $this->assertFalse(Schema::hasColumn('calendars', 'sync_failures'));
            $this->assertFalse(Schema::hasColumn('calendar_events', 'remote_id_hash'));
            $this->assertFalse(Schema::hasColumn('calendar_events', 'sync_generation'));

            // Rows written before the migration: one with a remote id, one without.
            $this->calendarId = Calendar::create(['name' => 'Backfill ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV])->id;
            $this->insertEvent('a@example.com', 'https://dav.example.com/calendars/support/a.ics');
            $this->insertEvent('b@example.com', null);
        } finally {
            $migration->up();
        }
        $migration->up();

        $this->assertTrue(Schema::hasColumn('calendars', 'sync_failures'));
        $this->assertSame(0, (int) DB::table('calendars')->where('id', $this->calendarId)->value('sync_failures'));
        $hashes = DB::table('calendar_events')->where('calendar_id', $this->calendarId)->orderBy('uid')->pluck('remote_id_hash')->all();
        $this->assertSame([sha1('https://dav.example.com/calendars/support/a.ics'), null], $hashes);
        $this->assertSame([0, 0], array_map('intval', DB::table('calendar_events')->where('calendar_id', $this->calendarId)->pluck('sync_generation')->all()));

        $this->expectException(QueryException::class); // the remote id is unique per calendar
        $this->insertEvent('c@example.com', 'https://dav.example.com/calendars/support/a.ics');
        DB::table('calendar_events')->where('uid', 'c@example.com')->update(['remote_id_hash' => sha1('https://dav.example.com/calendars/support/a.ics')]);
    }

    public function test_a_column_left_without_its_index_gets_it_on_the_next_run(): void
    {
        $migration = $this->migration();
        try {
            // What a run that stopped between the column and the index leaves behind.
            Schema::table('calendar_events', function ($table) {
                $table->dropUnique('ljpc_cal_events_remote_unique');
            });
            $this->assertTrue(Schema::hasColumn('calendar_events', 'remote_id_hash'));
            $this->assertFalse(self::hasRemoteIndex());

            $migration->up();

            $this->assertTrue(self::hasRemoteIndex());
            self::rebuildTables();
            $migration->down(); // dropping the index first, then the column, also when it is there
            $migration->down();
            $this->assertFalse(Schema::hasColumn('calendar_events', 'remote_id_hash'));
        } finally {
            $migration->up();
        }
        $this->assertTrue(self::hasRemoteIndex());
        $this->assertTrue(Schema::hasColumn('calendar_events', 'remote_id_hash'));
    }
}
