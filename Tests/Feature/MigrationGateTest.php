<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Option;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class MigrationGateTest extends TestCase
{
    public function test_completeness_tracks_unconverted_items(): void
    {
        $service = app(MigrationService::class);
        $calendar = Calendar::create(['name' => 'Gate ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        DB::table('calendar_items')->insert([
            'calendar_id' => $calendar->id, 'author_id' => 1, 'is_all_day' => 0, 'is_private' => 0, 'is_read_only' => 0,
            'title' => 'Pending', 'body' => '', 'state' => 'published', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00',
        ]);

        $this->assertFalse($service->isComplete());
        $service->convertItems();
        $this->assertTrue($service->isComplete());
    }

    public function test_agent_api_is_gated_until_the_migration_is_finished_but_the_page_is_not(): void
    {
        $user = $this->makeUser();
        Option::set(MigrationService::STATE_OPTION, 'pending');

        $this->actingAs($user)->json('GET', '/calendar/api/calendars')->assertStatus(503);
        $page = $this->actingAs($user)->get('/calendar')->assertStatus(200);
        $this->assertStringContainsString('migration_complete":false', $page->getContent());

        app(MigrationService::class)->finish();
        $this->actingAs($user)->json('GET', '/calendar/api/calendars')->assertStatus(200);
    }

    public function test_finishing_marks_the_upgrade_finished_and_reports_it(): void
    {
        $service = app(MigrationService::class);
        $service->finish();

        $report = $service->report();
        $this->assertTrue($service->isFinished());
        $this->assertSame('The upgrade to version 3 is complete.', end($report)['message']);
    }
}
