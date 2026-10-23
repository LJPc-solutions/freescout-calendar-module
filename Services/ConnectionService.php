<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Option;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;

/**
 * Connects Google and Microsoft accounts: the authorization code flow with state and PKCE (S256), the code exchange,
 * the account lookup, access tokens refreshed under a row lock, and the app credentials an administrator enters.
 * Tokens and secrets are stored encrypted, and only scrubbed answers are ever logged.
 */
class ConnectionService
{
    public const PROVIDERS = ['google', 'microsoft'];
    public const SESSION_KEY = 'ljpccalendarmodule.oauth';
    /** How long a started sign-in stays valid. */
    public const STATE_SECONDS = 600;
    /** An access token that expires within this time is refreshed before it is used. */
    public const REFRESH_SECONDS = 300;
    /** How long a token or account request may take; accessToken() holds a row lock meanwhile. */
    public const TIMEOUT_SECONDS = 15;
    public const GOOGLE_ACCOUNT_URL = 'https://www.googleapis.com/calendar/v3/users/me/calendarList/primary';
    public const MICROSOFT_ACCOUNT_URL = 'https://graph.microsoft.com/v1.0/me?$select=id,mail,userPrincipalName';

    /** Token endpoint errors that mean the account must sign in again (research, questions 5 and 14). */
    private const GRANT_ERRORS = ['invalid_grant', 'interaction_required'];
    /** Token endpoint errors that mean the app registration's id or secret is wrong, expired or not allowed. */
    private const APP_ERRORS = ['invalid_client', 'unauthorized_client'];
    private const LABELS = ['google' => 'Google', 'microsoft' => 'Microsoft'];

    private HttpClientFactory $http;

    public function __construct(HttpClientFactory $http)
    {
        $this->http = $http;
    }

    public function redirectUrl(string $provider): string
    {
        self::assertProvider($provider);

        return route('ljpccalendarmodule.oauth.callback', ['provider' => $provider]);
    }

