<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Jobs\SendAlert;
use App\Option;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Services\HealthService;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\TestCase;

class SyncCommandTest extends TestCase
{
    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeProvider(false);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_ICS, $this->provider);
    }

    private function feed(array $attributes = []): Calendar
    {
        return Calendar::create($attributes + ['name' => 'Feed ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS, 'remote_id' => 'https://calendar.example.com/feed.ics']);
    }

    public function test_syncs_the_due_calendars_and_prints_the_counts(): void
    {
        $this->feed();
        $this->provider->onPull(new ChangeSet([], [], [], true, false));

        $this->assertSame(0, Artisan::call('calendar:sync'));
        $this->assertStringContainsString('Synced 1 calendars, 0 failed, 0 skipped because another run holds them.', Artisan::output());
    }

    public function test_waits_until_the_upgrade_from_version_2_has_finished(): void
    {
        Option::set(MigrationService::STATE_OPTION, 'pending');
        $this->feed();

        $this->assertSame(0, Artisan::call('calendar:sync'));
        $this->assertStringContainsString('The calendar upgrade to version 3 has not finished, so no calendars were synced.', Artisan::output());
        $this->assertSame([], $this->provider->calls);
    }

    public function test_calendar_and_force_pick_one_calendar_that_is_not_due(): void
    {
        $later = $this->feed(['next_sync_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $this->feed();
        $this->provider->onPull(new ChangeSet([], [], [], true, false));

        Artisan::call('calendar:sync', ['--calendar' => $later->id]);
        $this->assertStringContainsString('Synced 0 calendars', Artisan::output());

        Artisan::call('calendar:sync', ['--calendar' => $later->id, '--force' => true]);
        $this->assertStringContainsString('Synced 1 calendars', Artisan::output());
        $this->assertSame([$later->id], array_column($this->provider->calls('pull'), 'calendar_id'));

        $this->assertSame(1, Artisan::call('calendar:sync', ['--calendar' => 'support']));
    }

    public function test_checks_the_health_after_the_sync_at_most_every_15_minutes(): void
    {
        Bus::fake();
        $feed = $this->feed(['last_synced_at' => gmdate('Y-m-d H:i:s', time() - 25 * 3600)]);
        $this->provider->onPull(new ProviderUnavailable('The feed answered with an error.', 500), new ChangeSet([], [], [], true, false));

        Artisan::call('calendar:sync');

        // The failure of this very run is what the check finds.
        $this->assertSame('The feed answered with an error. (HTTP 500)', $feed->fresh()->last_error);
        $this->assertSame(['Calendar: a calendar stopped syncing'], Bus::dispatched(SendAlert::class)->pluck('title')->all());
        $checked = Option::get(HealthService::OPTION, [], true, false)['checked_at'];

        Artisan::call('calendar:sync', ['--calendar' => $feed->id, '--force' => true]);
        $this->assertNull($feed->fresh()->last_error);
        $this->assertSame($checked, Option::get(HealthService::OPTION, [], true, false)['checked_at']);
        $this->assertCount(1, Bus::dispatched(SendAlert::class));
    }

    public function test_no_health_check_before_the_upgrade_from_version_2_has_finished(): void
    {
        Option::set(MigrationService::STATE_OPTION, 'pending');

        Artisan::call('calendar:sync');

        $this->assertSame([], Option::get(HealthService::OPTION, [], true, false));
    }

    public function test_runs_every_minute_in_the_background_from_freescouts_schedule(): void
    {
        $schedule = \Eventy::filter('schedule', new Schedule());

        $events = array_values(array_filter($schedule->events(), function ($event) {
            return str_contains((string) $event->command, 'calendar:sync');
        }));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(5, $events[0]->expiresAt);
        $this->assertTrue($events[0]->runInBackground);
    }
}
