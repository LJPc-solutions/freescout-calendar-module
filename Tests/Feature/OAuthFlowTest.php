<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Option;
use GuzzleHttp\Psr7\Response;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** Settings > Calendar > Connections: the app credentials, and connecting a Google or Microsoft account through OAuth. */
class OAuthFlowTest extends TestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = FakeHttp::install();
    }

    private function saveGoogleApp(): void
    {
        app(ConnectionService::class)->saveAppCredentials('google', ['client_id' => 'test-client-id.apps.example.com', 'client_secret' => 'test-google-secret']);
    }

    private static function answer(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** A sign-in the admin started: the session entry the callback checks. */
    private static function started(string $provider, ?int $connectionId = null): array
    {
        return [ConnectionService::SESSION_KEY => [
            'state' => str_repeat('5', 64), 'verifier' => str_repeat('v', 64), 'provider' => $provider, 'connection_id' => $connectionId, 'created_at' => time(),
        ]];
    }

    public function test_only_admins_reach_the_connect_actions(): void
    {
        $this->saveGoogleApp();
        $agent = $this->makeUser();

        $this->actingAs($agent)->get(route('ljpccalendarmodule.oauth.start', ['provider' => 'google']))->assertStatus(403);
        $this->actingAs($agent)->withSession(self::started('google'))->get(route('ljpccalendarmodule.oauth.callback', ['provider' => 'google']) . '?state=' . str_repeat('5', 64) . '&code=test-code')->assertStatus(403);
        $this->actingAs($agent)->post(route('ljpccalendarmodule.settings.apps', ['provider' => 'google']), ['client_id' => 'hacked', 'client_secret' => 'hacked'])->assertStatus(403);

        $this->assertSame([], $this->http->history());
        $this->assertSame('test-client-id.apps.example.com', app(ConnectionService::class)->appCredentials('google')['client_id']);
        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_only_google_and_microsoft_connect_through_oauth(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/calendar/oauth/caldav/start')->assertStatus(404);
        $this->actingAs($admin)->get('/calendar/oauth/caldav/callback')->assertStatus(404);
        $this->actingAs($admin)->post('/calendar/settings/apps/caldav', ['client_id' => 'x'])->assertStatus(404);
    }

    public function test_start_sends_the_admin_to_the_provider(): void
    {
        $this->saveGoogleApp();

        $response = $this->actingAs($this->makeAdmin())->get(route('ljpccalendarmodule.oauth.start', ['provider' => 'google']));

        $response->assertStatus(302);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?client_id=test-client-id.apps.example.com&', $response->headers->get('Location'));
        $this->assertSame('google', session(ConnectionService::SESSION_KEY)['provider']);
    }

    public function test_start_without_the_app_returns_to_the_connections_tab(): void
    {
        $response = $this->actingAs($this->makeAdmin())->get(route('ljpccalendarmodule.oauth.start', ['provider' => 'microsoft']));

        $response->assertRedirect(SettingsPage::connectionsUrl());
        $response->assertSessionHas('flash_error_floating', 'Fill in the tenant, client id and secret of the Microsoft app first.');
        $this->assertNull(session(ConnectionService::SESSION_KEY));
    }

    public function test_reauthorizing_needs_a_connection_of_that_provider(): void
    {
        $this->saveGoogleApp();
        $admin = $this->makeAdmin();
        $google = CalendarConnection::create(['provider' => 'google', 'account' => 'agent@example.com']);
        $caldav = CalendarConnection::create(['provider' => 'caldav', 'account' => 'agent', 'server_url' => 'https://dav.example.com']);

        $this->actingAs($admin)->get(route('ljpccalendarmodule.oauth.start', ['provider' => 'google', 'connection' => $caldav->id]))->assertStatus(404);
        $this->actingAs($admin)->get(route('ljpccalendarmodule.oauth.start', ['provider' => 'google', 'connection' => 'x']))->assertStatus(404);
        $this->actingAs($admin)->get(route('ljpccalendarmodule.oauth.start', ['provider' => 'google', 'connection' => $google->id]))->assertStatus(302);

        $this->assertSame($google->id, session(ConnectionService::SESSION_KEY)['connection_id']);
    }

    public function test_the_callback_connects_the_account_and_opens_its_calendar_choice(): void
    {
        $this->saveGoogleApp();
        $this->http->queue(
            self::answer(200, ['access_token' => 'test-access-1', 'expires_in' => 3599, 'refresh_token' => 'test-refresh-1', 'scope' => 'https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events']),
            self::answer(200, ['id' => 'agent@example.com'])
        );

        $response = $this->actingAs($this->makeAdmin())->withSession(self::started('google'))
            ->get(route('ljpccalendarmodule.oauth.callback', ['provider' => 'google']) . '?state=' . str_repeat('5', 64) . '&code=test-code&scope=x');

        $connection = CalendarConnection::where('provider', 'google')->firstOrFail();
        $response->assertRedirect(SettingsPage::connectionsUrl($connection->id));
        $this->assertStringEndsWith('?tab=connections&connection=' . $connection->id . '&pick=1', SettingsPage::connectionsUrl($connection->id));
        $response->assertSessionHas('flash_success_floating', 'agent@example.com is connected. Choose the calendars FreeScout shows.');
        $this->assertSame('agent@example.com', $connection->account);
    }

    public function test_a_failed_callback_returns_to_the_connections_tab_with_the_reason(): void
    {
        $this->saveGoogleApp();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->withSession(self::started('google'))
            ->get(route('ljpccalendarmodule.oauth.callback', ['provider' => 'google']) . '?state=' . str_repeat('6', 64) . '&code=test-code')
            ->assertRedirect(SettingsPage::connectionsUrl())
            ->assertSessionHas('flash_error_floating', 'This sign-in has expired or was not started here. Connect the account again.');

        // The provider's own words are shown as text: the floating flash message does not escape HTML.
        $this->actingAs($admin)->withSession(self::started('google'))
            ->get(route('ljpccalendarmodule.oauth.callback', ['provider' => 'google']) . '?' . http_build_query(['state' => str_repeat('5', 64), 'error' => 'access_denied', 'error_description' => 'Denied <img src=x onerror=alert(1)>']))
            ->assertRedirect(SettingsPage::connectionsUrl())
            ->assertSessionHas('flash_error_floating', 'Google did not connect the account: Denied &lt;img src=x onerror=alert(1)&gt;');

        $this->http->queue(new Response(503, [], 'Service Unavailable'));
        $this->actingAs($admin)->withSession(self::started('google'))
            ->get(route('ljpccalendarmodule.oauth.callback', ['provider' => 'google']) . '?state=' . str_repeat('5', 64) . '&code=test-code')
            ->assertRedirect(SettingsPage::connectionsUrl())
            ->assertSessionHas('flash_error_floating', 'The calendar provider could not be reached. Try again later.');

        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_the_app_credentials_are_saved_from_the_connections_tab(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.apps', ['provider' => 'microsoft']), [
            'client_id' => 'test-client-id', 'client_secret' => 'test-microsoft-secret', 'tenant' => 'example.com', 'secret_expires_at' => '2027-03-31',
        ])->assertRedirect(SettingsPage::connectionsUrl())->assertSessionHas('flash_success_floating', 'Microsoft app saved.');

        $this->assertSame(['client_id' => 'test-client-id', 'has_secret' => true, 'tenant' => 'example.com', 'secret_expires_at' => '2027-03-31'], app(ConnectionService::class)->appCredentials('microsoft'));
        $this->assertSame('test-microsoft-secret', decrypt(Option::get('ljpccalendarmodule.microsoft_client_secret', '', true, false)));
    }

    public function test_an_invalid_app_form_shows_its_errors_without_keeping_the_secret(): void
    {
        $response = $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.apps', ['provider' => 'microsoft']), [
            'client_id' => 'test-client-id', 'client_secret' => 'test-microsoft-secret', 'tenant' => 'not a tenant', 'app' => 'google',
        ]);

        $response->assertRedirect(SettingsPage::connectionsUrl());
        $errors = session('errors');
        $this->assertTrue($errors->getBag('microsoft')->has('tenant'));
        $this->assertFalse($errors->getBag('google')->any());
        // The old input says which app form it belongs to, because both forms have a client_id.
        $this->assertSame(['app' => 'microsoft', 'client_id' => 'test-client-id', 'tenant' => 'not a tenant'], session('_old_input'));
        $this->assertFalse(app(ConnectionService::class)->appCredentials('microsoft')['has_secret']);
    }
}