    /** Stores state, PKCE verifier, provider and the connection being reauthorized in the session; returns the provider's authorize URL. */
    public function authorizationUrl(string $provider, ?CalendarConnection $reauthorize = null): string
    {
        $app = $this->app($provider);
        if ($app === null) {
            throw ValidationException::withMessages(['oauth' => self::appMissing($provider)]);
        }
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        session()->put(self::SESSION_KEY, [
            'state'         => $state,
            'verifier'      => $verifier,
            'provider'      => $provider,
            'connection_id' => $reauthorize ? $reauthorize->id : null,
            'created_at'    => time(),
        ]);
        $params = [
            'client_id'             => $app['client_id'],
            'redirect_uri'          => $this->redirectUrl($provider),
            'response_type'         => 'code',
            'scope'                 => (string) config('ljpccalendarmodule.oauth.' . $provider . '.scopes'),
            'state'                 => $state,
            'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        $params += $provider === 'google'
            ? ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true']
            : ['prompt' => 'select_account', 'response_mode' => 'query'];

        return $this->endpoint($provider, 'authorize', $app) . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Verifies state, exchanges the code, creates or updates the connection (same provider + account updates). */
    public function completeAuthorization(string $provider, Request $request): CalendarConnection
    {
        self::assertProvider($provider);
        // Removed whatever happens next: a sign-in is answered once.
        $entry = session()->pull(self::SESSION_KEY);
        $state = $request->query('state');
        if (!is_array($entry) || ($entry['provider'] ?? null) !== $provider || !is_string($entry['state'] ?? null) || !is_string($entry['verifier'] ?? null)
            || time() - (int) ($entry['created_at'] ?? 0) > self::STATE_SECONDS || !is_string($state) || !hash_equals($entry['state'], $state)) {
            throw ValidationException::withMessages(['oauth' => __('This sign-in has expired or was not started here. Connect the account again.')]);
        }
        $error = $request->query('error');
        if ($error !== null) {
            $description = $request->query('error_description');
            $reason = is_string($description) && trim($description) !== '' ? $description : (is_string($error) ? $error : '');
            throw ValidationException::withMessages(['oauth' => __(':provider did not connect the account: :reason', ['provider' => self::LABELS[$provider], 'reason' => mb_substr($reason, 0, 300)])]);
        }
        $code = $request->query('code');
        if (!is_string($code) || $code === '') {
            throw ValidationException::withMessages(['oauth' => __(':provider did not accept this sign-in. Connect the account again.', ['provider' => self::LABELS[$provider]])]);
        }
        $app = $this->app($provider);
        if ($app === null) {
            throw ValidationException::withMessages(['oauth' => self::appMissing($provider)]);
        }
        $connection = null;
        if (!empty($entry['connection_id'])) {
            $connection = CalendarConnection::where('provider', $provider)->find((int) $entry['connection_id']);
            if ($connection === null) {
                throw ValidationException::withMessages(['oauth' => __('This connection no longer exists.')]);
            }
        }

        $tokens = $this->tokenRequest($provider, $app, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $entry['verifier'],
            'redirect_uri'  => $this->redirectUrl($provider),
        ], $connection ? $connection->id : null);
        if (is_int($tokens)) {
            throw ValidationException::withMessages(['oauth' => __(':provider did not accept this sign-in. Connect the account again.', ['provider' => self::LABELS[$provider]])]);
        }
        if (!is_string($tokens['refresh_token'] ?? null) || $tokens['refresh_token'] === '') {
            throw ValidationException::withMessages(['oauth' => $provider === 'google'
                ? __("Google did not return a refresh token. Remove the app's access in your Google account and connect again.")
                : __('Microsoft did not return a refresh token. Check that the app has the offline_access permission.')]);
        }
        // Google lets the user untick permissions on its consent screen (granular consent).
        if ($provider === 'google' && is_string($tokens['scope'] ?? null)
            && array_diff(explode(' ', (string) config('ljpccalendarmodule.oauth.google.scopes')), explode(' ', $tokens['scope']))) {
            throw ValidationException::withMessages(['oauth' => __('Google did not grant access to the calendars. Connect the account again and allow every permission it asks for.')]);
        }
        [$account, $accountId] = $this->account($provider, (string) $tokens['access_token'], $connection ? $connection->id : null);

        if ($connection !== null) {
            if (!self::isAccount($connection, $account, $accountId)) {
                throw ValidationException::withMessages(['oauth' => __('You signed in as :account, but this connection belongs to :expected.', ['account' => $account, 'expected' => $connection->account])]);
            }
            if ($connection->account === null && self::connectionOf($provider, $account, $accountId, $connection->id) !== null) {
                throw ValidationException::withMessages(['oauth' => __(':account is already connected.', ['account' => $account])]);
            }
        } else {
            $connection = self::connectionOf($provider, $account, $accountId)
                ?? new CalendarConnection(['provider' => $provider, 'created_by' => $request->user() ? $request->user()->id : null]);
        }
        // The address is what the account is shown as; Microsoft's user id is what it is known by, so a renamed user
        // still finds its connection.
        $connection->account = $account;
        $connection->setCredentials(self::credentials($tokens, null, $accountId));
        $connection->status = CalendarConnection::STATUS_OK;
        $connection->last_error = null;
        // A sync whose refresh with the old token was refused while this sign-in ran may have marked the row needs_reauth;
        // Eloquent saves no status it loaded as ok, so it is written again, in the same transaction as the new tokens.
        DB::transaction(function () use ($connection) {
            $connection->save();
            CalendarConnection::whereKey($connection->id)->update(['status' => CalendarConnection::STATUS_OK, 'last_error' => null]);
            $connection->resumeCalendars();
        });

        return $connection;
    }

    /**
     * A valid access token; refreshes under a row lock when it expires within 5 minutes. invalid_grant sets needs_reauth
     * and throws ConnectionNeedsReauth. Never call it inside a database transaction: the lock would last until that
     * transaction ends, and rolling it back would also undo needs_reauth.
     */
    public function accessToken(CalendarConnection $connection): string
    {
        if ($connection->status === CalendarConnection::STATUS_NEEDS_REAUTH) {
            throw new ConnectionNeedsReauth();
        }
        $token = self::validToken($connection->credentials());
        if ($token !== null) {
            return $token;
        }

        return $this->refresh($connection, null);
    }

    /**
     * A new access token after the provider refused $rejected (HTTP 401) before it expired: refreshed under the row lock,
     * unless another process stored a different valid token meanwhile. Fails like accessToken(), and like it never runs
     * inside a database transaction.
     */
    public function renewAccessToken(CalendarConnection $connection, string $rejected): string
    {
        if ($connection->status === CalendarConnection::STATUS_NEEDS_REAUTH) {
            throw new ConnectionNeedsReauth();
        }

        return $this->refresh($connection, $rejected);
    }

    /** Refreshes the access token under a row lock; a valid stored token other than $rejected is used as it is. */
    private function refresh(CalendarConnection $connection, ?string $rejected): string
    {
        // The row lock makes a second process wait, and then find the token the first one stored. Microsoft hands out a
        // new refresh token on every refresh, so two refreshes at once could each store a different one.
        [$row, $refused] = DB::transaction(function () use ($connection, $rejected) {
            $row = CalendarConnection::whereKey($connection->id)->lockForUpdate()->first();
            if ($row === null || $row->status === CalendarConnection::STATUS_NEEDS_REAUTH) {
                return [$row, null];
            }
            $credentials = $row->credentials();
            $valid = self::validToken($credentials);
            if ($valid !== null && $valid !== $rejected) {
                return [$row, null];
            }
            $app = $this->app($row->provider);
            if ($app === null) {
                throw new ProviderUnavailable(self::appMissing($row->provider));
            }
            $refreshToken = $credentials['refresh_token'] ?? null;
            $tokens = is_string($refreshToken) && $refreshToken !== ''
                ? $this->tokenRequest($row->provider, $app, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $row->id)
                : 0;
            if (is_int($tokens)) {
                // Saved here and thrown after the commit: an exception inside the transaction would roll it back.
                $row->status = CalendarConnection::STATUS_NEEDS_REAUTH;
                $row->last_error = __('The account must be connected again.');
                $row->save();

                return [$row, $tokens];
            }
            $row->setCredentials(self::credentials($tokens, $credentials));
            $row->save();

            return [$row, null];
        });
        if ($row === null) {
            throw new ConnectionNeedsReauth();
        }
        $connection->setRawAttributes($row->getAttributes(), true);
        if ($connection->status === CalendarConnection::STATUS_NEEDS_REAUTH) {
            throw new ConnectionNeedsReauth($refused ?: null);
        }

        // Valid, or just refreshed (then used even when the provider gave it less than REFRESH_SECONDS).
        return (string) $connection->credentials()['access_token'];
    }

    /** client_id, has_secret; for Microsoft also tenant and secret_expires_at (Y-m-d or null). */
    public function appCredentials(string $provider): array
    {
        self::assertProvider($provider);
        $credentials = [
            'client_id'  => (string) Option::get(self::option($provider, 'client_id'), '', true, false),
            'has_secret' => (string) Option::get(self::option($provider, 'client_secret'), '', true, false) !== '',
        ];
        if ($provider === 'microsoft') {
            $credentials['tenant'] = (string) Option::get(self::option($provider, 'tenant'), '', true, false);
            $credentials['secret_expires_at'] = ((string) Option::get(self::option($provider, 'secret_expires_at'), '', true, false)) ?: null;
        }

        return $credentials;
    }

    /** Validates and stores the app credentials. The secret is stored encrypted and kept when the field is empty. */
    public function saveAppCredentials(string $provider, array $input): void
    {
        self::assertProvider($provider);
        $rules = ['client_id' => 'required|string|max:191', 'client_secret' => 'nullable|string|max:500'];
        $messages = [];
        if ($provider === 'microsoft') {
            $rules['tenant'] = ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9.\-]+$/'];
            $rules['secret_expires_at'] = 'nullable|date_format:Y-m-d';
            $messages['tenant.regex'] = __('Enter the directory (tenant) id or a domain of the organization.');
        }
        Validator::make($input, $rules, $messages)->validate(); // returns nothing on Laravel 5.5
        $data = array_intersect_key($input, $rules);

        Option::set(self::option($provider, 'client_id'), trim($data['client_id']));
        $secret = trim((string) ($data['client_secret'] ?? ''));
        if ($secret !== '') {
            Option::set(self::option($provider, 'client_secret'), encrypt($secret));
        }
        if ($provider === 'microsoft') {
            Option::set(self::option($provider, 'tenant'), trim($data['tenant']));
            Option::set(self::option($provider, 'secret_expires_at'), (string) ($data['secret_expires_at'] ?? ''));
        }
    }

