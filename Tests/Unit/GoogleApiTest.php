<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\Google\GoogleApi;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;

class GoogleApiTest extends TestCase
{
    private const SCOPES = 'https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events';

    private FakeHttp $http;

    private GoogleApi $api;

    private CalendarConnection $connection;

    /** @var float[] the waits the API asked for, in seconds */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = FakeHttp::install();
        app(ConnectionService::class)->saveAppCredentials('google', ['client_id' => 'test-client-id.apps.example.com', 'client_secret' => 'test-google-secret']);
        $this->api = new GoogleApi($this->http, app(ConnectionService::class), function (float $seconds) {
            $this->sleeps[] = $seconds;
        });
        $this->connection = new CalendarConnection(['provider' => 'google', 'account' => 'agent@example.com']);
        $this->connection->setCredentials(['refresh_token' => 'test-refresh-1', 'access_token' => 'test-access-1', 'expires_at' => time() + 3600, 'scopes' => self::SCOPES]);
        $this->connection->save();
    }

    private static function answer(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json; charset=UTF-8'], json_encode($body));
    }

    /** Google's error body (calendar API errors guide). */
    private static function googleError(int $status, string $reason, string $message, array $headers = []): Response
    {
        return self::answer($status, ['error' => ['errors' => [['domain' => 'usageLimits', 'reason' => $reason, 'message' => $message]], 'code' => $status, 'message' => $message]], $headers);
    }

    private function token(string $accessToken): Response
    {
        return self::answer(200, ['access_token' => $accessToken, 'expires_in' => 3599, 'scope' => self::SCOPES, 'token_type' => 'Bearer']);
    }

    private function bearers(): array
    {
        return array_map(function (array $request) {
            return $request['method'] . ' ' . $request['url'] . ' ' . ($request['headers']['Authorization'][0] ?? '-');
        }, $this->http->history());
    }

    private function failure(callable $call): ProviderException
    {
        try {
            $call();
        } catch (ProviderException $e) {
            return $e;
        }
        $this->fail('The request did not fail');
    }

    public function test_requests_carry_the_accounts_token_and_follow_no_redirects(): void
    {
        $this->http->queue(self::answer(200, ['kind' => 'calendar#calendarList', 'items' => []]), new Response(302, ['Location' => 'https://elsewhere.example.com/']));

        [$status, $answer] = $this->api->request($this->connection, 'GET', 'users/me/calendarList', ['query' => ['maxResults' => 250]]);

        $this->assertSame([200, ['kind' => 'calendar#calendarList', 'items' => []]], [$status, $answer]);
        [$request] = $this->http->history();
        $this->assertSame(['GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250'], [$request['method'], $request['url']]);
        $this->assertSame(['Bearer test-access-1'], $request['headers']['Authorization']);
        $this->assertSame(['application/json'], $request['headers']['Accept']);
        // A redirect would carry the token to another address: it is an error instead.
        $e = $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'users/me/calendarList');
        });
        $this->assertSame([ProviderUnavailable::class, 302], [get_class($e), $e->httpStatus()]);
        $this->assertCount(2, $this->http->history());
    }

    public function test_a_refused_token_is_renewed_once_and_the_request_sent_again(): void
    {
        $refused = self::googleError(401, 'authError', 'Invalid Credentials');
        $this->http->queue($refused, $this->token('test-access-2'), self::answer(200, ['items' => []]));

        [$status] = $this->api->request($this->connection, 'GET', 'users/me/calendarList');

        $this->assertSame(200, $status);
        $this->assertSame([
            'GET https://www.googleapis.com/calendar/v3/users/me/calendarList Bearer test-access-1',
            'POST https://oauth2.googleapis.com/token -',
            'GET https://www.googleapis.com/calendar/v3/users/me/calendarList Bearer test-access-2',
        ], $this->bearers());

        // A renewed token that is refused as well means the account must be connected again.
        $this->http->queue($refused, $this->token('test-access-3'), $refused);
        $e = $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'users/me/calendarList');
        });
        $this->assertSame([ConnectionNeedsReauth::class, 401], [get_class($e), $e->httpStatus()]);
        $this->assertCount(6, $this->http->history());
    }

    public function test_rate_limits_are_retried_with_growing_waits_and_then_reported(): void
    {
        foreach (range(1, 4) as $attempt) {
            $this->http->queue(self::googleError(403, 'rateLimitExceeded', 'Rate Limit Exceeded'));
        }

        $e = $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'calendars/agent%40example.com/events');
        });

        $this->assertSame([ProviderUnavailable::class, 'Google is limiting requests. The next sync tries again.', 403], [get_class($e), $e->userMessage(), $e->httpStatus()]);
        $this->assertCount(4, $this->http->history());
        $this->assertCount(GoogleApi::MAX_RETRIES, $this->sleeps);
        // Exponential with up to a second of jitter: 1, 2 and 4 seconds.
        foreach ([1, 2, 4] as $index => $base) {
            $this->assertGreaterThanOrEqual($base, $this->sleeps[$index]);
            $this->assertLessThanOrEqual($base + 1, $this->sleeps[$index]);
        }
    }

    public function test_a_retry_after_is_honored_and_a_long_one_ends_the_request(): void
    {
        $this->http->queue(
            self::googleError(429, 'rateLimitExceeded', 'Rate Limit Exceeded', ['Retry-After' => '3']),
            self::googleError(403, 'userRateLimitExceeded', 'User Rate Limit Exceeded'),
            self::answer(200, ['items' => []])
        );

        [$status] = $this->api->request($this->connection, 'GET', 'calendars/agent%40example.com/events');

        $this->assertSame(200, $status);
        $this->assertCount(2, $this->sleeps);
        $this->assertSame(3.0, $this->sleeps[0]);

        $this->sleeps = [];
        $this->http->queue(self::googleError(429, 'rateLimitExceeded', 'Rate Limit Exceeded', ['Retry-After' => '120']));
        $e = $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'calendars/agent%40example.com/events');
        });
        $this->assertSame(['Google is limiting requests. The next sync tries again.', 429], [$e->userMessage(), $e->httpStatus()]);
        $this->assertSame([], $this->sleeps);
        $this->assertCount(4, $this->http->history());
    }

    public function test_errors_get_our_own_messages_and_a_scrubbed_log_line(): void
    {
        $logs = LogRecorder::install();
        $cases = [
            [self::answer(500, ['error' => ['code' => 500, 'message' => 'Backend Error', 'access_token' => 'test-leaked-token']]), ProviderUnavailable::class, 'The calendar provider could not be reached. Try again later.', 500],
            [self::googleError(412, 'conditionNotMet', 'Precondition Failed'), ProviderConflict::class, 'This event was changed elsewhere. Reload and try again.', 412],
            [self::googleError(403, 'quotaExceeded', 'Calendar usage limits exceeded.'), ProviderUnavailable::class, "Google's usage limits for this account are used up. Syncing continues once Google allows it again.", 403],
            [self::googleError(403, 'dailyLimitExceeded', 'The Courtesy API limit for your project has been reached.'), ProviderUnavailable::class, "The daily usage limit of the Google Cloud project behind this connection is used up. Syncing continues once Google allows it again; the project's quotas are in the Google Cloud console.", 403],
            [self::googleError(403, 'requiredAccessLevel', 'You need to have writer access to this calendar.'), ProviderUnavailable::class, 'Google refused this request. The account may not have access to this calendar, or may only read it.', 403],
            [self::googleError(403, 'forbiddenForNonOrganizer', 'Shared properties can only be changed by the organizer of the event.'), ProviderUnavailable::class, 'Only the organizer can change this event in Google.', 403],
            [self::googleError(404, 'notFound', 'Not Found'), ProviderUnavailable::class, 'Google could not find this calendar or event. It may have been deleted, or the account no longer has access to it.', 404],
            [self::googleError(400, 'invalid', 'Invalid recurrence rule.'), ProviderUnavailable::class, 'Google did not accept the event as FreeScout sent it.', 400],
            [self::googleError(409, 'duplicate', 'The requested identifier already exists.'), ProviderUnavailable::class, 'Google already has this event in the calendar.', 409],
            [new ConnectException('cURL error 6: Could not resolve host: www.googleapis.com', new Psr7Request('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList')), ProviderUnavailable::class, 'The calendar provider could not be reached. Try again later.', null],
        ];
        foreach ($cases as [$answer, $class, $message, $status]) {
            $this->http->queue($answer);

            $e = $this->failure(function () {
                $this->api->request($this->connection, 'PUT', 'calendars/agent%40example.com/events/abc', ['json' => ['summary' => 'x']], [], ['calendar_id' => 7]);
            });

            $this->assertSame([$class, $message, $status], [get_class($e), $e->userMessage(), $e->httpStatus()], $message);
        }
        // Server errors are not retried here: the next sync or the agent tries again.
        $this->assertCount(count($cases), $this->http->history());
        $this->assertSame([], $this->sleeps);

        $records = $logs->records('warning');
        $this->assertCount(count($cases), $records);
        $this->assertSame(['calendar_id' => 7, 'connection_id' => $this->connection->id, 'provider' => 'google', 'status' => 500], array_diff_key($records[0]['context'], ['body' => true]));
        $logged = json_encode($records);
        foreach (['test-leaked-token', 'test-access-1', 'test-refresh-1'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    public function test_statuses_the_caller_handles_are_returned(): void
    {
        $this->http->queue(self::googleError(410, 'fullSyncRequired', 'Sync token is no longer valid, a full sync is required.'));
        $this->assertSame(410, $this->api->request($this->connection, 'GET', 'calendars/agent%40example.com/events', [], [410])[0]);
        // A rate limit or a used-up quota is never handed to the caller, also when it accepts the status.
        foreach (['rateLimitExceeded', 'rateLimitExceeded', 'rateLimitExceeded', 'rateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded'] as $reason) {
            $this->http->queue(self::googleError(403, $reason, 'Limited'));
        }
        foreach (['Google is limiting requests. The next sync tries again.', "Google's usage limits for this account are used up. Syncing continues once Google allows it again.", "The daily usage limit of the Google Cloud project behind this connection is used up. Syncing continues once Google allows it again; the project's quotas are in the Google Cloud console."] as $message) {
            $this->assertSame($message, $this->failure(function () {
                $this->api->request($this->connection, 'GET', 'calendars/agent%40example.com/events/abc/instances', [], [403, 404]);
            })->userMessage());
        }
        $this->http->queue(new Response(204));
        $this->assertSame([204, []], $this->api->request($this->connection, 'DELETE', 'calendars/agent%40example.com/events/abc', ['headers' => ['If-Match' => '"1"']]));
        $history = $this->http->history();
        $this->assertSame(['"1"'], end($history)['headers']['If-Match']);
    }
}
