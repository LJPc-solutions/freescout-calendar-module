<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

/**
 * The cleanup migration must refuse (before any DDL) while anything is unconverted. There is deliberately no test that
 * lets up() succeed: it would drop the v2 tables from the test database.
 */
class MigrationCleanupGuardTest extends TestCase
{
    private function cleanup()
    {
        if (!class_exists('LjpcCalendarV3CleanupV2', false)) {
            require __DIR__ . '/../../Database/Migrations/2026_10_05_000006_ljpc_calendar_v3_cleanup_v2.php';
        }

        return new \LjpcCalendarV3CleanupV2();
    }

    private function assertRefusedAndV2DataKept(): void
    {
        try {
            $this->cleanup()->up();
            $this->fail('The cleanup migration should have refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('The calendar upgrade is incomplete', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('calendar_items'));
        $this->assertTrue(Schema::hasTable('calendar_event_index'));
        foreach (['type', 'permissions', 'custom_fields'] as $column) {
            $this->assertTrue(Schema::hasColumn('calendars', $column), $column);
        }
    }

    private function v2Calendar(array $attributes): int
    {
        return DB::table('calendars')->insertGetId($attributes + [
            'name' => 'Guard ' . uniqid(), 'enabled' => 1, 'color' => '#3498db', 'type' => 'normal',
            'created_at' => '2024-05-01 10:00:00', 'updated_at' => '2024-05-01 10:00:00',
        ]);
    }

    public function test_refuses_while_a_calendar_cannot_be_converted(): void
    {
        // Deterministic failure: the CalDAV connection insert succeeds, then a model event throws inside the calendar's transaction.
        CalendarConnection::created(function (CalendarConnection $connection) {
            if ($connection->account === 'explode') {
                throw new \RuntimeException('Simulated failure');
            }
        });
        try {
            $id = $this->v2Calendar(['type' => 'caldav', 'custom_fields' => json_encode(['url' => 'https://dav.example.com/', 'username' => 'explode', 'password' => 'x'])]);
            $this->assertRefusedAndV2DataKept();
        } finally {
            CalendarConnection::flushEventListeners();
        }
        $this->assertNull(DB::table('calendars')->where('id', $id)->value('export_token'));
    }

    public function test_refuses_while_legacy_permissions_could_not_be_converted(): void
    {
        $user = $this->makeUser();
        $calendar = Calendar::create(['name' => 'Perm ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        DB::table('calendars')->where('id', $calendar->id)->update(['permissions' => json_encode([(string) $user->id => ['showInCalendar' => true]])]);
        // An existing row makes the conversion hit the unique key inside its transaction.
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true]);

        $this->assertRefusedAndV2DataKept();
        $this->assertNotNull(DB::table('calendars')->where('id', $calendar->id)->value('permissions'));
    }

    public function test_refuses_while_an_item_fails_to_convert_and_converts_it_once_the_cause_is_gone(): void
    {
        $calendar = Calendar::create(['name' => 'Items ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $id = DB::table('calendar_items')->insertGetId([
            'calendar_id' => $calendar->id, 'author_id' => 1, 'is_all_day' => 0, 'is_private' => 0, 'is_read_only' => 0,
            'title' => 'Explodes', 'body' => '', 'state' => 'published', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00',
        ]);
        $occurrences = new class extends OccurrenceService {
            public bool $fail = true;

            public function refresh(CalendarEvent $event): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('forced failure');
                }
                parent::refresh($event);
            }
        };
        $this->app->instance(OccurrenceService::class, $occurrences);

        $this->assertRefusedAndV2DataKept();
        $this->assertSame([], app(MigrationService::class)->skippedItemIds());

        // Self-healing: the next cleanup run retries the item. Verified through the same steps up() starts with.
        $occurrences->fail = false;
        app(MigrationService::class)->convertItems();
        $this->assertSame(1, CalendarEvent::where('legacy_id', (string) $id)->count());
        $this->assertTrue(app(MigrationService::class)->isComplete());
    }
}