    /**
     * POSTs to the provider's token endpoint. Returns its answer, or the HTTP status when it refused the grant (the code
     * or refresh token is no longer valid, so the account must sign in again). Other failures throw ProviderUnavailable.
     */
    private function tokenRequest(string $provider, array $app, array $fields, ?int $connectionId): array|int
    {
        $fields += ['client_id' => $app['client_id'], 'client_secret' => $app['client_secret']];
        if ($provider === 'microsoft') {
            $fields['scope'] = (string) config('ljpccalendarmodule.oauth.microsoft.scopes');
        }
        $context = ['connection_id' => $connectionId, 'provider' => $provider];
        try {
            // A redirect would carry the secret, the code or the refresh token to another address, so none is followed.
            $response = $this->http->client(['timeout' => self::TIMEOUT_SECONDS, 'allow_redirects' => false])
                ->post($this->endpoint($provider, 'token', $app), ['form_params' => $fields, 'headers' => ['Accept' => 'application/json']]);
        } catch (GuzzleException $e) {
            HttpClientFactory::logFailure('An OAuth token request failed', $context + ['error' => $e->getMessage()]);
            throw new ProviderUnavailable();
        }
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $answer = json_decode($body, true);
        if ($status === 200 && is_array($answer) && is_string($answer['access_token'] ?? null) && $answer['access_token'] !== '') {
            return $answer;
        }
        HttpClientFactory::logFailure('An OAuth token request was refused', $context + ['status' => $status, 'body' => $body]);
        // Providers name the problem in "error"; its description is for people and changes without notice.
        $error = is_array($answer) && is_string($answer['error'] ?? null) ? $answer['error'] : '';
        if (in_array($error, self::GRANT_ERRORS, true)) {
            return $status;
        }
        if (in_array($error, self::APP_ERRORS, true)) {
            throw new ProviderUnavailable(__(":provider refused the app's client id or secret. Check the :provider app on the Connections tab.", ['provider' => self::LABELS[$provider]]), $status);
        }

        throw new ProviderUnavailable(null, $status);
    }

