<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Tests\TestCase;

class RemoteReadOnlyMigrationTest extends TestCase
{
    /** MySQL commits the open transaction on DDL, so this class runs without one and removes its own rows. */
    protected $connectionsToTransact = [];

    private ?int $calendarId = null;

    protected function tearDown(): void
    {
        if ($this->calendarId !== null) {
            Calendar::where('id', $this->calendarId)->delete();
        }
        parent::tearDown();
    }

    private function migration()
    {
        if (!class_exists('LjpcCalendarV3RemoteReadOnly', false)) {
            require __DIR__ . '/../../Database/Migrations/2026_10_07_000008_ljpc_calendar_v3_remote_read_only.php';
        }

        return new \LjpcCalendarV3RemoteReadOnly();
    }

    /** MariaDB keeps an instantly dropped column in the row format until the table is rebuilt (see SyncColumnsMigrationTest). */
    private static function rebuildCalendars(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ' . DB::connection()->getTablePrefix() . 'calendars FORCE');
        }
    }

    public function test_runs_down_and_up_again_and_leaves_existing_calendars_writable(): void
    {
        $migration = $this->migration();
        try {
            self::rebuildCalendars();
            $migration->down();
            $migration->down();
            $this->assertFalse(Schema::hasColumn('calendars', 'remote_read_only'));
            $this->calendarId = Calendar::create(['name' => 'Before ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV])->id;
        } finally {
            $migration->up();
        }
        $migration->up();

        $this->assertTrue(Schema::hasColumn('calendars', 'remote_read_only'));
        $this->assertSame(0, (int) DB::table('calendars')->where('id', $this->calendarId)->value('remote_read_only'));
        $this->assertFalse((bool) Calendar::find($this->calendarId)->remote_read_only);
    }
}
