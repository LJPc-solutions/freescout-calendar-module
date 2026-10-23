<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Option;
use Illuminate\Support\Facades\Bus;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Http\EventPresenter;
use Modules\LJPcCalendarModule\Http\FrontendConfig;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\HealthService;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** The health banners: the settings page and the calendar page for administrators, "not up to date" for agents. */
class HealthBannersTest extends TestCase
{
    private const ERROR = 'The CalDAV server refused the username or password. (HTTP 401)';

    private function failing(array $attributes = []): Calendar
    {
        return Calendar::create($attributes + [
            'name'           => 'Holidays',
            'provider'       => Calendar::PROVIDER_ICS,
            'remote_id'      => 'https://calendar.example.com/holidays.ics',
            'last_error'     => self::ERROR,
            'last_synced_at' => gmdate('Y-m-d H:i:s', time() - 25 * 3600),
        ]);
    }

    private function viewableBy($user, Calendar $calendar): Calendar
    {
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true]);

        return $calendar;
    }

    /** The calendar page's config, as the page prints it. */
    private function pageConfig($user): array
    {
        $content = $this->actingAs($user)->get('/calendar')->assertStatus(200)->getContent();
        $this->assertSame(1, preg_match('#<script type="application/json" id="ljpc-calendar-config">(.*?)</script>#s', $content, $m));

        return json_decode($m[1], true);
    }

    public function test_the_settings_page_lists_each_problem_with_a_link_above_the_tabs(): void
    {
        $feed = $this->failing(['name' => 'Team <b>holidays</b>']);
        $connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_MICROSOFT, 'account' => 'planning@example.com', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH]);

        foreach ([SettingsPage::url(), SettingsPage::connectionsUrl(), SettingsPage::url($feed->id)] as $url) {
            $html = $this->actingAs($this->makeAdmin())->get($url)->assertStatus(200)->getContent();
            $banner = strpos($html, 'class="alert alert-warning ljpc-health"');
            $this->assertNotFalse($banner, $url);
            $this->assertLessThan(strpos($html, 'class="nav nav-tabs ljpc-tabs"'), $banner, $url);
            $this->assertStringContainsString('<a href="' . e(SettingsPage::accountUrl($connection)) . '">The Microsoft 365 account &quot;planning@example.com&quot; needs to be connected again. Its calendars don&#039;t sync until then.</a>', $html);
            $this->assertStringContainsString('<a href="' . e(SettingsPage::url($feed->id)) . '">The calendar &quot;Team &lt;b&gt;holidays&lt;/b&gt;&quot; has not synced for more than 24 hours, so its events may be out of date.</a>', $html);
        }
    }

    public function test_the_banners_only_show_problems_that_are_present_now(): void
    {
        Bus::fake();
        $connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $health = app(HealthService::class);
        $health->check();
        $connection->update(['status' => CalendarConnection::STATUS_OK]);
        $health->check();

        // Remembered for a day, so a quick return is not mailed again, but no longer shown.
        $this->assertArrayHasKey('connection:' . $connection->id . ':reauth', Option::get(HealthService::OPTION, [], true, false)['notified']);
        $html = $this->actingAs($this->makeAdmin())->get(SettingsPage::url())->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('ljpc-health', $html);
        $this->assertSame([], $this->pageConfig($this->makeAdmin())['health']);
    }

    public function test_the_settings_page_has_no_banner_without_problems(): void
    {
        $this->failing(['last_synced_at' => gmdate('Y-m-d H:i:s')]);

        $html = $this->actingAs($this->makeAdmin())->get(SettingsPage::url())->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('ljpc-health', $html);
    }

    public function test_administrators_get_the_problems_on_the_calendar_page_and_agents_nothing_extra(): void
    {
        $feed = $this->failing();
        $agent = $this->makeUser();
        $this->viewableBy($agent, $feed);

        $this->assertSame([['key' => 'calendar:' . $feed->id . ':failing', 'text' => 'The calendar "Holidays" has not synced for more than 24 hours, so its events may be out of date.', 'url' => SettingsPage::url($feed->id)]], $this->pageConfig($this->makeAdmin())['health']);
        $this->assertSame([], $this->pageConfig($agent)['health']);
        $this->assertSame([], FrontendConfig::page($agent)['health']);
    }

    public function test_the_calendar_list_tells_agents_when_a_calendar_last_synced_and_never_the_error(): void
    {
        $agent = $this->makeUser();
        $feed = $this->viewableBy($agent, $this->failing(['last_synced_at' => '2026-10-08 06:30:00']));
        $never = $this->viewableBy($agent, $this->failing(['name' => 'Never synced', 'last_synced_at' => null]));
        $local = $this->viewableBy($agent, Calendar::create(['name' => 'Local', 'provider' => Calendar::PROVIDER_LOCAL]));

        $content = $this->actingAs($agent)->json('GET', '/calendar/api/calendars')->assertStatus(200)->getContent();
        $listed = collect(json_decode($content, true))->keyBy('id');

        $this->assertSame([true, '2026-10-08T06:30:00Z'], [$listed[$feed->id]['sync_failed'], $listed[$feed->id]['last_synced_at']]);
        $this->assertSame([true, null], [$listed[$never->id]['sync_failed'], $listed[$never->id]['last_synced_at']]);
        $this->assertSame([false, null], [$listed[$local->id]['sync_failed'], $listed[$local->id]['last_synced_at']]);
        foreach ([$content, json_encode($this->pageConfig($agent))] as $sent) {
            $this->assertStringNotContainsString('refused the username', $sent);
            $this->assertStringNotContainsString('last_error', $sent);
        }
    }

    public function test_the_calendar_presenter_never_hands_out_the_error_text(): void
    {
        // last_error is for the settings only: agents and administrators alike get sync_failed and the last success.
        $agent = $this->makeUser();
        $feed = $this->viewableBy($agent, $this->failing(['last_synced_at' => '2026-10-08 06:30:00']));

        foreach ([$agent, $this->makeAdmin()] as $user) {
            $presented = EventPresenter::calendar($feed->fresh(), $user);

            $this->assertSame(['id', 'name', 'color', 'provider', 'display_priority', 'fields', 'has_title_template', 'rights', 'writable', 'sync_failed', 'last_synced_at'], array_keys($presented));
            $this->assertSame([true, '2026-10-08T06:30:00Z'], [$presented['sync_failed'], $presented['last_synced_at']]);
            $this->assertStringNotContainsString('refused the username', json_encode($presented));
        }
    }

    public function test_a_calendar_whose_account_must_be_connected_again_counts_as_failing_for_agents(): void
    {
        // A write can mark the account (ConnectionService), so the calendar never got an error of its own. It doesn't
        // sync until an administrator reauthorizes the account.
        $agent = $this->makeUser();
        $connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $calendar = $this->viewableBy($agent, Calendar::create(['name' => 'Team', 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $connection->id, 'remote_id' => 'team@example.com']));

        $listed = collect($this->actingAs($agent)->json('GET', '/calendar/api/calendars')->assertStatus(200)->json())->firstWhere('id', $calendar->id);

        $this->assertTrue($listed['sync_failed']);
        $connection->update(['status' => CalendarConnection::STATUS_OK]);
        $listed = collect($this->actingAs($agent)->json('GET', '/calendar/api/calendars')->json())->firstWhere('id', $calendar->id);
        $this->assertFalse($listed['sync_failed']);
    }

    public function test_agents_only_learn_about_calendars_they_can_view(): void
    {
        $agent = $this->makeUser();
        $mine = $this->viewableBy($agent, $this->failing(['name' => 'Mine']));
        $other = $this->failing(['name' => 'Someone else\'s']);

        $content = $this->actingAs($agent)->json('GET', '/calendar/api/calendars')->assertStatus(200)->getContent();

        $this->assertSame([$mine->id], array_column(json_decode($content, true), 'id'));
        $this->assertStringNotContainsString('Someone else', $content);
        $this->assertStringNotContainsString('Someone else', json_encode($this->pageConfig($agent)));
        $this->assertNotNull($other->id);
    }
}