    /**
     * Whether the account that signed in is the one of this connection: by Microsoft's user id when the connection has
     * it (an address can change), otherwise by the address; a connection without an account takes any.
     */
    private static function isAccount(CalendarConnection $connection, string $account, ?string $accountId): bool
    {
        $storedId = $connection->credentials()['account_id'] ?? null;
        if (is_string($storedId) && $accountId !== null) {
            return $storedId === $accountId;
        }

        return $connection->account === null || mb_strtolower((string) $connection->account) === $account;
    }

    /**
     * The connection of this account: the one with Microsoft's user id, else one stored without an id under this
     * address (a connection made before the id was kept).
     */
    private static function connectionOf(string $provider, string $account, ?string $accountId, ?int $except = null): ?CalendarConnection
    {
        $query = CalendarConnection::where('provider', $provider)->orderBy('id');
        if ($except !== null) {
            $query->whereKeyNot($except);
        }
        $connections = $query->get();
        if ($accountId !== null) {
            $byId = $connections->first(function (CalendarConnection $connection) use ($accountId) {
                return ($connection->credentials()['account_id'] ?? null) === $accountId;
            });
            if ($byId !== null) {
                return $byId;
            }
        }

        return $connections->first(function (CalendarConnection $connection) use ($account) {
            return !isset($connection->credentials()['account_id']) && mb_strtolower((string) $connection->account) === $account;
        });
    }

