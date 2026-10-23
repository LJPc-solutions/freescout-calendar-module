<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\User;
use GuzzleHttp\Psr7\Response;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** Settings > Calendar: the tabs, the new-calendar types, the ICS form and the sync section of external calendars. */
class SettingsSyncTest extends TestCase
{
    private const FEED = 'https://93.184.215.14/private/abc123/team.ics';
    private const NOT_ALLOWED = 'This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST.';

    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = FakeHttp::install();
    }

    private function html(string $url, ?User $user = null): string
    {
        return $this->actingAs($user ?? $this->makeAdmin())->get($url)->assertStatus(200)->getContent();
    }

    private function store(array $post, ?User $admin = null)
    {
        return $this->actingAs($admin ?? $this->makeAdmin())->from(SettingsPage::url('new') . '&type=ics')
            ->post(route('ljpccalendarmodule.settings.calendars.store'), $post);
    }

    private function ics(array $overrides = []): array
    {
        return $overrides + ['provider' => 'ics', 'name' => 'Team feed ' . uniqid(), 'color' => '#e67e22', 'url' => self::FEED, 'sync_interval' => '60'];
    }

    private function feedCalendar(array $attributes = []): Calendar
    {
        return Calendar::create($attributes + ['name' => 'Feed ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS, 'remote_id' => self::FEED, 'sync_interval' => 60]);
    }

    /** What the edit form posts for a calendar, without fields or permissions. */
    private function form(Calendar $calendar, array $overrides = []): array
    {
        return $overrides + [
            'name' => $calendar->name, 'color' => '#3498db', 'enabled' => '1', 'display_priority' => '200', 'form_complete' => '1',
        ];
    }

    /** The types whose form the page shows; the others are rendered hidden for the type switch. */
    private static function shownForms(string $html): array
    {
        preg_match_all('/class="ljpc-type-form( hidden)?" data-ljpc-type-form="([a-z]+)"/', $html, $matches, PREG_SET_ORDER);
        $shown = [];
        foreach ($matches as $match) {
            if ($match[1] === '') {
                $shown[] = $match[2];
            }
        }

        return $shown;
    }

    private static function assertDueNow(?string $nextSyncAt): void
    {
        self::assertNotNull($nextSyncAt);
        self::assertLessThanOrEqual(5, abs(strtotime($nextSyncAt . ' UTC') - time()), 'next_sync_at is now, in UTC');
    }

    public function test_the_tabs_link_both_tabs_and_mark_the_open_one(): void
    {
        $admin = $this->makeAdmin();
        $calendar = Calendar::create(['name' => 'Cal ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $active = function (string $html): string {
            preg_match('/<li class="active"><a href="([^"]+)" aria-current="page">/', $html, $match);

            return html_entity_decode($match[1] ?? '');
        };

        foreach ([SettingsPage::url(), SettingsPage::url('new'), SettingsPage::url($calendar->id)] as $url) {
            $html = $this->html($url, $admin);
            $this->assertSame(SettingsPage::url(), $active($html), $url);
            $this->assertStringContainsString('<a href="' . e(SettingsPage::connectionsUrl()) . '">' . e(__('Connections')) . '</a>', $html);
        }
        $this->assertSame(SettingsPage::connectionsUrl(), $active($this->html(SettingsPage::connectionsUrl(), $admin)));
    }

    public function test_the_type_chooser_shows_the_form_of_the_chosen_type_and_works_without_javascript(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->html(SettingsPage::url('new'), $admin);
        $this->assertSame(['local'], self::shownForms($html), 'a FreeScout calendar is the default');
        $this->assertMatchesRegularExpression('/<form method="GET" action="' . preg_quote(e(SettingsPage::url()), '/') . '"[^>]*data-ljpc-type-chooser>\s*<input type="hidden" name="calendar" value="new">/', $html);
        foreach (['local', 'ics', 'account'] as $type) {
            $this->assertMatchesRegularExpression('/<input type="radio" name="type" value="' . $type . '"/', $html);
        }
        $this->assertMatchesRegularExpression('/<input type="radio" name="type" value="local"[^>]* checked/', $html);
        $this->assertStringContainsString('data-ljpc-type-continue>' . e(__('Continue')) . '</button>', $html);

        $html = $this->html(SettingsPage::url('new') . '&type=ics', $admin);
        $this->assertSame(['ics'], self::shownForms($html));
        $this->assertMatchesRegularExpression('/<input type="radio" name="type" value="ics"[^>]* checked/', $html);
        $this->assertStringContainsString('<input type="hidden" name="provider" value="ics">', $html);
        $this->assertStringContainsString('name="url"', $html);
        $this->assertStringContainsString('name="sync_interval"', $html);

        $this->assertSame(['account'], self::shownForms($this->html(SettingsPage::url('new') . '&type=account', $admin)));
        $this->assertSame(['local'], self::shownForms($this->html(SettingsPage::url('new') . '&type=bogus', $admin)));
    }

    public function test_the_feed_form_offers_the_sync_intervals_the_upgrade_uses(): void
    {
        $html = $this->html(SettingsPage::url('new') . '&type=ics');

        preg_match('/<select id="ljpc-ics-interval"[^>]*>(.*?)<\/select>/s', $html, $select);
        preg_match_all('/<option value="(\d+)"/', $select[1], $options);
        $this->assertSame(array_values(Calendar::SYNC_INTERVALS), array_map('intval', $options[1]));
        $this->assertSame([1, 5, 15, 30, 60, 120, 360, 720, 1440], array_values(Calendar::SYNC_INTERVALS));
        $this->assertSame(['1 minute', '5 minutes', '15 minutes', '30 minutes', '1 hour', '2 hours', '6 hours', '12 hours', 'daily'], array_keys(Calendar::SYNC_INTERVALS), 'the keys are the refresh settings v2 stored');
        $this->assertMatchesRegularExpression('/<option value="15" selected>' . preg_quote(e(__('Every 15 minutes')), '/') . '<\/option>/', $select[1]);
        $this->assertStringContainsString('<option value="1">' . e(__('Every minute')) . '</option>', $select[1]);
        $this->assertStringContainsString('<option value="60">' . e(__('Every hour')) . '</option>', $select[1]);
        $this->assertStringContainsString('<option value="1440">' . e(__('Once a day')) . '</option>', $select[1]);
    }

    public function test_the_account_type_lists_working_connections_and_opens_their_pick_screen(): void
    {
        $admin = $this->makeAdmin();
        $none = $this->html(SettingsPage::url('new') . '&type=account', $admin);
        $this->assertStringContainsString('href="' . e(SettingsPage::connectionsUrl()) . '"', $none);
        $this->assertStringNotContainsString('name="connection"', $none);

        $ok = CalendarConnection::create(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com']);
        $broken = CalendarConnection::create(['provider' => Calendar::PROVIDER_MICROSOFT, 'account' => 'gone@example.com', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH]);

        $html = $this->html(SettingsPage::url('new') . '&type=account', $admin);

        preg_match('/<form method="GET" action="([^"]+)"[^>]*data-ljpc-account-form>(.*?)<\/form>/s', $html, $form);
        // Within the account form only: the page's other selects hold option values (minutes) a connection id can equal.
        $this->assertMatchesRegularExpression('/<option value="' . $ok->id . '">[^<]*team@example\.com<\/option>/', $form[2]);
        $this->assertStringNotContainsString('<option value="' . $broken->id . '">', $form[2]);
        $this->assertSame(SettingsPage::url(), html_entity_decode($form[1]));
        $this->assertStringContainsString('<input type="hidden" name="tab" value="connections">', $form[2]);
        $this->assertStringContainsString('<input type="hidden" name="pick" value="1">', $form[2]);
        $this->assertStringContainsString('<select id="ljpc-account" class="form-control input-sized" name="connection"', $form[2]);
    }

    public function test_creates_an_ics_calendar_that_syncs_right_away(): void
    {
        $name = 'Team feed ' . uniqid();

        $response = $this->store($this->ics(['name' => ' <b>' . $name . '</b> ', 'url' => ' webcal://93.184.215.14/private/abc123/team.ics ', 'sync_interval' => '30']));

        $calendar = Calendar::where('name', $name)->firstOrFail();
        $response->assertRedirect(SettingsPage::url($calendar->id));
        $response->assertSessionHas('flash_success_floating', __('Calendar created. Its events are being read now. Choose who can use it below.'));
        // The edit page starts the first sync itself; without its script, calendar:sync reads the feed.
        $response->assertSessionHas('ljpccalendarmodule.sync_start', [$calendar->id]);
        $this->assertSame([Calendar::PROVIDER_ICS, 'webcal://93.184.215.14/private/abc123/team.ics', 30, '#e67e22', null], [$calendar->provider, $calendar->remote_id, $calendar->sync_interval, $calendar->color, $calendar->connection_id]);
        self::assertDueNow($calendar->next_sync_at);
        $this->assertSame([], $this->http->history(), 'the feed is read by calendar:sync, not by the form');
    }

    public function test_a_feed_address_must_be_http_https_or_webcal(): void
    {
        $admin = $this->makeAdmin();
        $before = Calendar::count();
        $scheme = __('Enter an address that starts with https://, http:// or webcal://.');

        foreach (['', 'ftp://93.184.215.14/team.ics', 'file:///etc/passwd', 'team.ics', 'https://93.184.215.14/' . str_repeat('a', 2000)] as $url) {
            $this->store($this->ics(['url' => $url]), $admin)->assertSessionHasErrors(['url']);
        }
        $this->store($this->ics(['url' => 'ftp://93.184.215.14/team.ics']), $admin)->assertSessionHasErrors(['url' => $scheme]);
        $this->store($this->ics(['url' => ['x']]), $admin)->assertSessionHasErrors(['url']);

        $this->assertSame($before, Calendar::count());
        $this->assertSame([], $this->http->history());
    }

    public function test_a_blocked_host_is_refused_under_the_url_field_without_a_request(): void
    {
        $admin = $this->makeAdmin();
        $before = Calendar::count();

        foreach (['http://127.0.0.1/team.ics', 'https://169.254.169.254/latest/meta-data/', 'webcal://10.0.0.5/team.ics', 'webcals://127.0.0.1:8080/team.ics'] as $url) {
            $this->store($this->ics(['url' => $url]), $admin)->assertSessionHasErrors(['url' => self::NOT_ALLOWED]);
        }

        config(['app.remote_host_white_list' => '10.0.0.5']);
        $this->store($this->ics(['url' => 'webcal://10.0.0.5/team.ics']), $admin)->assertSessionMissing('errors');

        $this->assertSame($before + 1, Calendar::count());
        $this->assertSame([], $this->http->history());
    }

    public function test_a_feed_address_with_a_login_in_it_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $before = Calendar::count();
        $login = __('Leave the username and password out of the link. FreeScout keeps no passwords for feeds.');

        foreach (['https://user:secret@93.184.215.14/team.ics', 'webcal://token@93.184.215.14/team.ics', 'https://user:@93.184.215.14/team.ics'] as $url) {
            $this->store($this->ics(['url' => $url]), $admin)->assertSessionHasErrors(['url' => $login]);
        }
        $this->store($this->ics(['url' => 'https://93.184.215.14/feeds/mail@example.com/team.ics']), $admin)->assertSessionMissing('errors');

        $feed = $this->feedCalendar();
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $feed->id]), $this->form($feed, ['url' => 'https://user:secret@93.184.215.14/team.ics', 'sync_interval' => '60']))
            ->assertSessionHasErrors(['url' => $login]);

        $this->assertSame($before + 2, Calendar::count());
        $this->assertSame(self::FEED, $feed->fresh()->remote_id);
        $this->assertSame([], $this->http->history());
    }

    public function test_the_sync_interval_must_be_one_of_the_offered_ones(): void
    {
        $admin = $this->makeAdmin();

        foreach (['7', '0', '', 'hourly', '1441'] as $interval) {
            $this->store($this->ics(['sync_interval' => $interval]), $admin)->assertSessionHasErrors(['sync_interval']);
        }
        foreach (Calendar::SYNC_INTERVALS as $minutes) {
            $this->store($this->ics(['sync_interval' => (string) $minutes]), $admin)->assertSessionMissing('errors');
        }
    }

    public function test_a_failed_feed_create_shows_the_feed_form_with_its_errors_and_input(): void
    {
        $admin = $this->makeAdmin();
        $name = 'Typed ' . uniqid();

        // Posted from the local form's page: the type switch in the browser doesn't change the address.
        $this->actingAs($admin)->from(SettingsPage::url('new'))->post(route('ljpccalendarmodule.settings.calendars.store'), $this->ics(['name' => $name, 'url' => 'http://127.0.0.1/x.ics', 'sync_interval' => '120']))
            ->assertRedirect(SettingsPage::url('new'));
        $html = $this->html(SettingsPage::url('new'), $admin);

        $this->assertSame(['ics'], self::shownForms($html));
        $this->assertStringContainsString(e(self::NOT_ALLOWED), $html);
        $this->assertMatchesRegularExpression('/id="ljpc-ics-name"[^>]*value="' . $name . '"/', $html);
        $this->assertMatchesRegularExpression('/id="ljpc-ics-url"[^>]*value="http:\/\/127\.0\.0\.1\/x\.ics"/', $html);
        $this->assertMatchesRegularExpression('/<option value="120" selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="ljpc-name"[^>]*value="' . $name . '"/', $html, 'the hidden local form stays empty');
    }

    public function test_a_local_calendar_is_still_created_without_a_provider_field(): void
    {
        $name = 'Plain ' . uniqid();

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.calendars.store'), ['name' => $name, 'color' => '#3498db'])->assertSessionMissing('errors');

        $calendar = Calendar::where('name', $name)->firstOrFail();
        $this->assertSame([Calendar::PROVIDER_LOCAL, null, null], [$calendar->provider, $calendar->remote_id, $calendar->next_sync_at]);
        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.calendars.store'), ['provider' => 'caldav', 'name' => 'X ' . uniqid(), 'color' => '#3498db'])
            ->assertSessionHasErrors(['provider']);
    }

    public function test_the_edit_page_shows_the_sync_section_only_for_external_calendars(): void
    {
        $admin = $this->makeAdmin(['timezone' => 'America/New_York', 'time_format' => User::TIME_FORMAT_12]);
        $local = Calendar::create(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $feed = $this->feedCalendar(['remote_id' => 'webcal://93.184.215.14/private/abc123/team.ics']);
        $feed->update(['last_synced_at' => '2026-10-09 12:05:00']);

        $html = $this->html(SettingsPage::url($local->id), $admin);
        $this->assertStringNotContainsString('id="ljpc-sync"', $html);
        $this->assertStringNotContainsString(route('ljpccalendarmodule.settings.calendars.sync', ['id' => $local->id]), $html);

        $html = $this->html(SettingsPage::url($feed->id), $admin);
        $this->assertStringContainsString('id="ljpc-sync"', $html);
        $this->assertMatchesRegularExpression('/<input id="ljpc-url" type="url" class="form-control" name="url" value="webcal:\/\/93\.184\.215\.14\/private\/abc123\/team\.ics"/', $html);
        $this->assertMatchesRegularExpression('/<option value="60" selected>' . preg_quote(e(__('Every hour')), '/') . '<\/option>/', $html);
        $this->assertStringContainsString('Oct 9, 2026 08:05am', $html, 'in the admin\'s zone and clock');
        $this->assertStringContainsString(e(__('An ICS feed is read-only: its events can\'t be changed from FreeScout.')), $html);
        $this->assertMatchesRegularExpression('/<form id="ljpc-sync-now" method="POST" action="' . preg_quote(route('ljpccalendarmodule.settings.calendars.sync', ['id' => $feed->id]), '/') . '" data-ljpc-sync-form>/', $html);
        $this->assertMatchesRegularExpression('/<button type="submit" form="ljpc-sync-now" class="btn btn-default" data-ljpc-sync-button><i class="glyphicon glyphicon-refresh" aria-hidden="true"><\/i> ' . preg_quote(e(__('Sync now')), '/') . '<\/button>/', $html);

        // The sync-now form sits outside the calendar form, which still ends with its completion marker.
        $form = substr($html, strpos($html, 'action="' . route('ljpccalendarmodule.settings.calendars.update', ['id' => $feed->id]) . '"'));
        $form = substr($form, 0, strpos($form, '</form>'));
        $this->assertMatchesRegularExpression('/name="form_complete" value="1">\s*$/', $form);
        $this->assertStringNotContainsString('id="ljpc-sync-now"', $form);
    }

    public function test_a_calendar_of_a_connected_account_names_it_and_links_to_reauthorize_when_needed(): void
    {
        $admin = $this->makeAdmin();
        $connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com']);
        $calendar = Calendar::create(['name' => 'Google ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $connection->id, 'remote_id' => 'team@example.com']);
        $reauthorize = route('ljpccalendarmodule.oauth.start', ['provider' => 'google', 'connection' => $connection->id]);

        $html = $this->html(SettingsPage::url($calendar->id), $admin);
        $this->assertStringContainsString('<a href="' . e(SettingsPage::connectionsUrl() . '#ljpc-connection-' . $connection->id) . '">team@example.com</a>', $html);
        $this->assertStringNotContainsString('name="url"', $html, 'only an ICS feed has an editable address');
        $this->assertStringNotContainsString(e($reauthorize), $html);
        $this->assertMatchesRegularExpression('/data-ljpc-sync-status="' . $calendar->id . '" data-ljpc-syncing>\s*<span class="ljpc-syncing">/', $html);
        $this->assertMatchesRegularExpression('/<button type="submit" form="ljpc-sync-now" class="btn btn-default" data-ljpc-sync-button>/', $html);

        $connection->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $html = $this->html(SettingsPage::url($calendar->id), $admin);
        $this->assertStringContainsString('<a href="' . e($reauthorize) . '">' . e(__('Reauthorize')) . '</a>', $html);
        $this->assertMatchesRegularExpression('/<button type="submit" form="ljpc-sync-now" class="btn btn-default" data-ljpc-sync-button disabled>/', $html);

        $dav = CalendarConnection::create(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'calendar-user', 'server_url' => 'https://192.0.2.10/dav/', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $davCalendar = Calendar::create(['name' => 'Dav ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $dav->id, 'remote_id' => 'https://192.0.2.10/dav/calendars/calendar-user/work/']);
        $html = $this->html(SettingsPage::url($davCalendar->id), $admin);
        $this->assertStringContainsString('<a href="' . e(SettingsPage::connectionsUrl() . '#ljpc-connection-' . $dav->id) . '">' . e(__('Reauthorize')) . '</a>', $html);
        $this->assertStringContainsString('https://192.0.2.10/dav/calendars/calendar-user/work/', $html);
    }

    public function test_the_last_error_is_escaped_on_the_edit_page_and_in_the_list(): void
    {
        $admin = $this->makeAdmin();
        $feed = $this->feedCalendar();
        $feed->update(['last_error' => 'The calendar feed refused access <script>alert(1)</script> (HTTP 403)']);

        foreach ([SettingsPage::url($feed->id), SettingsPage::url()] as $url) {
            $html = $this->html($url, $admin);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $url);
            $this->assertStringNotContainsString('<script>alert(1)', $html, $url);
        }
    }

    public function test_the_list_shows_the_sync_status_of_external_calendars(): void
    {
        $admin = $this->makeAdmin(['timezone' => 'Europe/Amsterdam', 'time_format' => User::TIME_FORMAT_24]);
        $synced = $this->feedCalendar(['name' => 'Synced ' . uniqid()]);
        $synced->update(['last_synced_at' => '2026-10-09 12:05:00']);
        $failing = $this->feedCalendar(['name' => 'Failing ' . uniqid()]);
        $failing->update(['last_synced_at' => '2026-10-08 06:00:00', 'last_error' => 'The calendar feed was not found (HTTP 404)']);
        $waiting = $this->feedCalendar(['name' => 'Waiting ' . uniqid()]);
        $off = $this->feedCalendar(['name' => 'Off ' . uniqid(), 'enabled' => false]);
        $off->update(['last_error' => 'Ignored while off']);

        $html = $this->html(SettingsPage::url(), $admin);
        $row = function (Calendar $calendar) use ($html): string {
            return $this->calendarCard($html, $calendar);
        };

        $this->assertStringContainsString(e(__('OK, synced :time', ['time' => 'Oct 9, 2026 14:05'])), $row($synced));
        $this->assertStringContainsString(e(__('Sync failed')), $row($failing));
        $error = preg_quote(e(__('Last error: :error', ['error' => 'The calendar feed was not found (HTTP 404)'])), '/');
        $this->assertMatchesRegularExpression('/<p class="ljpc-sync-error text-danger" title="' . $error . '">' . $error . '<\/p>/', $row($failing));
        $this->assertStringContainsString(e(__('Syncing…')), $row($waiting));
        $this->assertStringContainsString('data-ljpc-syncing', $row($waiting));
        $this->assertStringNotContainsString('data-ljpc-syncing', $row($synced));
        $this->assertStringContainsString(e(__('Disabled')), $row($off));
        $this->assertStringNotContainsString('Ignored while off', $html);
    }

    public function test_the_list_shows_each_calendar_as_a_card_like_freescouts_mailboxes(): void
    {
        $admin = $this->makeAdmin();
        $on = $this->feedCalendar(['name' => 'Card on ' . uniqid()]);
        $off = $this->feedCalendar(['name' => 'Card off ' . uniqid(), 'enabled' => false]);

        $html = $this->html(SettingsPage::url(), $admin);

        $this->assertStringContainsString('<div class="card-list ljpc-calendar-cards">', $html);
        $this->assertStringContainsString('<a href="' . e(SettingsPage::url('new')) . '" class="btn btn-bordered">' . e(__('New calendar')) . '</a>', $html);
        $this->assertStringNotContainsString('<table class="table table-striped ljpc-calendars">', $html);
        // The whole card opens the calendar; a disabled calendar looks inactive, as a disconnected mailbox does.
        $this->assertMatchesRegularExpression('/<div class="card no-img hover-shade ljpc-calendar-card card-active">\s*<h4>.*?<a href="' . preg_quote(e(SettingsPage::url($on->id)), '/') . '" class="ljpc-card-link">' . preg_quote(e($on->name), '/') . '<\/a><\/h4>\s*<p class="text-truncate">' . preg_quote(e(__('ICS feed')), '/') . '<\/p>/s', $html);
        $this->assertMatchesRegularExpression('/<div class="card no-img hover-shade ljpc-calendar-card card-inactive">\s*<h4>.*?' . preg_quote(e($off->name), '/') . '/s', $html);
    }

    /** One calendar's card in the settings list, from its name to the next card or the end of the list. */
    private function calendarCard(string $html, Calendar $calendar): string
    {
        $start = strpos($html, '>' . e($calendar->name) . '</a>');
        $this->assertNotFalse($start, $calendar->name);
        $end = strpos($html, 'ljpc-calendar-card', $start);

        return substr($html, $start, ($end === false ? strpos($html, '<!-- /calendars -->', $start) : $end) - $start);
    }

    public function test_the_list_marks_calendars_whose_account_must_be_connected_again(): void
    {
        $connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        // A sibling of the calendar whose sync found out: synced before, no error of its own.
        $calendar = Calendar::create(['name' => 'Sibling ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $connection->id, 'remote_id' => 'team@example.com']);
        $calendar->update(['last_synced_at' => '2026-10-09 12:05:00']);

        $html = $this->html(SettingsPage::url());

        $row = $this->calendarCard($html, $calendar);
        $this->assertStringContainsString('<span class="text-danger">' . e(__('Needs reauthorization')) . '</span>', $row);
        $this->assertStringContainsString('<a href="' . e(SettingsPage::reauthorizeUrl($connection)) . '">' . e(__('Reauthorize')) . '</a>', $row);
        $this->assertStringNotContainsString('OK, synced', $row);
    }

    private const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Feed//EN\r\nBEGIN:VEVENT\r\nUID:standup@example.com\r\nDTSTAMP:20261001T000000Z\r\n"
        . "SUMMARY:Stand-up\r\nDTSTART:20261020T080000Z\r\nDTEND:20261020T083000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    public function test_sync_now_syncs_right_away_and_says_what_changed(): void
    {
        $admin = $this->makeAdmin();
        $feed = $this->feedCalendar();
        $feed->update(['next_sync_at' => '2030-01-01 00:00:00', 'sync_failures' => 3, 'last_error' => 'Down (HTTP 503)']);
        $sync = route('ljpccalendarmodule.settings.calendars.sync', ['id' => $feed->id]);

        $this->actingAs($this->makeUser())->post($sync)->assertStatus(403);
        $this->assertSame([], $this->http->history());

        $this->http->queue(new Response(200, ['Content-Type' => 'text/calendar'], self::ICS));
        $this->actingAs($admin)->post($sync)
            ->assertRedirect(SettingsPage::url($feed->id))
            ->assertSessionHas('flash_success_floating', e(__('Synced: :created new, :updated changed, :deleted removed.', ['created' => 1, 'updated' => 0, 'deleted' => 0])));
        $this->assertSame(['Stand-up'], CalendarEvent::where('calendar_id', $feed->id)->pluck('summary')->all());
        $this->assertSame([0, null], [$feed->fresh()->sync_failures, $feed->fresh()->last_error]);

        // From a card in the list it returns to the list; a second click right after it leaves the feed alone.
        $this->actingAs($admin)->post($sync, ['back' => 'list'])
            ->assertRedirect(SettingsPage::url())
            ->assertSessionHas('flash_success_floating', e(__('This calendar synced moments ago.')));
        $this->assertCount(1, $this->http->history());

        $local = Calendar::create(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.sync', ['id' => $local->id]))->assertStatus(404);
    }

    public function test_sync_now_shows_the_error_when_the_sync_fails(): void
    {
        $feed = $this->feedCalendar();
        $this->http->queue(new Response(404));

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.calendars.sync', ['id' => $feed->id]))
            ->assertSessionHas('flash_error_floating', e(__('Syncing failed: :error', ['error' => 'The calendar feed was not found (HTTP 404).'])));
    }

    public function test_the_page_script_syncs_for_json_and_asks_for_the_status_while_a_calendar_syncs(): void
    {
        $admin = $this->makeAdmin();
        $feed = $this->feedCalendar();
        $status = route('ljpccalendarmodule.settings.calendars.status', ['ids' => [$feed->id]]);

        $this->actingAs($this->makeUser())->getJson($status)->assertStatus(403);
        $waiting = $this->actingAs($admin)->getJson($status)->assertStatus(200)->json()['calendars'][$feed->id];
        $this->assertTrue($waiting['syncing']);
        $this->assertStringContainsString(e(__('Syncing…')), $waiting['html']);

        $this->http->queue(new Response(200, ['Content-Type' => 'text/calendar'], self::ICS));
        $synced = $this->actingAs($admin)->postJson(route('ljpccalendarmodule.settings.calendars.sync', ['id' => $feed->id]))->assertStatus(200)->json();
        $this->assertSame(['synced', false], [$synced['state'], $synced['syncing']]);
        $this->assertSame(__('Synced: :created new, :updated changed, :deleted removed.', ['created' => 1, 'updated' => 0, 'deleted' => 0]), $synced['message']);
        $this->assertStringContainsString(e(__('OK, synced :time', ['time' => SettingsPage::time($feed->fresh()->last_synced_at)])), $synced['html']);

        $done = $this->actingAs($admin)->getJson($status)->json()['calendars'][$feed->id];
        $this->assertFalse($done['syncing']);
    }

    public function test_the_list_starts_the_first_sync_of_calendars_just_added_and_offers_sync_now(): void
    {
        $added = $this->feedCalendar();
        $other = $this->feedCalendar(['last_synced_at' => '2026-10-09 12:05:00']);
        $local = Calendar::create(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);

        $html = $this->actingAs($this->makeAdmin())->withSession(['ljpccalendarmodule.sync_start' => [$added->id]])
            ->get(SettingsPage::url())->assertStatus(200)->getContent();

        $sync = e(route('ljpccalendarmodule.settings.calendars.sync', ['id' => $added->id]));
        $this->assertStringContainsString('data-ljpc-sync-status="' . $added->id . '" data-ljpc-syncing data-ljpc-sync-start="' . $sync . '"', $html);
        $this->assertStringNotContainsString('data-ljpc-sync-start="' . e(route('ljpccalendarmodule.settings.calendars.sync', ['id' => $other->id])) . '"', $html);
        $this->assertMatchesRegularExpression('/<form method="POST" action="' . preg_quote($sync, '/') . '" class="ljpc-card-sync" data-ljpc-sync-form>.*?name="back" value="list"/s', $this->calendarCard($html, $added));
        $this->assertStringNotContainsString('ljpc-card-sync', $this->calendarCard($html, $local));
    }

    public function test_sync_now_explains_why_a_calendar_that_is_off_or_disconnected_does_not_sync(): void
    {
        $admin = $this->makeAdmin();
        $off = $this->feedCalendar(['enabled' => false]);
        $off->update(['next_sync_at' => '2030-01-01 00:00:00']);
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.sync', ['id' => $off->id]))
            ->assertSessionHas('flash_error_floating', e(__('This calendar is off, so it doesn\'t sync. Turn it on first.')));
        $this->assertSame('2030-01-01 00:00:00', $off->fresh()->next_sync_at);

        $connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $google = Calendar::create(['name' => 'Google ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $connection->id, 'remote_id' => 'team@example.com']);
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.sync', ['id' => $google->id]))
            ->assertSessionHas('flash_error_floating', e(__('The account of this calendar must be connected again before it can sync.')));
    }

    public function test_saving_a_feed_stores_its_address_and_interval_and_syncs_a_new_address_in_full(): void
    {
        $admin = $this->makeAdmin();
        $feed = $this->feedCalendar();
        $feed->update(['next_sync_at' => '2030-01-01 00:00:00', 'sync_state' => ['etag' => '"old"', 'hash' => 'abc', '_generation' => 4, '_listing' => false]]);
        $update = route('ljpccalendarmodule.settings.calendars.update', ['id' => $feed->id]);

        $this->actingAs($admin)->post($update, $this->form($feed, ['url' => self::FEED, 'sync_interval' => '60']))->assertSessionMissing('errors');
        $this->assertSame('2030-01-01 00:00:00', $feed->fresh()->next_sync_at, 'nothing about the sync changed');

        $this->actingAs($admin)->post($update, $this->form($feed, ['url' => self::FEED, 'sync_interval' => '5']))->assertSessionMissing('errors');
        $this->assertSame(5, $feed->fresh()->sync_interval);
        self::assertDueNow($feed->fresh()->next_sync_at);
        $this->assertSame('"old"', $feed->fresh()->sync_state['etag'], 'a new interval keeps the feed\'s state');

        $feed->update(['next_sync_at' => '2030-01-01 00:00:00']);
        $this->actingAs($admin)->post($update, $this->form($feed, ['url' => 'https://93.184.215.14/other.ics', 'sync_interval' => '5']))->assertSessionMissing('errors');
        $fresh = $feed->fresh();
        $this->assertSame('https://93.184.215.14/other.ics', $fresh->remote_id);
        self::assertDueNow($fresh->next_sync_at);
        $this->assertArrayNotHasKey('etag', $fresh->sync_state, 'the old feed\'s etag must not answer for the new one');
        $this->assertSame(4, $fresh->sync_state['_generation']);

        $this->actingAs($admin)->post($update, $this->form($feed, ['url' => 'http://127.0.0.1/x.ics', 'sync_interval' => '5']))->assertSessionHasErrors(['url' => self::NOT_ALLOWED]);
        $this->actingAs($admin)->post($update, $this->form($feed, ['url' => self::FEED, 'sync_interval' => '7']))->assertSessionHasErrors(['sync_interval']);
        $this->actingAs($admin)->post($update, $this->form($feed, ['url' => '', 'sync_interval' => '5']))->assertSessionHasErrors(['url']);
        $this->actingAs($admin)->post($update, $this->form($feed, ['url' => self::FEED, 'sync_interval' => '']))->assertSessionHasErrors(['sync_interval']);
        $this->assertSame('https://93.184.215.14/other.ics', $feed->fresh()->remote_id);

        // A form without the sync fields (a page from before they existed) keeps what is stored.
        $this->actingAs($admin)->post($update, $this->form($feed, ['name' => 'Renamed ' . uniqid()]))->assertSessionMissing('errors');
        $this->assertSame(['https://93.184.215.14/other.ics', 5], [$feed->fresh()->remote_id, $feed->fresh()->sync_interval]);
    }

    public function test_a_stored_feed_address_is_only_checked_again_when_it_changes(): void
    {
        $admin = $this->makeAdmin();
        // An upgraded v2 feed on an intranet host that APP_REMOTE_HOST_WHITE_LIST doesn't allow (yet).
        $feed = $this->feedCalendar(['remote_id' => 'http://10.0.0.7/intranet.ics']);
        $update = route('ljpccalendarmodule.settings.calendars.update', ['id' => $feed->id]);

        $this->actingAs($admin)->post($update, $this->form($feed, ['name' => 'Renamed ' . uniqid(), 'url' => 'http://10.0.0.7/intranet.ics', 'sync_interval' => '60']))
            ->assertSessionMissing('errors');
        $this->assertStringStartsWith('Renamed ', $feed->fresh()->name);

        $this->actingAs($admin)->post($update, $this->form($feed, ['url' => 'http://10.0.0.8/intranet.ics', 'sync_interval' => '60']))
            ->assertSessionHasErrors(['url' => self::NOT_ALLOWED]);
        $this->assertSame('http://10.0.0.7/intranet.ics', $feed->fresh()->remote_id);
    }

    public function test_a_local_calendar_saves_without_sync_fields(): void
    {
        $calendar = Calendar::create(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]), $this->form($calendar, ['url' => 'https://93.184.215.14/x.ics', 'sync_interval' => '1']))
            ->assertSessionMissing('errors');

        $this->assertSame([null, 5, null], [$calendar->fresh()->remote_id, $calendar->fresh()->sync_interval, $calendar->fresh()->next_sync_at]);
    }

    public function test_the_connections_tab_lists_the_accounts_with_their_status_and_calendars(): void
    {
        $admin = $this->makeAdmin();
        $ok = CalendarConnection::create(['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com']);
        $failing = CalendarConnection::create(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'calendar-user', 'server_url' => 'https://192.0.2.10', 'status' => CalendarConnection::STATUS_ERROR, 'last_error' => 'Missing <b>password</b>']);
        Calendar::create(['name' => 'One ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $ok->id, 'remote_id' => 'a']);
        Calendar::create(['name' => 'Two ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $ok->id, 'remote_id' => 'b']);

        $html = $this->html(SettingsPage::connectionsUrl(), $admin);

        $row = function (CalendarConnection $connection) use ($html): string {
            $start = strpos($html, 'id="ljpc-connection-' . $connection->id . '"');
            $this->assertNotFalse($start);

            return substr($html, $start, strpos($html, '</tr>', $start) - $start);
        };
        $this->assertStringContainsString('team@example.com', $row($ok));
        $this->assertStringContainsString(e(__('Google Calendar')), $row($ok));
        $this->assertStringContainsString(e(__('OK')), $row($ok));
        $this->assertMatchesRegularExpression('/<td class="ljpc-count">2<\/td>/', $row($ok));
        $this->assertStringContainsString('192.0.2.10', $row($failing));
        $this->assertStringContainsString('Missing &lt;b&gt;password&lt;/b&gt;', $row($failing));
        $this->assertMatchesRegularExpression('/<td class="ljpc-count">0<\/td>/', $row($failing));
    }
}
