<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteCalendar;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Modules\LJPcCalendarModule\Services\SyncService;
use Modules\LJPcCalendarModule\Tests\Support\DavResponses;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** The Connections tab: the Google and Microsoft apps, connecting CalDAV, choosing calendars, deleting a connection. */
class ConnectionsSettingsTest extends TestCase
{
    private const SERVER = 'https://192.0.2.10';
    private const WIKI = 'https://github.com/LJPc-solutions/freescout-calendar-module/wiki/';
    private const NOT_ALLOWED = 'This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST.';
    private const REFUSED = 'The CalDAV server refused the username or password.';

    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.remote_host_white_list' => '192.0.2.10']);
        $this->http = FakeHttp::install();
    }

    private function html(string $url, $user = null): string
    {
        return $this->actingAs($user ?? $this->makeAdmin())->get($url)->assertStatus(200)->getContent();
    }

    private function fake(string $provider): FakeProvider
    {
        $fake = new FakeProvider(true);
        app(ProviderRegistry::class)->swap($provider, $fake);

        return $fake;
    }

    private function connection(string $provider, array $attributes = []): CalendarConnection
    {
        return CalendarConnection::create($attributes + ['provider' => $provider, 'account' => 'team-' . uniqid() . '@example.com']);
    }

    private function davConnection(array $attributes = [], string $password = 'old-password'): CalendarConnection
    {
        $connection = new CalendarConnection($attributes + ['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'calendar-user', 'server_url' => self::SERVER]);
        $connection->setCredentials(['username' => 'calendar-user', 'password' => $password]);
        $connection->save();

        return $connection;
    }

    /** The answers of a CalDAV server whose account has a writable "Work & support" and a read-only holiday calendar. */
    private function queueDiscovery(): void
    {
        $this->http->queue(
            new Response(301, ['Location' => '/dav/']),
            DavResponses::fixture('well-known.xml'),
            DavResponses::fixture('principal.xml'),
            DavResponses::fixture('home.xml')
        );
    }

    private function remoteCalendar(string $remoteId, string $name, array $overrides = []): Calendar
    {
        return Calendar::create($overrides + ['name' => $name, 'provider' => Calendar::PROVIDER_GOOGLE, 'remote_id' => $remoteId]);
    }

    /** One stored event with an occurrence, written the way the importer leaves them. */
    private function event(Calendar $calendar, bool $readOnly = false, ?string $etag = '"e1"'): int
    {
        $uid = uniqid() . '@example.com';
        $id = DB::table('calendar_events')->insertGetId([
            'calendar_id' => $calendar->id, 'uid' => $uid, 'uid_hash' => sha1($uid), 'ics' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
            'remote_id' => $uid, 'remote_id_hash' => sha1($uid), 'etag' => $etag, 'read_only' => $readOnly,
            'starts_at' => '2026-10-20 08:00:00', 'ends_at' => '2026-10-20 09:00:00', 'created_at' => '2026-10-07 10:00:00', 'updated_at' => '2026-10-07 10:00:00',
        ]);
        DB::table('calendar_occurrences')->insert(['event_id' => $id, 'calendar_id' => $calendar->id, 'starts_at' => '2026-10-20 08:00:00', 'ends_at' => '2026-10-20 09:00:00']);

        return $id;
    }

    private static function assertDueNow(?string $nextSyncAt): void
    {
        self::assertNotNull($nextSyncAt);
        self::assertLessThanOrEqual(5, abs(strtotime($nextSyncAt . ' UTC') - time()));
    }

    public function test_the_tab_shows_both_apps_with_their_redirect_urls_and_wiki_pages_but_never_a_secret(): void
    {
        $service = app(ConnectionService::class);
        $service->saveAppCredentials('google', ['client_id' => 'test-client-id.apps.example.com', 'client_secret' => 'test-google-secret']);
        $service->saveAppCredentials('microsoft', ['client_id' => 'test-microsoft-client-id', 'client_secret' => 'test-microsoft-secret', 'tenant' => 'example.com', 'secret_expires_at' => '2027-03-31']);

        $html = $this->html(SettingsPage::connectionsUrl());

        $this->assertStringNotContainsString('test-google-secret', $html);
        $this->assertStringNotContainsString('test-microsoft-secret', $html);
        $this->assertSame(2, substr_count($html, e(__('A secret is saved. Leave it empty to keep it.'))));
        $this->assertMatchesRegularExpression('/<input id="ljpc-google-secret" type="password" class="form-control" name="client_secret" value=""/', $html);
        $this->assertMatchesRegularExpression('/<input id="ljpc-microsoft-secret" type="password" class="form-control" name="client_secret" value=""/', $html);
        $this->assertMatchesRegularExpression('/id="ljpc-google-client-id"[^>]*value="test-client-id\.apps\.example\.com"/', $html);
        $this->assertMatchesRegularExpression('/id="ljpc-microsoft-client-id"[^>]*value="test-microsoft-client-id"/', $html);
        $this->assertMatchesRegularExpression('/id="ljpc-microsoft-tenant"[^>]*value="example\.com"/', $html);
        $this->assertMatchesRegularExpression('/<input id="ljpc-microsoft-expires" type="date" class="form-control input-sized" name="secret_expires_at" value="2027-03-31">/', $html);
        foreach (['google', 'microsoft'] as $provider) {
            $this->assertStringContainsString('action="' . route('ljpccalendarmodule.settings.apps', ['provider' => $provider]) . '"', $html);
            $this->assertMatchesRegularExpression('/<input id="ljpc-' . $provider . '-redirect" type="text" class="form-control" readonly value="' . preg_quote(e($service->redirectUrl($provider)), '/') . '">/', $html);
            $this->assertStringContainsString('data-ljpc-copy="ljpc-' . $provider . '-redirect"', $html);
            $this->assertStringContainsString('<a href="' . e(route('ljpccalendarmodule.oauth.start', ['provider' => $provider])) . '" class="btn btn-primary">', $html);
        }
        foreach (['Google-Calendar', 'Microsoft-365', 'CalDAV'] as $page) {
            $this->assertStringContainsString('href="' . self::WIKI . $page . '"', $html);
        }
    }

    public function test_the_connect_buttons_wait_until_the_app_is_saved(): void
    {
        $html = $this->html(SettingsPage::connectionsUrl());

        $this->assertStringNotContainsString(e(route('ljpccalendarmodule.oauth.start', ['provider' => 'google'])), $html);
        $this->assertStringNotContainsString(e(route('ljpccalendarmodule.oauth.start', ['provider' => 'microsoft'])), $html);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-primary" disabled>' . preg_quote(e(__('Connect a Google account')), '/') . '<\/button>/', $html);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-primary" disabled>' . preg_quote(e(__('Connect a Microsoft account')), '/') . '<\/button>/', $html);
        $this->assertStringContainsString(e(__('Save the client id and secret first.')), $html);
        $this->assertStringContainsString(e(__('Save the tenant, client id and secret first.')), $html);
        $this->assertSame(2, substr_count($html, e(__('No secret is saved yet.'))));
    }

    public function test_an_app_form_shows_only_its_own_errors_and_input(): void
    {
        $admin = $this->makeAdmin();
        app(ConnectionService::class)->saveAppCredentials('google', ['client_id' => 'stored-google-id', 'client_secret' => 'test-google-secret']);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.apps', ['provider' => 'microsoft']), [
            'client_id' => 'typed-microsoft-id', 'client_secret' => 'typed-secret', 'tenant' => 'not a tenant!', 'secret_expires_at' => '2027-03-31',
        ])->assertRedirect(SettingsPage::connectionsUrl());
        $html = $this->html(SettingsPage::connectionsUrl(), $admin);

        $this->assertStringContainsString(e(__('Enter the directory (tenant) id or a domain of the organization.')), $html);
        $this->assertMatchesRegularExpression('/id="ljpc-microsoft-client-id"[^>]*value="typed-microsoft-id"/', $html);
        $this->assertMatchesRegularExpression('/id="ljpc-microsoft-tenant"[^>]*value="not a tenant!"/', $html);
        $this->assertMatchesRegularExpression('/id="ljpc-google-client-id"[^>]*value="stored-google-id"/', $html);
        $this->assertStringNotContainsString('typed-secret', $html);
        $this->assertSame(1, substr_count($html, 'form-group has-error'), 'only the tenant field of the Microsoft form');
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.apps', ['provider' => 'microsoft']), ['client_id' => '', 'client_secret' => 'typed-secret', 'tenant' => 'example.com']);
        $this->assertNull(session()->getOldInput('client_secret'));
        $this->assertSame('microsoft', session()->getOldInput('app'));

        // And the other way round: a failed Google form leaves the saved Microsoft app as it is shown.
        app(ConnectionService::class)->saveAppCredentials('microsoft', ['client_id' => 'stored-microsoft-id', 'client_secret' => 'test-microsoft-secret', 'tenant' => 'example.com']);
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.apps', ['provider' => 'google']), ['client_id' => '', 'client_secret' => 'typed-secret'])
            ->assertSessionHasErrorsIn('google', ['client_id']);
        $html = $this->html(SettingsPage::connectionsUrl(), $admin);
        $this->assertMatchesRegularExpression('/id="ljpc-microsoft-client-id"[^>]*value="stored-microsoft-id"/', $html);
        $this->assertMatchesRegularExpression('/id="ljpc-microsoft-tenant"[^>]*value="example\.com"/', $html);
        $this->assertMatchesRegularExpression('/id="ljpc-google-client-id"[^>]*value=""/', $html);
    }

    public function test_connecting_caldav_discovers_the_calendars_and_opens_the_pick_screen(): void
    {
        $admin = $this->makeAdmin();
        $before = CalendarConnection::count();
        $this->queueDiscovery();

        $response = $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.connections.caldav'), [
            'server_url' => ' ' . self::SERVER . ' ', 'username' => 'calendar-user', 'password' => 'fake-password',
        ]);

        $connection = CalendarConnection::orderByDesc('id')->first();
        $this->assertSame($before + 1, CalendarConnection::count());
        $response->assertRedirect(SettingsPage::connectionsUrl($connection->id));
        $response->assertSessionHas('flash_success_floating', e(__(':account is connected. Choose the calendars FreeScout shows.', ['account' => 'calendar-user'])));
        $this->assertSame(
            [Calendar::PROVIDER_CALDAV, 'calendar-user', self::SERVER, CalendarConnection::STATUS_OK, null, (int) $admin->id],
            [$connection->provider, $connection->account, $connection->server_url, $connection->status, $connection->last_error, (int) $connection->created_by]
        );
        $this->assertSame(['username' => 'calendar-user', 'password' => 'fake-password'], $connection->credentials());
        $this->assertSame('Basic ' . base64_encode('calendar-user:fake-password'), $this->http->history()[0]['headers']['Authorization'][0]);
        $this->assertSame(0, $this->http->pending());
    }

    public function test_a_failed_caldav_discovery_shows_the_error_under_the_form_and_stores_nothing(): void
    {
        $admin = $this->makeAdmin();
        $before = CalendarConnection::count();
        $this->http->queue(new Response(401), new Response(401));

        $this->actingAs($admin)->from(SettingsPage::connectionsUrl())->post(route('ljpccalendarmodule.settings.connections.caldav'), [
            'server_url' => self::SERVER, 'username' => 'calendar-user', 'password' => 'wrong-password',
        ])->assertRedirect(SettingsPage::connectionsUrl() . '#ljpc-caldav')
            ->assertSessionHasErrorsIn('caldav', ['connect' => self::REFUSED]);

        $this->assertSame($before, CalendarConnection::count());
        $this->assertNull(session()->getOldInput('password'));
        $html = $this->html(SettingsPage::connectionsUrl(), $admin);
        $form = substr($html, strpos($html, 'id="ljpc-caldav"'));
        $form = substr($form, 0, strpos($form, '</form>'));
        $this->assertStringContainsString(e(self::REFUSED), $form);
        $this->assertMatchesRegularExpression('/id="ljpc-caldav-username"[^>]*value="calendar-user"/', $form);
        $this->assertStringNotContainsString('wrong-password', $html);
    }

    public function test_caldav_connect_checks_its_fields_and_the_host_before_any_request(): void
    {
        $admin = $this->makeAdmin();
        $post = function (array $fields) use ($admin) {
            return $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.connections.caldav'), $fields + ['server_url' => self::SERVER, 'username' => 'calendar-user', 'password' => 'fake-password']);
        };

        $post(['server_url' => '', 'username' => '', 'password' => ''])->assertSessionHasErrorsIn('caldav', ['server_url', 'username', 'password']);
        $post(['server_url' => 'ftp://192.0.2.10/dav'])->assertSessionHasErrorsIn('caldav', ['server_url' => __('Enter an address that starts with https:// or http://.')]);
        $post(['server_url' => ['x']])->assertSessionHasErrorsIn('caldav', ['server_url']);
        $post(['server_url' => 'http://127.0.0.1/dav/'])->assertSessionHasErrorsIn('caldav', ['connect' => self::NOT_ALLOWED]);
        $post(['server_url' => 'https://calendar-user:secret@192.0.2.10/dav/'])
            ->assertSessionHasErrorsIn('caldav', ['server_url' => __('Leave the username and password out of the address and fill them in below.')]);
        $this->assertSame([], CalendarConnection::where('server_url', 'like', '%secret%')->pluck('id')->all());

        $this->assertSame([], $this->http->history());
    }

    public function test_a_malformed_server_address_or_href_is_a_form_error_and_not_a_crash(): void
    {
        $admin = $this->makeAdmin();
        $invalid = __('This is not a valid http or https address.');
        foreach (['https://192.0.2.10:99999/dav/', 'https://exa%20mple/dav/'] as $address) {
            $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.connections.caldav'), ['server_url' => $address, 'username' => 'calendar-user', 'password' => 'p'])
                ->assertRedirect(SettingsPage::connectionsUrl() . '#ljpc-caldav')
                ->assertSessionHasErrorsIn('caldav', ['connect' => $invalid]);
        }
        // A server whose discovery names an address no client can use.
        $this->http->queue(new Response(207, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/.well-known/caldav</d:href>'
            . '<d:propstat><d:prop><d:current-user-principal><d:href>https://192.0.2.10:99999/principals/calendar-user/</d:href></d:current-user-principal></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'));
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.connections.caldav'), ['server_url' => self::SERVER, 'username' => 'calendar-user', 'password' => 'p'])
            ->assertSessionHasErrorsIn('caldav', ['connect' => $invalid]);
        $this->assertSame(0, CalendarConnection::where('provider', Calendar::PROVIDER_CALDAV)->count());

        // Basic authentication can't carry a colon in the username: the form says so before anything is sent.
        $requests = count($this->http->history());
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.connections.caldav'), ['server_url' => self::SERVER, 'username' => 'calendar:user', 'password' => 'p'])
            ->assertSessionHasErrorsIn('caldav', ['username' => "The username can't contain a colon."]);
        $this->assertCount($requests, $this->http->history());
    }

    public function test_connecting_the_same_caldav_account_again_updates_its_connection(): void
    {
        $existing = $this->davConnection(['status' => CalendarConnection::STATUS_ERROR, 'last_error' => 'Missing CalDAV username or password']);
        $before = CalendarConnection::count();
        $this->queueDiscovery();

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.connections.caldav'), [
            'server_url' => self::SERVER, 'username' => 'calendar-user', 'password' => 'new-password',
        ])->assertRedirect(SettingsPage::connectionsUrl($existing->id));

        $this->assertSame($before, CalendarConnection::count());
        $fresh = $existing->fresh();
        $this->assertSame([CalendarConnection::STATUS_OK, null, 'new-password'], [$fresh->status, $fresh->last_error, $fresh->credentials()['password']]);
    }

    public function test_the_same_server_written_another_way_is_the_same_connection(): void
    {
        $existing = $this->davConnection(['server_url' => 'https://192.0.2.10/dav']);
        $other = $this->davConnection(['account' => 'other-user', 'server_url' => 'https://192.0.2.10/dav/']);
        $this->queueDiscovery();

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.connections.caldav'), [
            'server_url' => 'https://192.0.2.10/dav/', 'username' => 'calendar-user', 'password' => 'new-password',
        ])->assertRedirect(SettingsPage::connectionsUrl($existing->id));

        $this->assertSame(2, CalendarConnection::where('provider', Calendar::PROVIDER_CALDAV)->count());
        $this->assertSame(['https://192.0.2.10/dav', 'new-password'], [$existing->fresh()->server_url, $existing->fresh()->credentials()['password']]);

        // A reauthorization may not take over the username another connection to that server has, however it is written.
        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.connections.caldav'), ['connection' => (string) $existing->id, 'username' => 'other-user', 'password' => 'p'])
            ->assertSessionHasErrorsIn('caldav', ['connect' => __(':account is already connected.', ['account' => 'other-user'])]);
        $this->assertSame('other-user', $other->fresh()->account);
    }

    public function test_a_double_click_on_connect_makes_one_connection(): void
    {
        // The first click's connection is saved while the second click's discovery runs.
        $first = null;
        Event::listen(TransactionBeginning::class, function () use (&$first) {
            $first = $first ?? $this->davConnection(['server_url' => self::SERVER . '/'], 'first-password');
        });
        $this->queueDiscovery();

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.connections.caldav'), [
            'server_url' => self::SERVER, 'username' => 'calendar-user', 'password' => 'second-password',
        ])->assertRedirect(SettingsPage::connectionsUrl($first->id));

        $this->assertSame([$first->id], CalendarConnection::where('provider', Calendar::PROVIDER_CALDAV)->pluck('id')->all());
        $this->assertSame('second-password', $first->fresh()->credentials()['password']);
    }

    public function test_reauthorizing_caldav_checks_the_new_login_before_storing_it(): void
    {
        $admin = $this->makeAdmin();
        $connection = $this->davConnection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => 'The CalDAV server refused the username or password. (HTTP 401)']);
        $reauthorize = route('ljpccalendarmodule.settings.connections.caldav');

        // A new password the server refuses: nothing changes, and the error shows in this connection's own form.
        $this->http->queue(new Response(401), new Response(401));
        $this->actingAs($admin)->post($reauthorize, ['connection' => (string) $connection->id, 'username' => 'calendar-user', 'password' => 'wrong-password'])
            ->assertRedirect(SettingsPage::accountUrl($connection))
            ->assertSessionHasErrorsIn('caldav', ['connect' => self::REFUSED]);
        $this->assertSame([CalendarConnection::STATUS_NEEDS_REAUTH, 'old-password'], [$connection->fresh()->status, $connection->fresh()->credentials()['password']]);
        $html = $this->html(SettingsPage::connectionsUrl(), $admin);
        $this->assertMatchesRegularExpression('/<details class="ljpc-action" open>\s*<summary class="btn btn-default btn-xs">' . preg_quote(e(__('Reauthorize')), '/') . '<\/summary>\s*<form[^>]*>.*?' . preg_quote(e(self::REFUSED), '/') . '/s', $html);

        // An empty password keeps the saved one; the login is checked with it before the connection works again.
        $this->queueDiscovery();
        $this->actingAs($admin)->post($reauthorize, ['connection' => (string) $connection->id, 'username' => 'calendar-user', 'password' => ''])
            ->assertRedirect(SettingsPage::connectionsUrl($connection->id));
        $this->assertSame('Basic ' . base64_encode('calendar-user:old-password'), $this->http->history()[2]['headers']['Authorization'][0]);
        $fresh = $connection->fresh();
        $this->assertSame([CalendarConnection::STATUS_OK, null, self::SERVER], [$fresh->status, $fresh->last_error, $fresh->server_url]);

        $google = $this->connection(Calendar::PROVIDER_GOOGLE);
        $this->actingAs($admin)->post($reauthorize, ['connection' => (string) $google->id, 'username' => 'x', 'password' => 'y'])->assertStatus(404);

        // Another connection to this server has that username already: one account, one connection.
        $this->davConnection(['account' => 'other-user']);
        $requests = count($this->http->history());
        $this->actingAs($admin)->post($reauthorize, ['connection' => (string) $connection->id, 'username' => 'other-user', 'password' => 'p'])
            ->assertSessionHasErrorsIn('caldav', ['connect' => __(':account is already connected.', ['account' => 'other-user'])]);
        $this->assertSame('calendar-user', $connection->fresh()->account);
        $this->assertCount($requests, $this->http->history());
    }

    public function test_a_caldav_account_whose_password_the_server_refused_works_again_after_reauthorizing(): void
    {
        $admin = $this->makeAdmin();
        $connection = $this->davConnection();
        $calendar = Calendar::create([
            'name' => 'Work ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $connection->id,
            'remote_id' => self::SERVER . '/dav/calendars/calendar-user/work/', 'sync_interval' => 15,
        ]);
        $this->http->queue(new Response(401, ['WWW-Authenticate' => 'Basic realm="dav"']));
        app(SyncService::class)->syncCalendar($calendar, SyncBudget::seconds(30));
        $this->assertSame([CalendarConnection::STATUS_NEEDS_REAUTH, 'The account must be connected again.'], [$connection->fresh()->status, $connection->fresh()->last_error]);

        // The account's row says so and holds the login form, open.
        $html = $this->html(SettingsPage::connectionsUrl(), $admin);
        $row = substr($html, strpos($html, 'id="ljpc-connection-' . $connection->id . '"'));
        $row = substr($row, 0, strpos($row, '</tr>'));
        $this->assertStringContainsString(e(__('Needs reauthorization')), $row);
        $this->assertStringContainsString(e(__('The account must be connected again.')), $row);
        $this->assertMatchesRegularExpression('/<details class="ljpc-action" open>\s*<summary class="btn btn-default btn-xs">' . preg_quote(e(__('Reauthorize')), '/') . '<\/summary>/', $row);

        $this->queueDiscovery();
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.connections.caldav'), ['connection' => (string) $connection->id, 'username' => 'calendar-user', 'password' => 'new-password'])
            ->assertRedirect(SettingsPage::connectionsUrl($connection->id));

        $fresh = $connection->fresh();
        $this->assertSame([CalendarConnection::STATUS_OK, null, 'new-password'], [$fresh->status, $fresh->last_error, $fresh->credentials()['password']]);
        // It syncs again at the next run, with the new password and without the backoff of its failure.
        $this->assertSame(0, $calendar->fresh()->sync_failures);
        $this->http->queue(DavResponses::multistatus([$calendar->remote_id => ['resourcetype' => 'calendar', 'sync-token' => 'http://sabre.io/ns/sync/1']]), DavResponses::multistatus([$calendar->remote_id => ['resourcetype' => 'calendar']]));
        $requests = count($this->http->history());
        $this->assertSame(['synced' => 1, 'failed' => 0, 'skipped' => 0], app(SyncService::class)->runDue(SyncBudget::seconds(30), $calendar->id));
        $this->assertSame('Basic ' . base64_encode('calendar-user:new-password'), $this->http->history()[$requests]['headers']['Authorization'][0]);
        $this->assertNull($calendar->fresh()->last_error);
    }

    public function test_a_reauthorization_wins_over_a_refused_old_password_that_lands_while_it_runs(): void
    {
        $connection = $this->davConnection();
        // A sync's 401 for the old password marks the account while discovery checks the new one.
        $this->http->queue(function () use ($connection) {
            CalendarConnection::whereKey($connection->id)->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => 'The account must be connected again.']);

            return new Response(301, ['Location' => '/dav/']);
        }, DavResponses::fixture('well-known.xml'), DavResponses::fixture('principal.xml'), DavResponses::fixture('home.xml'));

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.connections.caldav'), ['connection' => (string) $connection->id, 'username' => 'calendar-user', 'password' => 'new-password'])
            ->assertRedirect(SettingsPage::connectionsUrl($connection->id));

        $fresh = $connection->fresh();
        $this->assertSame([CalendarConnection::STATUS_OK, null, 'new-password'], [$fresh->status, $fresh->last_error, $fresh->credentials()['password']]);
    }

    public function test_connecting_a_caldav_account_again_with_a_refused_password_changes_nothing(): void
    {
        $existing = $this->davConnection();
        $this->http->queue(new Response(401), new Response(401));

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.connections.caldav'), [
            'server_url' => self::SERVER, 'username' => 'calendar-user', 'password' => 'wrong-password',
        ])->assertSessionHasErrorsIn('caldav', ['connect' => self::REFUSED]);

        $fresh = $existing->fresh();
        $this->assertSame([CalendarConnection::STATUS_OK, null, 'old-password'], [$fresh->status, $fresh->last_error, $fresh->credentials()['password']]);
    }

    public function test_every_account_offers_its_actions(): void
    {
        $google = $this->connection(Calendar::PROVIDER_GOOGLE);
        $microsoft = $this->connection(Calendar::PROVIDER_MICROSOFT);
        $dav = $this->davConnection();

        $html = $this->html(SettingsPage::connectionsUrl());

        foreach ([$google, $microsoft, $dav] as $connection) {
            $this->assertStringContainsString('<a href="' . e(SettingsPage::connectionsUrl($connection->id)) . '" class="btn btn-default btn-xs">' . e(__('Choose calendars')) . '</a>', $html);
            $this->assertStringContainsString('action="' . route('ljpccalendarmodule.settings.connections.delete', ['id' => $connection->id]) . '"', $html);
        }
        foreach ([$google, $microsoft] as $connection) {
            $this->assertStringContainsString('<a href="' . e(route('ljpccalendarmodule.oauth.start', ['provider' => $connection->provider, 'connection' => $connection->id])) . '" class="btn btn-default btn-xs">' . e(__('Reauthorize')) . '</a>', $html);
        }
        $this->assertStringContainsString('<input type="hidden" name="connection" value="' . $dav->id . '">', $html);
        $this->assertMatchesRegularExpression('/<input id="ljpc-reauth-username-' . $dav->id . '"[^>]*name="username" value="calendar-user"/', $html);
        $this->assertMatchesRegularExpression('/<details class="ljpc-action">\s*<summary class="btn btn-default btn-xs">' . preg_quote(e(__('Reauthorize')), '/') . '<\/summary>/', $html, 'closed while the login works');
        $this->assertStringNotContainsString('old-password', $html);

        preg_match('/<form method="GET" action="([^"]+)"[^>]*data-ljpc-shared-mailbox="' . $microsoft->id . '">(.*?)<\/form>/s', $html, $shared);
        $this->assertSame(SettingsPage::url(), html_entity_decode($shared[1]));
        foreach (['tab' => 'connections', 'connection' => (string) $microsoft->id, 'pick' => '1'] as $name => $value) {
            $this->assertStringContainsString('<input type="hidden" name="' . $name . '" value="' . $value . '">', $shared[2]);
        }
        $this->assertMatchesRegularExpression('/<input id="ljpc-owner-' . $microsoft->id . '" type="email" class="form-control" name="owner"/', $shared[2]);
        $this->assertSame(1, substr_count($html, 'data-ljpc-shared-mailbox='), 'only Microsoft has shared mailboxes');
    }

    public function test_the_pick_screen_lists_the_calendars_and_marks_added_and_read_only_ones(): void
    {
        $fake = $this->fake(Calendar::PROVIDER_GOOGLE);
        $connection = $this->connection(Calendar::PROVIDER_GOOGLE);
        $fake->calendars = [
            new RemoteCalendar('work-id', 'Work', '#1f6feb', 'Europe/Amsterdam', true, true),
            new RemoteCalendar('holidays-id', 'Holidays <b>NL</b>', null, null, false),
            new RemoteCalendar('team-id', 'Team', null, null, true),
        ];
        $added = $this->remoteCalendar('team-id', 'Team ' . uniqid(), ['connection_id' => $connection->id]);

        $html = $this->html(SettingsPage::connectionsUrl($connection->id));

        $this->assertStringContainsString('action="' . route('ljpccalendarmodule.settings.connections.calendars', ['id' => $connection->id]) . '"', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="calendars\[\]" value="work-id">/', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="calendars\[\]" value="team-id" checked disabled>/', $html);
        $this->assertStringContainsString('<a href="' . e(SettingsPage::url($added->id)) . '">' . e($added->name) . '</a>', $html);
        $this->assertStringContainsString('Holidays &lt;b&gt;NL&lt;/b&gt;', $html);
        $item = substr($html, strpos($html, 'value="holidays-id"'), 600);
        $this->assertStringContainsString(e(__('Read only')), $item);
        $this->assertStringContainsString('<span class="ljpc-swatch ljpc-swatch-none" aria-hidden="true"></span>', $item, 'no color yet: adding picks one');
        $this->assertStringContainsString('<span class="ljpc-swatch" style="background-color: #1f6feb" aria-hidden="true"></span>', substr($html, strpos($html, 'value="work-id"'), 600));
        $this->assertStringContainsString(e(__('Primary')), substr($html, strpos($html, 'value="work-id"'), 600));
        $this->assertSame([['method' => 'listCalendars', 'connection_id' => $connection->id, 'owner' => null]], $fake->calls);
    }

    public function test_a_listing_error_shows_on_the_pick_screen_escaped(): void
    {
        $fake = $this->fake(Calendar::PROVIDER_GOOGLE);
        $connection = $this->connection(Calendar::PROVIDER_GOOGLE, ['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $fake->listError = new ProviderUnavailable('Google said <b>no</b>');

        $html = $this->html(SettingsPage::connectionsUrl($connection->id));

        $this->assertStringContainsString('<div class="alert alert-danger">' . e('Google said <b>no</b>'), $html);
        $this->assertStringNotContainsString('name="calendars[]"', $html);
        $this->assertStringContainsString('<a href="' . e(SettingsPage::reauthorizeUrl($connection)) . '">' . e(__('Reauthorize')) . '</a>', $html);
    }

    public function test_a_connection_that_no_longer_exists_says_so(): void
    {
        $html = $this->html(SettingsPage::url() . '?tab=connections&connection=999999&pick=1');

        $this->assertStringContainsString(e(__('This connection no longer exists.')), $html);
        $this->assertStringContainsString('<li class="active"><a href="' . e(SettingsPage::connectionsUrl()) . '" aria-current="page">', $html);
    }

    public function test_adding_calendars_names_them_uniquely_keeps_valid_colors_and_zones_and_syncs_them_now(): void
    {
        $fake = $this->fake(Calendar::PROVIDER_GOOGLE);
        $connection = $this->connection(Calendar::PROVIDER_GOOGLE);
        $base = 'Work ' . uniqid();
        Calendar::create(['name' => $base, 'provider' => Calendar::PROVIDER_LOCAL]);
        $fake->calendars = [
            new RemoteCalendar('work-id', $base, '#ABCDEF', 'Asia/Calcutta', true, true),
            new RemoteCalendar('holidays-id', ' Holidays <b>NL</b> ' . $base, null, 'Mars/Olympus', false),
            new RemoteCalendar('offset-id', $base, 'red', '+02:00', true),
            new RemoteCalendar('skipped-id', 'Not ticked', null, null, true),
        ];

        $response = $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.connections.calendars', ['id' => $connection->id]), [
            'calendars' => ['work-id', 'holidays-id', 'offset-id', 'unknown-id'],
        ]);

        $added = Calendar::where('connection_id', $connection->id)->orderBy('id')->get();
        $this->assertSame(['work-id', 'holidays-id', 'offset-id'], $added->pluck('remote_id')->all());
        $this->assertSame([$base . ' (2)', 'Holidays NL ' . $base, $base . ' (3)'], $added->pluck('name')->all());
        $this->assertSame('#abcdef', $added[0]->color);
        $this->assertContains($added[1]->color, SettingsPage::SWATCHES);
        $this->assertContains($added[2]->color, SettingsPage::SWATCHES);
        $this->assertSame(['Asia/Calcutta', null, null], $added->pluck('timezone')->all());
        $this->assertSame([false, true, false], $added->map(function (Calendar $calendar) {
            return (bool) $calendar->remote_read_only;
        })->all());
        foreach ($added as $calendar) {
            $this->assertSame([Calendar::PROVIDER_GOOGLE, true], [$calendar->provider, (bool) $calendar->enabled]);
            self::assertDueNow($calendar->next_sync_at);
        }
        $response->assertRedirect(SettingsPage::url());
        $response->assertSessionHas('flash_success_floating', e(__('Added :names. Open each calendar to choose who can use it.', ['names' => '"' . $base . ' (2)", "Holidays NL ' . $base . '", "' . $base . ' (3)"'])));
    }

    public function test_a_double_click_on_add_calendars_adds_each_calendar_once(): void
    {
        $fake = $this->fake(Calendar::PROVIDER_GOOGLE);
        $connection = $this->connection(Calendar::PROVIDER_GOOGLE);
        $fake->calendars = [new RemoteCalendar('team-id', 'Team ' . uniqid(), null, null, true), new RemoteCalendar('desk-id', 'Desk ' . uniqid(), null, null, true)];
        // The first click adds "team-id" while the second click lists the account.
        $first = null;
        Event::listen(TransactionBeginning::class, function () use (&$first, $connection) {
            $first = $first ?? $this->remoteCalendar('team-id', 'Team ' . uniqid(), ['connection_id' => $connection->id]);
        });

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.connections.calendars', ['id' => $connection->id]), ['calendars' => ['team-id', 'desk-id']])
            ->assertRedirect(SettingsPage::url());

        $this->assertSame(['desk-id', 'team-id'], Calendar::where('connection_id', $connection->id)->orderBy('remote_id')->pluck('remote_id')->all());
    }

    public function test_a_name_without_text_or_a_very_long_one_still_makes_a_valid_unique_name(): void
    {
        $fake = $this->fake(Calendar::PROVIDER_GOOGLE);
        $connection = $this->connection(Calendar::PROVIDER_GOOGLE);
        $long = str_repeat('Planning ', 30) . uniqid();
        $fake->calendars = [new RemoteCalendar('empty-id', ' <b></b> ', null, null, true), new RemoteCalendar('long-id', $long, null, null, true)];
        Calendar::where('name', __('Calendar'))->delete(); // so the fallback name is free (rolled back with the test)

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.connections.calendars', ['id' => $connection->id]), ['calendars' => ['empty-id', 'long-id']])
            ->assertRedirect(SettingsPage::url());

        $names = Calendar::where('connection_id', $connection->id)->orderBy('id')->pluck('name')->all();
        $this->assertSame([__('Calendar'), mb_substr($long, 0, 180)], $names);
    }

    public function test_nothing_is_added_without_a_new_pick_or_when_the_listing_fails(): void
    {
        $admin = $this->makeAdmin();
        $fake = $this->fake(Calendar::PROVIDER_GOOGLE);
        $connection = $this->connection(Calendar::PROVIDER_GOOGLE);
        $fake->calendars = [new RemoteCalendar('team-id', 'Team', null, null, true)];
        $this->remoteCalendar('team-id', 'Team ' . uniqid(), ['connection_id' => $connection->id]);
        $add = route('ljpccalendarmodule.settings.connections.calendars', ['id' => $connection->id]);
        $before = Calendar::count();

        $this->actingAs($admin)->post($add, [])
            ->assertRedirect(SettingsPage::connectionsUrl($connection->id))
            ->assertSessionHasErrors(['calendars' => __('Choose at least one calendar.')]);
        $this->actingAs($admin)->post($add, ['calendars' => ['team-id']])
            ->assertSessionHasErrors(['calendars' => __('These calendars are already added.')]);
        $fake->listError = new ProviderUnavailable('Google is limiting requests. The next sync tries again.', 429);
        $this->actingAs($admin)->post($add, ['calendars' => ['team-id']])
            ->assertRedirect(SettingsPage::connectionsUrl($connection->id))
            ->assertSessionHasErrors(['calendars' => 'Google is limiting requests. The next sync tries again.']);

        $this->assertSame($before, Calendar::count());
    }

    public function test_a_shared_mailbox_needs_an_email_address(): void
    {
        $admin = $this->makeAdmin();
        $fake = $this->fake(Calendar::PROVIDER_MICROSOFT);
        $connection = $this->connection(Calendar::PROVIDER_MICROSOFT);
        $before = Calendar::count();

        $html = $this->html(SettingsPage::connectionsUrl($connection->id, 'not an address'), $admin);
        $this->assertStringContainsString(e(__('Enter the email address of the shared mailbox.')), $html);
        $this->assertStringNotContainsString('name="calendars[]"', $html);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.connections.calendars', ['id' => $connection->id]), ['owner' => 'not an address', 'calendars' => ['users/x/calendars/a']])
            ->assertSessionHasErrors(['owner' => __('Enter the email address of the shared mailbox.')]);

        $this->assertSame([], $fake->calls('listCalendars'));
        $this->assertSame($before, Calendar::count());
    }

    public function test_a_shared_mailbox_is_listed_and_added_with_its_address(): void
    {
        $admin = $this->makeAdmin();
        $fake = $this->fake(Calendar::PROVIDER_MICROSOFT);
        $connection = $this->connection(Calendar::PROVIDER_MICROSOFT);
        $fake->calendars = [new RemoteCalendar('users/support@example.com/calendars/AAMk', 'Support planning', '#3498db', null, true)];

        $html = $this->html(SettingsPage::connectionsUrl($connection->id, 'Support@Example.com'), $admin);
        $this->assertStringContainsString('<input type="hidden" name="owner" value="support@example.com">', $html);
        $this->assertStringContainsString('support@example.com', $html);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.connections.calendars', ['id' => $connection->id]), ['owner' => 'support@example.com', 'calendars' => ['users/support@example.com/calendars/AAMk']])
            ->assertRedirect(SettingsPage::url());

        $this->assertSame(['support@example.com', 'support@example.com'], array_column($fake->calls('listCalendars'), 'owner'));
        $this->assertSame(1, Calendar::where('connection_id', $connection->id)->where('remote_id', 'users/support@example.com/calendars/AAMk')->count());

        // Other providers have no shared mailboxes: the address is ignored.
        $google = $this->fake(Calendar::PROVIDER_GOOGLE);
        $this->html(SettingsPage::connectionsUrl($this->connection(Calendar::PROVIDER_GOOGLE)->id, 'support@example.com'), $admin);
        $this->assertNull($google->calls('listCalendars')[0]['owner']);
    }

    public function test_deleting_a_connection_deletes_its_calendars_and_their_events_and_nothing_else(): void
    {
        $admin = $this->makeAdmin();
        $doomed = $this->davConnection();
        $kept = $this->davConnection(['account' => 'other-user']);
        $first = Calendar::create(['name' => 'Doomed A ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $doomed->id, 'remote_id' => self::SERVER . '/dav/a/']);
        $second = Calendar::create(['name' => 'Doomed B ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $doomed->id, 'remote_id' => self::SERVER . '/dav/b/']);
        $other = Calendar::create(['name' => 'Kept ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $kept->id, 'remote_id' => self::SERVER . '/dav/c/']);
        $local = Calendar::create(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $events = [$this->event($first), $this->event($second), $this->event($other), $this->event($local)];
        $delete = route('ljpccalendarmodule.settings.connections.delete', ['id' => $doomed->id]);

        $this->actingAs($this->makeUser())->post($delete)->assertStatus(403);
        $this->actingAs($admin)->post($delete)
            ->assertRedirect(SettingsPage::connectionsUrl())
            ->assertSessionHas('flash_success_floating', __('The connection and its calendars were deleted.'));

        $this->assertNull(CalendarConnection::find($doomed->id));
        $this->assertSame([], Calendar::whereIn('id', [$first->id, $second->id])->pluck('id')->all());
        $this->assertSame([$events[2], $events[3]], DB::table('calendar_events')->whereIn('id', $events)->orderBy('id')->pluck('id')->map('intval')->all());
        $this->assertSame(2, DB::table('calendar_occurrences')->whereIn('event_id', $events)->count());
        $this->assertNotNull(CalendarConnection::find($kept->id));
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.connections.delete', ['id' => $doomed->id]))->assertStatus(404);
    }

    public function test_the_delete_confirmation_names_the_calendars_it_deletes(): void
    {
        $dav = $this->davConnection();
        $google = $this->connection(Calendar::PROVIDER_GOOGLE);
        $unused = $this->connection(Calendar::PROVIDER_MICROSOFT);
        $suffix = uniqid();
        Calendar::create(['name' => 'Support "desk" ' . $suffix, 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $dav->id, 'remote_id' => self::SERVER . '/dav/a/']);
        Calendar::create(['name' => 'Agenda ' . $suffix, 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $dav->id, 'remote_id' => self::SERVER . '/dav/b/']);
        $this->remoteCalendar('team-id', 'Team ' . $suffix, ['connection_id' => $google->id]);

        $html = $this->html(SettingsPage::connectionsUrl());

        $confirm = function (CalendarConnection $connection) use ($html): string {
            preg_match('/action="' . preg_quote(route('ljpccalendarmodule.settings.connections.delete', ['id' => $connection->id]), '/') . '" data-ljpc-confirm="([^"]*)"/', $html, $match);

            return html_entity_decode($match[1] ?? '', ENT_QUOTES);
        };
        $this->assertSame(__('Delete this connection and its calendars :names? Their events are removed from FreeScout, not from :provider.', ['names' => '"Agenda ' . $suffix . '", "Support "desk" ' . $suffix . '"', 'provider' => __('the CalDAV server')]), $confirm($dav));
        $this->assertSame(__('Delete this connection and its calendars :names? Their events are removed from FreeScout, not from :provider.', ['names' => '"Team ' . $suffix . '"', 'provider' => 'Google']), $confirm($google));
        $this->assertSame(__('Delete this connection? No calendar uses it.'), $confirm($unused));
    }

    public function test_deleting_a_calendar_keeps_its_connection(): void
    {
        $admin = $this->makeAdmin();
        $connection = $this->davConnection();
        $calendar = Calendar::create(['name' => 'Dav ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $connection->id, 'remote_id' => self::SERVER . '/dav/a/']);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.delete', ['id' => $calendar->id]))->assertRedirect(SettingsPage::url());

        $this->assertNotNull(CalendarConnection::find($connection->id));
        $html = $this->html(SettingsPage::connectionsUrl(), $admin);
        $row = substr($html, strpos($html, 'id="ljpc-connection-' . $connection->id . '"'));
        $this->assertStringContainsString('<td class="ljpc-count">0</td>', substr($row, 0, strpos($row, '</tr>')));
    }

    public function test_agents_cannot_use_the_connection_actions(): void
    {
        $user = $this->makeUser();
        $connection = $this->davConnection();

        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.connections.caldav'), ['server_url' => self::SERVER, 'username' => 'u', 'password' => 'p'])->assertStatus(403);
        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.connections.calendars', ['id' => $connection->id]), ['calendars' => ['x']])->assertStatus(403);
        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.connections.delete', ['id' => $connection->id]))->assertStatus(403);
        $this->actingAs($user)->get(SettingsPage::connectionsUrl($connection->id))->assertStatus(403);

        $this->assertNotNull(CalendarConnection::find($connection->id));
        $this->assertSame([], $this->http->history());
    }

    public function test_a_calendar_the_account_may_only_read_is_not_writable_and_offers_only_view_rights(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeUser();
        $this->fake(Calendar::PROVIDER_GOOGLE);
        $connection = $this->connection(Calendar::PROVIDER_GOOGLE);
        $readOnly = $this->remoteCalendar('holidays-id', 'Holidays ' . uniqid(), ['connection_id' => $connection->id, 'remote_read_only' => true]);
        $writable = $this->remoteCalendar('work-id', 'Work ' . uniqid(), ['connection_id' => $connection->id]);
        $feed = Calendar::create(['name' => 'Feed ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS, 'remote_id' => 'https://93.184.215.14/x.ics']);

        $this->assertSame([false, true], [$readOnly->isWritable(), $readOnly->isReadOnly()]);
        $this->assertSame([true, false], [$writable->isWritable(), $writable->isReadOnly()]);
        $this->assertSame([false, true], [$feed->isWritable(), $feed->isReadOnly()]);

        foreach ([$readOnly, $feed] as $calendar) {
            $html = $this->html(SettingsPage::url($calendar->id), $admin);
            $this->assertStringContainsString('name="permissions[user][' . $agent->id . '][can_view]"', $html);
            $this->assertStringContainsString('name="permissions[user][' . $agent->id . '][show_in_dashboard]"', $html);
            $this->assertStringNotContainsString('name="permissions[user][' . $agent->id . '][can_create]"', $html);
            $this->assertStringNotContainsString('name="permissions[user][' . $agent->id . '][can_edit]"', $html);
            $this->assertStringContainsString(e(__('This calendar is read-only, so people can only view its events.')), $html);
        }
        $this->assertStringContainsString(e(__('The account may only read this calendar, so its events can\'t be changed from FreeScout.')), $this->html(SettingsPage::url($readOnly->id), $admin));
        $html = $this->html(SettingsPage::url($writable->id), $admin);
        $this->assertStringContainsString('name="permissions[user][' . $agent->id . '][can_edit]"', $html);
        $this->assertStringNotContainsString(e(__('This calendar is read-only, so people can only view its events.')), $html);

        // The form doesn't offer the write rights, and a crafted post can't set them. A calendar the account may only
        // read keeps the stored ones, though: they count again once the account may write. A feed never can.
        $other = $this->makeUser();
        $rights = function (Calendar $calendar, $user): array {
            $row = CalendarPermission::where('calendar_id', $calendar->id)->where('subject_type', 'user')->where('subject_id', $user->id)->first();

            return $row ? [(bool) $row->can_view, (bool) $row->can_create, (bool) $row->can_edit] : [];
        };
        $save = function (Calendar $calendar, array $permissions) use ($admin) {
            $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]), [
                'name' => $calendar->name, 'color' => '#3498db', 'enabled' => '1', 'display_priority' => '200', 'form_complete' => '1',
                'permissions' => ['user' => $permissions],
            ])->assertSessionMissing('errors');
        };
        $gone = $this->makeUser();
        foreach ([$readOnly, $feed] as $calendar) {
            CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $agent->id, 'can_view' => true, 'can_edit' => true]);
            CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $other->id, 'can_create' => true]);
            CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $gone->id, 'can_edit' => true]);
        }
        $gone->update(['status' => \App\User::STATUS_DELETED]);
        foreach ([$readOnly, $feed] as $calendar) {
            $save($calendar, [$agent->id => ['can_view' => '1', 'can_create' => '1']]);
        }

        $this->assertSame([true, false, true], $rights($readOnly, $agent));
        $this->assertSame([false, true, false], $rights($readOnly, $other));
        $this->assertSame([], $rights($readOnly, $gone), 'a deleted user keeps nothing');
        $this->assertSame([true, false, false], $rights($feed, $agent));
        $this->assertSame([], $rights($feed, $other));
        $readOnly->update(['remote_read_only' => false]);
        $this->assertTrue($agent->can('edit', $readOnly->fresh()));
    }

    public function test_the_pick_screen_refreshes_the_read_only_flag_of_added_calendars_only(): void
    {
        $admin = $this->makeAdmin();
        $fake = $this->fake(Calendar::PROVIDER_GOOGLE);
        $connection = $this->connection(Calendar::PROVIDER_GOOGLE);
        $calendar = $this->remoteCalendar('shared-id', 'Shared ' . uniqid(), ['connection_id' => $connection->id]);
        $calendar->update(['next_sync_at' => '2030-01-01 00:00:00', 'sync_state' => ['sync_token' => 'token-1', '_generation' => 3, '_listing' => false]]);
        $event = $this->event($calendar, false, '"e1"');
        // The same Google calendar added through another account keeps its own flag.
        $other = $this->remoteCalendar('shared-id', 'Shared elsewhere ' . uniqid(), ['connection_id' => $this->connection(Calendar::PROVIDER_GOOGLE)->id]);

        // The owner took the write access away.
        $fake->calendars = [new RemoteCalendar('shared-id', 'Shared', null, null, false)];
        $this->html(SettingsPage::connectionsUrl($connection->id), $admin);
        $this->assertTrue($calendar->fresh()->remote_read_only);
        $this->assertFalse($calendar->fresh()->isWritable());
        $this->assertFalse($other->fresh()->remote_read_only);

        // Write access is back: the flag is all that changes. The calendar's events never stored it.
        $fake->calendars = [new RemoteCalendar('shared-id', 'Shared', null, null, true)];
        $this->html(SettingsPage::connectionsUrl($connection->id), $admin);
        $fresh = $calendar->fresh();
        $this->assertFalse($fresh->remote_read_only);
        $this->assertTrue($fresh->isWritable());
        $this->assertSame(['2030-01-01 00:00:00', 'token-1'], [$fresh->next_sync_at, $fresh->sync_state['sync_token']]);
        $this->assertSame('"e1"', DB::table('calendar_events')->where('id', $event)->value('etag'));
    }

    public function test_a_zone_php_knows_only_by_its_old_name_survives_a_save(): void
    {
        $admin = $this->makeAdmin();
        $calendar = $this->remoteCalendar('india-id', 'India ' . uniqid(), ['connection_id' => $this->connection(Calendar::PROVIDER_GOOGLE)->id, 'timezone' => 'Asia/Calcutta']);

        $this->assertMatchesRegularExpression('/<option value="Asia\/Calcutta"\s+selected\s*>Asia\/Calcutta<\/option>/', $this->html(SettingsPage::url($calendar->id), $admin));

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]), [
            'name' => $calendar->name, 'color' => '#3498db', 'enabled' => '1', 'display_priority' => '200', 'timezone' => 'Asia/Calcutta', 'form_complete' => '1',
        ])->assertSessionMissing('errors');
        $this->assertSame('Asia/Calcutta', $calendar->fresh()->timezone);
        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]), [
            'name' => $calendar->name, 'color' => '#3498db', 'enabled' => '1', 'display_priority' => '200', 'timezone' => 'Asia/Rangoon', 'form_complete' => '1',
        ])->assertSessionHasErrors(['timezone']);
    }
}