    /**
     * The account that signed in, as [address, id]: Google's primary calendar id (its address) without an id, or
     * Microsoft's mail (or user principal name) with Graph's user id.
     *
     * @return array{0: string, 1: ?string}
     */
    private function account(string $provider, string $accessToken, ?int $connectionId): array
    {
        $url = $provider === 'google' ? self::GOOGLE_ACCOUNT_URL : self::MICROSOFT_ACCOUNT_URL;
        $context = ['connection_id' => $connectionId, 'provider' => $provider];
        try {
            $response = $this->http->client(['timeout' => self::TIMEOUT_SECONDS, 'allow_redirects' => false])
                ->get($url, ['headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']]);
        } catch (GuzzleException $e) {
            HttpClientFactory::logFailure('An OAuth account lookup failed', $context + ['error' => $e->getMessage()]);
            throw new ProviderUnavailable();
        }
        $body = (string) $response->getBody();
        $answer = json_decode($body, true);
        $account = null;
        if (is_array($answer)) {
            $account = $provider === 'google' ? ($answer['id'] ?? null) : (($answer['mail'] ?? null) ?: ($answer['userPrincipalName'] ?? null));
        }
        if ($response->getStatusCode() !== 200 || !is_string($account) || trim($account) === '') {
            HttpClientFactory::logFailure('An OAuth account lookup was refused', $context + ['status' => $response->getStatusCode(), 'body' => $body]);
            throw new ProviderUnavailable(null, $response->getStatusCode());
        }
        $id = $provider === 'microsoft' && is_string($answer['id'] ?? null) && trim($answer['id']) !== '' ? trim($answer['id']) : null;

        return [mb_strtolower(trim($account)), $id];
    }

    /** The app's client id, secret and (Microsoft) tenant, or null when one of them is missing. */
    private function app(string $provider): ?array
    {
        self::assertProvider($provider);
        $clientId = (string) Option::get(self::option($provider, 'client_id'), '', true, false);
        $secret = (string) Option::get(self::option($provider, 'client_secret'), '', true, false);
        $tenant = $provider === 'microsoft' ? (string) Option::get(self::option($provider, 'tenant'), '', true, false) : null;
        if ($clientId === '' || $secret === '' || $tenant === '') {
            return null;
        }

        return ['client_id' => $clientId, 'client_secret' => decrypt($secret), 'tenant' => $tenant];
    }

    private function endpoint(string $provider, string $kind, array $app): string
    {
        if ($provider === 'google') {
            return (string) config('ljpccalendarmodule.oauth.google.' . $kind . '_url');
        }

        return rtrim((string) config('ljpccalendarmodule.oauth.microsoft.authority'), '/') . '/' . rawurlencode((string) $app['tenant']) . '/oauth2/v2.0/' . $kind;
    }

    /**
     * The stored credentials for a token answer; a refresh without a new refresh token keeps the previous one, and a
     * refresh keeps the account's id (Microsoft).
     */
    private static function credentials(array $tokens, ?array $previous, ?string $accountId = null): array
    {
        $refreshToken = $tokens['refresh_token'] ?? null;
        $credentials = [
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : (string) ($previous['refresh_token'] ?? ''),
            'access_token'  => (string) $tokens['access_token'],
            'expires_at'    => time() + (int) ($tokens['expires_in'] ?? 3600),
            'scopes'        => is_string($tokens['scope'] ?? null) ? $tokens['scope'] : (string) ($previous['scopes'] ?? ''),
        ];
        $accountId = $accountId ?? ($previous['account_id'] ?? null);
        if (is_string($accountId) && $accountId !== '') {
            $credentials['account_id'] = $accountId;
        }

        return $credentials;
    }

    private static function validToken(array $credentials): ?string
    {
        $token = $credentials['access_token'] ?? null;

        return is_string($token) && $token !== '' && (int) ($credentials['expires_at'] ?? 0) > time() + self::REFRESH_SECONDS ? $token : null;
    }

    private static function appMissing(string $provider): string
    {
        return $provider === 'google'
            ? __('Fill in the client id and secret of the Google app first.')
            : __('Fill in the tenant, client id and secret of the Microsoft app first.');
    }

    private static function option(string $provider, string $key): string
    {
        return 'ljpccalendarmodule.' . $provider . '_' . $key;
    }

    private static function assertProvider(string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('Unknown OAuth provider "' . $provider . '".');
        }
    }
}
