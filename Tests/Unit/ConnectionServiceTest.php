<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\Option;
use App\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Modules\LJPcCalendarModule\Services\SyncService;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;

class ConnectionServiceTest extends TestCase
{
    private const GOOGLE_SCOPES = 'https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events';
    private const MICROSOFT_SCOPES = 'offline_access User.Read Calendars.ReadWrite Calendars.ReadWrite.Shared';

    private FakeHttp $http;
    private ConnectionService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = FakeHttp::install();
        $this->service = app(ConnectionService::class);
        $this->admin = $this->makeAdmin();
        $this->service->saveAppCredentials('google', ['client_id' => 'test-client-id.apps.example.com', 'client_secret' => 'test-google-secret']);
        $this->service->saveAppCredentials('microsoft', ['client_id' => 'test-client-id', 'client_secret' => 'test-microsoft-secret', 'tenant' => 'example.com', 'secret_expires_at' => '2027-03-31']);
    }

    private static function answer(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** The callback request the provider sends the browser back with. */
    private function returnFromProvider(array $query): Request
    {
        $request = Request::create('/calendar/oauth/x/callback', 'GET', $query);
        $request->setUserResolver(function () {
            return $this->admin;
        });

        return $request;
    }

    /** Starts a sign-in and returns its callback with the right state and a code. */
    private function signedIn(string $provider, ?CalendarConnection $reauthorize = null): Request
    {
        $state = self::query($this->service->authorizationUrl($provider, $reauthorize))['state'];

        return $this->returnFromProvider(['state' => $state, 'code' => 'test-code']);
    }

    private function tokens(array $override = []): Response
    {
        return self::answer(200, $override + ['access_token' => 'test-access-1', 'expires_in' => 3599, 'refresh_token' => 'test-refresh-1', 'scope' => self::GOOGLE_SCOPES, 'token_type' => 'Bearer']);
    }

    private function connection(string $provider, array $credentials, array $attributes = []): CalendarConnection
    {
        $connection = new CalendarConnection($attributes + ['provider' => $provider, 'account' => 'agent@example.com']);
        $connection->setCredentials($credentials + ['refresh_token' => 'test-refresh-1', 'access_token' => 'test-access-1', 'expires_at' => time() + 3600, 'scopes' => self::GOOGLE_SCOPES]);
        $connection->save();

        return $connection;
    }

    public function test_the_google_authorize_url_asks_for_offline_access_with_state_and_pkce(): void
    {
        $url = $this->service->authorizationUrl('google');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $query = self::query($url);
        $entry = session(ConnectionService::SESSION_KEY);
        $this->assertSame(['state', 'verifier', 'provider', 'connection_id', 'created_at'], array_keys($entry));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['state']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['verifier']);
        $this->assertSame(['google', null], [$entry['provider'], $entry['connection_id']]);
        $this->assertEqualsWithDelta(time(), $entry['created_at'], 5);
        $this->assertSame([
            'client_id'             => 'test-client-id.apps.example.com',
            'redirect_uri'          => route('ljpccalendarmodule.oauth.callback', ['provider' => 'google']),
            'response_type'         => 'code',
            'scope'                 => self::GOOGLE_SCOPES,
            'state'                 => $entry['state'],
            'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $entry['verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'include_granted_scopes' => 'true',
        ], $query);
        $this->assertStringContainsString('scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fcalendar.calendarlist.readonly%20https', $url);
        $this->assertSame(url('/calendar/oauth/google/callback'), $this->service->redirectUrl('google'));
    }

    public function test_the_microsoft_authorize_url_uses_the_tenant_and_lets_the_admin_pick_an_account(): void
    {
        $connection = $this->connection('microsoft', []);

        $url = $this->service->authorizationUrl('microsoft', $connection);

        $this->assertStringStartsWith('https://login.microsoftonline.com/example.com/oauth2/v2.0/authorize?', $url);
        $query = self::query($url);
        $this->assertSame(['test-client-id', 'code', self::MICROSOFT_SCOPES, 'select_account', 'query', 'S256'], [
            $query['client_id'], $query['response_type'], $query['scope'], $query['prompt'], $query['response_mode'], $query['code_challenge_method'],
        ]);
        $this->assertSame(route('ljpccalendarmodule.oauth.callback', ['provider' => 'microsoft']), $query['redirect_uri']);
        $this->assertArrayNotHasKey('access_type', $query);
        $this->assertSame(['microsoft', $connection->id], [session(ConnectionService::SESSION_KEY)['provider'], session(ConnectionService::SESSION_KEY)['connection_id']]);
        // Every sign-in gets its own state and verifier.
        $this->service->authorizationUrl('microsoft');
        $this->assertNotSame($query['state'], session(ConnectionService::SESSION_KEY)['state']);
    }

    public function test_the_authorize_url_needs_the_apps_credentials(): void
    {
        Option::remove('ljpccalendarmodule.google_client_secret');
        Option::set('ljpccalendarmodule.microsoft_tenant', '');
        $cases = ['google' => 'Fill in the client id and secret of the Google app first.', 'microsoft' => 'Fill in the tenant, client id and secret of the Microsoft app first.'];

        foreach ($cases as $provider => $message) {
            try {
                $this->service->authorizationUrl($provider);
                $this->fail('Started a sign-in without the app of ' . $provider);
            } catch (ValidationException $e) {
                $this->assertSame([$message], $e->errors()['oauth']);
            }
        }
        $this->assertNull(session(ConnectionService::SESSION_KEY));
    }

    public function test_the_callback_refuses_a_missing_a_wrong_an_expired_or_another_providers_sign_in(): void
    {
        $cases = [
            'no sign-in started' => function () {
                session()->forget(ConnectionService::SESSION_KEY);

                return $this->returnFromProvider(['state' => str_repeat('a', 64), 'code' => 'test-code']);
            },
            'wrong state'        => function () {
                $this->service->authorizationUrl('google');

                return $this->returnFromProvider(['state' => str_repeat('a', 64), 'code' => 'test-code']);
            },
            'state as an array'  => function () {
                $this->service->authorizationUrl('google');

                return $this->returnFromProvider(['state' => [session(ConnectionService::SESSION_KEY)['state']], 'code' => 'test-code']);
            },
            'expired'            => function () {
                $request = $this->signedIn('google');
                session()->put(ConnectionService::SESSION_KEY . '.created_at', time() - ConnectionService::STATE_SECONDS - 1);

                return $request;
            },
            'other provider'     => function () {
                return $this->signedIn('microsoft');
            },
        ];
        foreach ($cases as $case => $request) {
            try {
                $this->service->completeAuthorization('google', $request());
                $this->fail('Accepted a callback: ' . $case);
            } catch (ValidationException $e) {
                $this->assertSame(['This sign-in has expired or was not started here. Connect the account again.'], $e->errors()['oauth'], $case);
            }
            $this->assertNull(session(ConnectionService::SESSION_KEY), $case);
        }
        $this->assertSame([], $this->http->history());
        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_a_refusal_from_the_provider_becomes_its_message(): void
    {
        $state = self::query($this->service->authorizationUrl('google'))['state'];

        try {
            $this->service->completeAuthorization('google', $this->returnFromProvider(['state' => $state, 'error' => 'access_denied', 'error_description' => 'The user denied <b>access</b>.']));
            $this->fail('Accepted a refused sign-in');
        } catch (ValidationException $e) {
            $this->assertSame(['Google did not connect the account: The user denied <b>access</b>.'], $e->errors()['oauth']);
        }
        $this->assertNull(session(ConnectionService::SESSION_KEY));
        $this->assertSame([], $this->http->history());
    }

    public function test_the_code_is_exchanged_and_the_google_account_stored(): void
    {
        $request = $this->signedIn('google');
        $verifier = session(ConnectionService::SESSION_KEY)['verifier'];
        $this->http->queue($this->tokens(), self::answer(200, ['id' => 'Agent@Example.com', 'summary' => 'Agent', 'primary' => true]));

        $connection = $this->service->completeAuthorization('google', $request);

        [$exchange, $account] = $this->http->history();
        $this->assertSame(['POST', 'https://oauth2.googleapis.com/token'], [$exchange['method'], $exchange['url']]);
        $this->assertSame(['application/x-www-form-urlencoded'], $exchange['headers']['Content-Type']);
        parse_str($exchange['body'], $body);
        $this->assertSame([
            'grant_type'    => 'authorization_code',
            'code'          => 'test-code',
            'code_verifier' => $verifier,
            'redirect_uri'  => url('/calendar/oauth/google/callback'),
            'client_id'     => 'test-client-id.apps.example.com',
            'client_secret' => 'test-google-secret',
        ], $body);
        $this->assertSame(['GET', ConnectionService::GOOGLE_ACCOUNT_URL, ['Bearer test-access-1']], [$account['method'], $account['url'], $account['headers']['Authorization']]);

        $connection = $connection->fresh();
        $this->assertSame(['google', 'agent@example.com', CalendarConnection::STATUS_OK, null, $this->admin->id], [$connection->provider, $connection->account, $connection->status, $connection->last_error, $connection->created_by]);
        $credentials = $connection->credentials();
        $this->assertSame(['refresh_token', 'access_token', 'expires_at', 'scopes'], array_keys($credentials));
        $this->assertSame(['test-refresh-1', 'test-access-1', self::GOOGLE_SCOPES], [$credentials['refresh_token'], $credentials['access_token'], $credentials['scopes']]);
        $this->assertEqualsWithDelta(time() + 3599, $credentials['expires_at'], 5);
        $this->assertNull(session(ConnectionService::SESSION_KEY));
    }

    public function test_microsoft_names_the_account_by_mail_or_else_user_principal_name(): void
    {
        $this->http->queue($this->tokens(['scope' => self::MICROSOFT_SCOPES]), self::answer(200, ['mail' => null, 'userPrincipalName' => 'Agent@example.com']));
        $first = $this->service->completeAuthorization('microsoft', $this->signedIn('microsoft'));
        $this->http->queue($this->tokens(['scope' => self::MICROSOFT_SCOPES]), self::answer(200, ['mail' => 'shared.desk@example.com', 'userPrincipalName' => 'desk@example.com']));
        $second = $this->service->completeAuthorization('microsoft', $this->signedIn('microsoft'));

        $this->assertSame(['agent@example.com', 'shared.desk@example.com'], [$first->account, $second->account]);
        $history = $this->http->history();
        $this->assertSame('https://login.microsoftonline.com/example.com/oauth2/v2.0/token', $history[0]['url']);
        parse_str($history[0]['body'], $body);
        $this->assertSame([self::MICROSOFT_SCOPES, 'test-microsoft-secret'], [$body['scope'], $body['client_secret']]);
        $this->assertSame(['GET', 'https://graph.microsoft.com/v1.0/me?$select=id,mail,userPrincipalName', ['Bearer test-access-1']], [$history[1]['method'], $history[1]['url'], $history[1]['headers']['Authorization']]);
    }

    public function test_an_answer_without_a_refresh_token_is_refused_with_the_providers_advice(): void
    {
        $cases = [
            'google'    => "Google did not return a refresh token. Remove the app's access in your Google account and connect again.",
            'microsoft' => 'Microsoft did not return a refresh token. Check that the app has the offline_access permission.',
        ];
        foreach ($cases as $provider => $message) {
            $this->http->queue(self::answer(200, ['access_token' => 'test-access-1', 'expires_in' => 3599, 'scope' => $provider === 'google' ? self::GOOGLE_SCOPES : self::MICROSOFT_SCOPES]));
            try {
                $this->service->completeAuthorization($provider, $this->signedIn($provider));
                $this->fail('Connected without a refresh token: ' . $provider);
            } catch (ValidationException $e) {
                $this->assertSame([$message], $e->errors()['oauth']);
            }
        }
        $this->assertSame(0, CalendarConnection::count());
        $this->assertSame(0, $this->http->pending());
    }

    public function test_google_must_grant_both_calendar_permissions(): void
    {
        // Google lets the user untick permissions on the consent screen.
        $this->http->queue($this->tokens(['scope' => 'https://www.googleapis.com/auth/calendar.calendarlist.readonly']));

        try {
            $this->service->completeAuthorization('google', $this->signedIn('google'));
            $this->fail('Connected without access to the events');
        } catch (ValidationException $e) {
            $this->assertSame(['Google did not grant access to the calendars. Connect the account again and allow every permission it asks for.'], $e->errors()['oauth']);
        }
        $this->assertCount(1, $this->http->history());
        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_a_refused_code_and_a_wrong_app_secret_get_their_own_messages(): void
    {
        $this->http->queue(self::answer(400, ['error' => 'invalid_grant', 'error_description' => 'Bad Request']), self::answer(401, ['error' => 'invalid_client', 'error_description' => 'Unauthorized']));

        try {
            $this->service->completeAuthorization('google', $this->signedIn('google'));
            $this->fail('Accepted a refused code');
        } catch (ValidationException $e) {
            $this->assertSame(['Google did not accept this sign-in. Connect the account again.'], $e->errors()['oauth']);
        }
        try {
            $this->service->completeAuthorization('google', $this->signedIn('google'));
            $this->fail('Accepted a wrong client secret');
        } catch (ProviderUnavailable $e) {
            $this->assertSame(["Google refused the app's client id or secret. Check the Google app on the Connections tab.", 401], [$e->userMessage(), $e->httpStatus()]);
        }
    }

    public function test_token_and_account_requests_follow_no_redirects_and_wait_at_most_15_seconds(): void
    {
        $options = [];
        $this->http->queue(
            function ($request, array $requestOptions) use (&$options) {
                $options[] = $requestOptions;

                return $this->tokens();
            },
            function ($request, array $requestOptions) use (&$options) {
                $options[] = $requestOptions;

                return self::answer(200, ['id' => 'agent@example.com']);
            }
        );
        $this->service->completeAuthorization('google', $this->signedIn('google'));

        foreach ($options as $requestOptions) {
            $this->assertFalse($requestOptions['allow_redirects']);
            $this->assertEquals(ConnectionService::TIMEOUT_SECONDS, $requestOptions['timeout']);
        }
        // A redirect would send the client secret or the token to another address: it counts as a failure.
        $this->http->queue(new Response(307, ['Location' => 'https://elsewhere.example.com/token']));
        try {
            $this->service->completeAuthorization('google', $this->signedIn('google'));
            $this->fail('Followed a redirect of the token endpoint');
        } catch (ProviderUnavailable $e) {
            $this->assertSame(307, $e->httpStatus());
        }
        $this->assertCount(3, $this->http->history());
    }

    public function test_reauthorizing_a_connection_without_an_account_cannot_take_another_connections_account(): void
    {
        $taken = $this->connection('google', []);
        $connection = $this->connection('google', [], ['account' => null]);
        $this->http->queue($this->tokens(), self::answer(200, ['id' => 'agent@example.com']));

        try {
            $this->service->completeAuthorization('google', $this->signedIn('google', $connection));
            $this->fail('Gave two connections one account');
        } catch (ValidationException $e) {
            $this->assertSame(['agent@example.com is already connected.'], $e->errors()['oauth']);
        }
        $this->assertNull($connection->fresh()->account);
        $this->assertSame('test-refresh-1', $taken->fresh()->credentials()['refresh_token']);
    }

    public function test_connecting_an_account_again_updates_its_connection(): void
    {
        $existing = $this->connection('google', ['refresh_token' => 'test-refresh-old'], ['status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => 'The account must be connected again.']);
        $this->http->queue($this->tokens(), self::answer(200, ['id' => 'agent@example.com']));

        $connection = $this->service->completeAuthorization('google', $this->signedIn('google'));

        $this->assertSame($existing->id, $connection->id);
        $this->assertSame(1, CalendarConnection::count());
        $this->assertSame([CalendarConnection::STATUS_OK, null, 'test-refresh-1'], [$connection->fresh()->status, $connection->fresh()->last_error, $connection->fresh()->credentials()['refresh_token']]);
    }

    public function test_reauthorizing_needs_the_same_account(): void
    {
        $connection = $this->connection('google', [], ['status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => 'The account must be connected again.']);
        $this->http->queue($this->tokens(['refresh_token' => 'test-refresh-other']), self::answer(200, ['id' => 'other@example.com']));

        try {
            $this->service->completeAuthorization('google', $this->signedIn('google', $connection));
            $this->fail('Reauthorized with another account');
        } catch (ValidationException $e) {
            $this->assertSame(['You signed in as other@example.com, but this connection belongs to agent@example.com.'], $e->errors()['oauth']);
        }
        $this->assertSame([CalendarConnection::STATUS_NEEDS_REAUTH, 'test-refresh-1'], [$connection->fresh()->status, $connection->fresh()->credentials()['refresh_token']]);
        $this->assertSame(1, CalendarConnection::count());
    }

    public function test_reauthorizing_stores_the_new_tokens_and_clears_the_problem(): void
    {
        $connection = $this->connection('microsoft', [], ['account' => 'Agent@Example.com', 'status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => 'The account must be connected again.', 'created_by' => null]);
        $this->http->queue($this->tokens(['refresh_token' => 'test-refresh-2', 'access_token' => 'test-access-2', 'scope' => self::MICROSOFT_SCOPES]), self::answer(200, ['mail' => 'agent@example.com']));

        $updated = $this->service->completeAuthorization('microsoft', $this->signedIn('microsoft', $connection));

        $this->assertSame($connection->id, $updated->id);
        $fresh = $connection->fresh();
        $this->assertSame([CalendarConnection::STATUS_OK, null, 'test-refresh-2', 'test-access-2'], [$fresh->status, $fresh->last_error, $fresh->credentials()['refresh_token'], $fresh->credentials()['access_token']]);
    }

    public function test_a_reauthorization_wins_over_a_refresh_with_the_old_token_that_lands_while_it_runs(): void
    {
        $connection = $this->connection('google', ['refresh_token' => 'test-refresh-old']);
        $request = $this->signedIn('google', $connection);
        // A sync's refresh with the old refresh token is refused and marks the account while the sign-in completes.
        $this->http->queue(function () use ($connection) {
            CalendarConnection::whereKey($connection->id)->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => 'The account must be connected again.']);

            return $this->tokens();
        }, self::answer(200, ['id' => 'agent@example.com']));

        $this->service->completeAuthorization('google', $request);

        $fresh = $connection->fresh();
        $this->assertSame([CalendarConnection::STATUS_OK, null, 'test-refresh-1'], [$fresh->status, $fresh->last_error, $fresh->credentials()['refresh_token']]);
    }

    public function test_reauthorizing_makes_the_accounts_calendars_due_again(): void
    {
        app(ProviderRegistry::class)->swap('google', (new FakeProvider(true))->onPull(new ChangeSet([], [], [], true, false)));
        $connection = $this->connection('google', [], ['status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => 'The account must be connected again.']);
        $failing = ['sync_interval' => 1440, 'sync_failures' => 3, 'next_sync_at' => gmdate('Y-m-d H:i:s', time() + 4 * 86400), 'last_error' => 'The account must be connected again.'];
        $calendar = Calendar::create(['name' => 'Team ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $connection->id, 'remote_id' => 'team@example.com'] + $failing);
        $other = Calendar::create(['name' => 'Other ' . uniqid(), 'provider' => Calendar::PROVIDER_GOOGLE, 'remote_id' => 'other@example.com'] + $failing);
        $this->http->queue($this->tokens(), self::answer(200, ['id' => 'agent@example.com']));

        $this->service->completeAuthorization('google', $this->signedIn('google', $connection));

        $this->assertSame(0, $calendar->fresh()->sync_failures);
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), (string) $calendar->fresh()->next_sync_at);
        $this->assertSame(['synced' => 1, 'failed' => 0, 'skipped' => 0], app(SyncService::class)->runDue(SyncBudget::seconds(30), $calendar->id));
        $this->assertSame([3, $failing['next_sync_at']], [$other->fresh()->sync_failures, (string) $other->fresh()->next_sync_at]);
    }

    public function test_a_microsoft_account_is_known_by_its_id_when_its_address_changed(): void
    {
        $tokens = $this->tokens(['scope' => self::MICROSOFT_SCOPES]);
        // Connected under the old address; Graph's id for the user stays when the address changes.
        $this->http->queue(clone $tokens, self::answer(200, ['id' => '00000000-0000-4000-8000-0000000000b1', 'mail' => 'Old.Name@example.com', 'userPrincipalName' => 'old.name@example.com']));
        $connection = $this->service->completeAuthorization('microsoft', $this->signedIn('microsoft'));
        $this->assertSame(['old.name@example.com', '00000000-0000-4000-8000-0000000000b1'], [$connection->account, $connection->fresh()->credentials()['account_id']]);
        $connection->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);

        $renamed = self::answer(200, ['id' => '00000000-0000-4000-8000-0000000000b1', 'mail' => 'new.name@example.com', 'userPrincipalName' => 'new.name@example.com']);
        $this->http->queue(clone $tokens, clone $renamed);
        $this->service->completeAuthorization('microsoft', $this->signedIn('microsoft', $connection));
        $this->assertSame([CalendarConnection::STATUS_OK, 'new.name@example.com'], [$connection->fresh()->status, $connection->fresh()->account]);

        // Connecting the account again (not through "Reauthorize") finds the same connection.
        $this->http->queue(clone $tokens, clone $renamed);
        $this->assertSame($connection->id, $this->service->completeAuthorization('microsoft', $this->signedIn('microsoft'))->id);
        $this->assertSame(1, CalendarConnection::where('provider', 'microsoft')->count());

        // Another user is another account, also when it got the old address.
        $this->http->queue(clone $tokens, self::answer(200, ['id' => '00000000-0000-4000-8000-0000000000b2', 'mail' => 'old.name@example.com']));
        try {
            $this->service->completeAuthorization('microsoft', $this->signedIn('microsoft', $connection));
            $this->fail('Reauthorized with another account');
        } catch (ValidationException $e) {
            $this->assertSame(['You signed in as old.name@example.com, but this connection belongs to new.name@example.com.'], $e->errors()['oauth']);
        }
        // A refresh keeps the id.
        $connection->update(['status' => CalendarConnection::STATUS_OK]);
        $fresh = $connection->fresh();
        $fresh->setCredentials(['access_token' => 'test-access-old', 'expires_at' => time() - 10] + $fresh->credentials());
        $fresh->save();
        $this->http->queue($this->tokens(['access_token' => 'test-access-9', 'scope' => self::MICROSOFT_SCOPES]));
        $this->service->accessToken($fresh);
        $this->assertSame('00000000-0000-4000-8000-0000000000b1', $connection->fresh()->credentials()['account_id']);
    }

    public function test_a_microsoft_connection_stored_without_an_id_is_matched_by_its_address_and_gets_the_id(): void
    {
        $connection = $this->connection('microsoft', [], ['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->http->queue($this->tokens(['scope' => self::MICROSOFT_SCOPES]), self::answer(200, ['id' => '00000000-0000-4000-8000-0000000000b3', 'mail' => 'agent@example.com']));

        $this->service->completeAuthorization('microsoft', $this->signedIn('microsoft', $connection));

        $this->assertSame([CalendarConnection::STATUS_OK, '00000000-0000-4000-8000-0000000000b3'], [$connection->fresh()->status, $connection->fresh()->credentials()['account_id']]);
    }

    public function test_reauthorizing_a_connection_that_was_deleted_meanwhile_fails(): void
    {
        $connection = $this->connection('google', []);
        $request = $this->signedIn('google', $connection);
        $connection->delete();

        try {
            $this->service->completeAuthorization('google', $request);
            $this->fail('Reauthorized a deleted connection');
        } catch (ValidationException $e) {
            $this->assertSame(['This connection no longer exists.'], $e->errors()['oauth']);
        }
        $this->assertSame([], $this->http->history());
    }

    public function test_a_valid_access_token_is_used_as_it_is(): void
    {
        $connection = $this->connection('google', ['expires_at' => time() + ConnectionService::REFRESH_SECONDS + 60]);

        $this->assertSame('test-access-1', $this->service->accessToken($connection));
        $this->assertSame([], $this->http->history());
    }

    public function test_a_token_that_expires_soon_is_refreshed_and_a_rotated_refresh_token_stored(): void
    {
        $connection = $this->connection('microsoft', ['expires_at' => time() + 200]);
        $this->http->queue(self::answer(200, ['access_token' => 'test-access-2', 'expires_in' => 3600, 'refresh_token' => 'test-refresh-2', 'scope' => self::MICROSOFT_SCOPES]));

        $this->assertSame('test-access-2', $this->service->accessToken($connection));

        [$refresh] = $this->http->history();
        $this->assertSame(['POST', 'https://login.microsoftonline.com/example.com/oauth2/v2.0/token'], [$refresh['method'], $refresh['url']]);
        parse_str($refresh['body'], $body);
        $this->assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'test-refresh-1', 'client_id' => 'test-client-id', 'client_secret' => 'test-microsoft-secret', 'scope' => self::MICROSOFT_SCOPES], $body);
        $credentials = $connection->fresh()->credentials();
        $this->assertSame(['test-access-2', 'test-refresh-2'], [$credentials['access_token'], $credentials['refresh_token']]);
        $this->assertEqualsWithDelta(time() + 3600, $credentials['expires_at'], 5);
        // The caller's model holds the new token too, so a second call needs no request.
        $this->assertSame('test-access-2', $this->service->accessToken($connection));
        $this->assertCount(1, $this->http->history());
    }

    public function test_a_refresh_without_a_new_refresh_token_keeps_the_old_one(): void
    {
        $connection = $this->connection('google', ['expires_at' => time() - 10]);
        $this->http->queue(self::answer(200, ['access_token' => 'test-access-2', 'expires_in' => 3599, 'scope' => self::GOOGLE_SCOPES]));

        $this->assertSame('test-access-2', $this->service->accessToken($connection));

        $this->assertSame(['test-access-2', 'test-refresh-1'], [$connection->fresh()->credentials()['access_token'], $connection->fresh()->credentials()['refresh_token']]);
    }

    public function test_a_refreshed_token_is_used_even_when_it_lives_shorter_than_the_margin(): void
    {
        $connection = $this->connection('google', ['expires_at' => time() - 10]);
        $this->http->queue(self::answer(200, ['access_token' => 'test-access-short', 'expires_in' => 120, 'scope' => self::GOOGLE_SCOPES]));

        $this->assertSame('test-access-short', $this->service->accessToken($connection));
        $this->assertSame(CalendarConnection::STATUS_OK, $connection->fresh()->status);
    }

    public function test_a_token_another_process_refreshed_meanwhile_is_used_without_a_request(): void
    {
        $connection = $this->connection('google', ['expires_at' => time() + 100]);
        // Another process refreshed it after this one read the connection.
        $other = CalendarConnection::find($connection->id);
        $other->setCredentials(['refresh_token' => 'test-refresh-1', 'access_token' => 'test-access-other', 'expires_at' => time() + 3600, 'scopes' => self::GOOGLE_SCOPES]);
        $other->save();

        $this->assertSame('test-access-other', $this->service->accessToken($connection));
        $this->assertSame([], $this->http->history());
    }

    public function test_a_token_the_provider_refused_is_renewed_although_it_has_not_expired(): void
    {
        $connection = $this->connection('google', ['expires_at' => time() + 3000]);
        $this->http->queue(self::answer(200, ['access_token' => 'test-access-2', 'expires_in' => 3599, 'scope' => self::GOOGLE_SCOPES]));

        $this->assertSame('test-access-2', $this->service->renewAccessToken($connection, 'test-access-1'));

        [$refresh] = $this->http->history();
        parse_str($refresh['body'], $body);
        $this->assertSame(['refresh_token', 'test-refresh-1'], [$body['grant_type'], $body['refresh_token']]);
        $this->assertSame(['test-access-2', 'test-refresh-1'], [$connection->fresh()->credentials()['access_token'], $connection->fresh()->credentials()['refresh_token']]);
        $this->assertSame('test-access-2', $this->service->accessToken($connection));
        $this->assertCount(1, $this->http->history());
    }

    public function test_a_refused_token_another_process_renewed_meanwhile_is_replaced_without_a_request(): void
    {
        $connection = $this->connection('google', ['expires_at' => time() + 3000]);
        $other = CalendarConnection::find($connection->id);
        $other->setCredentials(['refresh_token' => 'test-refresh-1', 'access_token' => 'test-access-other', 'expires_at' => time() + 3600, 'scopes' => self::GOOGLE_SCOPES]);
        $other->save();

        $this->assertSame('test-access-other', $this->service->renewAccessToken($connection, 'test-access-1'));
        $this->assertSame([], $this->http->history());

        // A connection that needs reauthorization is never renewed.
        $connection->status = CalendarConnection::STATUS_NEEDS_REAUTH;
        $connection->save();
        $this->expectException(ConnectionNeedsReauth::class);
        try {
            $this->service->renewAccessToken($connection, 'test-access-other');
        } finally {
            $this->assertSame([], $this->http->history());
        }
    }

    public function test_a_revoked_grant_marks_the_connection_for_reauthorization(): void
    {
        $cases = [
            'google'    => self::answer(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']),
            'microsoft' => self::answer(400, ['error' => 'interaction_required', 'error_description' => 'AADSTS50076: Due to a configuration change made by your administrator, you must use multi-factor authentication.', 'error_codes' => [50076]]),
        ];
        foreach ($cases as $provider => $answer) {
            $connection = $this->connection($provider, ['expires_at' => time() + 10], ['account' => $provider . '@example.com']);
            $this->http->queue($answer);

            try {
                $this->service->accessToken($connection);
                $this->fail('Returned a token for a revoked grant: ' . $provider);
            } catch (ConnectionNeedsReauth $e) {
                $this->assertSame(400, $e->httpStatus());
            }
            $fresh = $connection->fresh();
            $this->assertSame([CalendarConnection::STATUS_NEEDS_REAUTH, 'The account must be connected again.'], [$fresh->status, $fresh->last_error], $provider);
            $this->assertSame(CalendarConnection::STATUS_NEEDS_REAUTH, $connection->status, $provider);
            $this->assertSame('test-refresh-1', $fresh->credentials()['refresh_token'], $provider);
        }

        // From then on no request is made until an administrator connects the account again.
        $this->expectException(ConnectionNeedsReauth::class);
        try {
            $this->service->accessToken($connection);
        } finally {
            $this->assertCount(2, $this->http->history());
        }
    }

    public function test_network_and_server_errors_leave_the_connection_as_it_is(): void
    {
        $connection = $this->connection('google', ['expires_at' => time() + 10]);
        $this->http->queue(
            new ConnectException('cURL error 7: Failed to connect to oauth2.googleapis.com port 443', new Psr7Request('POST', 'https://oauth2.googleapis.com/token')),
            new Response(503, [], 'Service Unavailable')
        );

        foreach ([null, 503] as $status) {
            try {
                $this->service->accessToken($connection);
                $this->fail('Returned a token without an answer');
            } catch (ProviderUnavailable $e) {
                $this->assertSame($status, $e->httpStatus());
            }
        }
        $fresh = $connection->fresh();
        $this->assertSame([CalendarConnection::STATUS_OK, null, 'test-access-1'], [$fresh->status, $fresh->last_error, $fresh->credentials()['access_token']]);
    }

    public function test_secrets_and_tokens_never_reach_the_logs(): void
    {
        $logs = LogRecorder::install();
        $this->http->queue(self::answer(500, ['error' => 'server_error', 'refresh_token' => 'test-refresh-echo', 'access_token' => 'test-access-echo', 'client_secret' => 'test-google-secret']));
        try {
            $this->service->completeAuthorization('google', $this->signedIn('google'));
        } catch (ProviderUnavailable $e) {
            $this->assertSame(500, $e->httpStatus());
        }
        $connection = $this->connection('google', ['expires_at' => time() + 10]);
        $this->http->queue(self::answer(400, ['error' => 'invalid_grant', 'error_description' => 'refresh_token=test-refresh-1 is revoked']));
        try {
            $this->service->accessToken($connection);
        } catch (ConnectionNeedsReauth $e) {
            $this->assertSame(400, $e->httpStatus());
        }

        $records = $logs->records();
        $this->assertCount(2, $records);
        $logged = json_encode($records);
        foreach (['test-refresh-echo', 'test-access-echo', 'test-google-secret', 'test-refresh-1', 'test-access-1', 'test-code'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
        $this->assertSame(['connection_id', 'provider', 'status', 'body'], array_keys($records[1]['context']));
    }

    public function test_credentials_stay_out_of_serialized_connections(): void
    {
        $connection = $this->connection('google', []);

        $this->assertArrayNotHasKey('credentials', $connection->fresh()->toArray());
        foreach (['test-refresh-1', 'test-access-1'] as $secret) {
            $this->assertStringNotContainsString($secret, $connection->fresh()->toJson());
            $this->assertStringNotContainsString($secret, (string) \DB::table('calendar_connections')->where('id', $connection->id)->value('credentials'));
        }
    }

    public function test_the_app_secret_is_stored_encrypted_and_kept_when_the_field_is_left_empty(): void
    {
        $stored = (string) Option::get('ljpccalendarmodule.microsoft_client_secret', '', true, false);
        $this->assertStringNotContainsString('test-microsoft-secret', $stored);
        $this->assertSame('test-microsoft-secret', decrypt($stored));

        $this->service->saveAppCredentials('microsoft', ['client_id' => 'test-client-id-2', 'client_secret' => '', 'tenant' => 'organizations', 'secret_expires_at' => '']);

        $this->assertSame(['client_id' => 'test-client-id-2', 'has_secret' => true, 'tenant' => 'organizations', 'secret_expires_at' => null], $this->service->appCredentials('microsoft'));
        $this->assertSame('test-microsoft-secret', decrypt((string) Option::get('ljpccalendarmodule.microsoft_client_secret', '', true, false)));
        $this->assertSame(['client_id' => 'test-client-id.apps.example.com', 'has_secret' => true], $this->service->appCredentials('google'));
    }

    public function test_the_app_credentials_are_validated(): void
    {
        $cases = [
            ['google', ['client_id' => ''], 'client_id'],
            ['microsoft', ['client_id' => 'test-client-id'], 'tenant'],
            ['microsoft', ['client_id' => 'test-client-id', 'tenant' => 'example.com/../common'], 'tenant'],
            ['microsoft', ['client_id' => 'test-client-id', 'tenant' => str_repeat('a', 101)], 'tenant'],
            ['microsoft', ['client_id' => 'test-client-id', 'tenant' => 'example.com', 'secret_expires_at' => '31-03-2027'], 'secret_expires_at'],
            ['google', ['client_id' => ['test-client-id']], 'client_id'],
        ];
        foreach ($cases as [$provider, $input, $key]) {
            try {
                $this->service->saveAppCredentials($provider, $input);
                $this->fail('Saved ' . json_encode($input));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors(), json_encode($input));
            }
        }
        $this->assertSame('example.com', $this->service->appCredentials('microsoft')['tenant']);
        $this->assertSame('2027-03-31', $this->service->appCredentials('microsoft')['secret_expires_at']);
    }
}
