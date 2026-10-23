<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Tests\TestCase;

class RemindersMigrationTest extends TestCase
{
    /** MySQL commits the open transaction on DDL, so this class runs without one. */
    protected $connectionsToTransact = [];

    public function test_runs_down_and_up_again(): void
    {
        if (!class_exists('LjpcCalendarV3Reminders', false)) {
            require __DIR__ . '/../../Database/Migrations/2026_10_23_000010_ljpc_calendar_v3_reminders.php';
        }
        $migration = new \LjpcCalendarV3Reminders();
        try {
            $migration->down();
            $migration->down();
            $this->assertFalse(Schema::hasTable('calendar_reminder_log'));
        } finally {
            $migration->up();
        }
        $migration->up();

        $this->assertTrue(Schema::hasTable('calendar_reminder_log'));
    }
}
