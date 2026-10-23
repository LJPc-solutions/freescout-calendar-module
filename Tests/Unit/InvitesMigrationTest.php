<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Tests\TestCase;

class InvitesMigrationTest extends TestCase
{
    /** MySQL commits the open transaction on DDL, so this class runs without one and removes its own rows. */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        CalendarInvite::where('attachment_id', 900000001)->delete();
        parent::tearDown();
    }

    private function migration()
    {
        if (!class_exists('LjpcCalendarV3Invites', false)) {
            require __DIR__ . '/../../Database/Migrations/2026_10_19_000009_ljpc_calendar_v3_invites.php';
        }

        return new \LjpcCalendarV3Invites();
    }

    /** MariaDB keeps an instantly dropped column in the row format until the table is rebuilt (see SyncColumnsMigrationTest). */
    private static function rebuildEvents(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ' . DB::connection()->getTablePrefix() . 'calendar_events FORCE');
        }
    }

    public function test_runs_down_and_up_again(): void
    {
        $migration = $this->migration();
        try {
            self::rebuildEvents();
            $migration->down();
            $migration->down();
            $this->assertFalse(Schema::hasTable('calendar_invites'));
            $this->assertFalse(Schema::hasColumn('calendar_events', 'attendees'));
            $this->assertFalse(Schema::hasColumn('calendar_events', 'invite_state'));
        } finally {
            $migration->up();
        }
        $migration->up();

        $this->assertTrue(Schema::hasTable('calendar_invites'));
        $this->assertTrue(Schema::hasColumn('calendar_events', 'attendees'));
        $this->assertTrue(Schema::hasColumn('calendar_events', 'invite_state'));
    }

    public function test_one_message_per_attachment_uid_and_occurrence(): void
    {
        $row = ['conversation_id' => 1, 'attachment_id' => 900000001, 'uid' => 'one@example.com', 'method' => 'REQUEST'];
        CalendarInvite::create($row);
        CalendarInvite::create($row + ['recurrence_id' => '20261117T090000Z']);

        $this->expectException(QueryException::class);
        CalendarInvite::create($row);
    }
}
