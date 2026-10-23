<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\Microsoft\GraphApi;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;

class GraphApiTest extends TestCase
{
    private const SCOPES = 'offline_access User.Read Calendars.ReadWrite Calendars.ReadWrite.Shared';
    private const TOKEN_URL = 'https://login.microsoftonline.com/example.onmicrosoft.com/oauth2/v2.0/token';
    private const CALENDARS = 'https://graph.microsoft.com/v1.0/me/calendars';

    private FakeHttp $http;

    private GraphApi $api;

    private CalendarConnection $connection;

    /** @var float[] the waits the API asked for, in seconds */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = FakeHttp::install();
        app(ConnectionService::class)->saveAppCredentials('microsoft', ['tenant' => 'example.onmicrosoft.com', 'client_id' => '00000000-0000-4000-8000-000000000001', 'client_secret' => 'test-microsoft-secret']);
        $this->api = new GraphApi($this->http, app(ConnectionService::class), function (float $seconds) {
            $this->sleeps[] = $seconds;
        });
        $this->connection = new CalendarConnection(['provider' => 'microsoft', 'account' => 'agent@example.com']);
        $this->connection->setCredentials(['refresh_token' => 'test-refresh-1', 'access_token' => 'test-access-1', 'expires_at' => time() + 3600, 'scopes' => self::SCOPES]);
        $this->connection->save();
    }

    private static function answer(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** Graph's error body (Microsoft Graph error responses reference). */
    private static function graphError(int $status, string $code, string $message, array $headers = []): Response
    {
        return self::answer($status, ['error' => ['code' => $code, 'message' => $message, 'innerError' => ['date' => '2026-10-09T10:00:00', 'request-id' => '00000000-0000-4000-8000-0000000000aa']]], $headers);
    }

    private function token(string $accessToken): Response
    {
        return self::answer(200, ['token_type' => 'Bearer', 'scope' => self::SCOPES, 'expires_in' => 3599, 'access_token' => $accessToken, 'refresh_token' => 'test-refresh-2']);
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

    public function test_requests_carry_the_token_and_graphs_preferences_and_follow_no_redirects(): void
    {
        $this->http->queue(self::answer(200, ['value' => []]), new Response(302, ['Location' => 'https://elsewhere.example.com/']));

        [$status, $answer] = $this->api->request($this->connection, 'GET', 'me/calendars', ['query' => ['$select' => 'id,name', '$top' => 100]]);

        $this->assertSame([200, ['value' => []]], [$status, $answer]);
        [$request] = $this->http->history();
        $this->assertSame(['GET', self::CALENDARS . '?%24select=id%2Cname&%24top=100'], [$request['method'], $request['url']]);
        $this->assertSame(['Bearer test-access-1'], $request['headers']['Authorization']);
        $this->assertSame(['application/json'], $request['headers']['Accept']);
        // Each preference as a header of its own (research question 3): immutable ids, UTC times, text bodies.
        $this->assertSame(['IdType="ImmutableId"', 'outlook.timezone="UTC"', 'outlook.body-content-type="text"'], $request['headers']['Prefer']);
        $e = $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'me/calendars');
        });
        $this->assertSame([ProviderUnavailable::class, 302], [get_class($e), $e->httpStatus()]);
        $this->assertCount(2, $this->http->history());
    }

    public function test_a_next_link_is_followed_only_on_graphs_own_address(): void
    {
        $next = self::CALENDARS . '?%24select=id%2Cname&%24top=100&%24skip=100';
        $this->http->queue(self::answer(200, ['value' => []]));

        $this->api->request($this->connection, 'GET', $next);

        $this->assertSame($next, $this->http->history()[0]['url']);
        foreach (['https://graph.microsoft.com.example.com/v1.0/me/calendars', 'http://graph.microsoft.com/v1.0/me/calendars', 'https://graph.microsoft.com/beta/me/calendars'] as $elsewhere) {
            $e = $this->failure(function () use ($elsewhere) {
                $this->api->request($this->connection, 'GET', $elsewhere);
            });
            $this->assertSame(ProviderUnavailable::class, get_class($e), $elsewhere);
        }
        $this->assertCount(1, $this->http->history());
    }

    public function test_a_refused_token_is_renewed_once_and_the_request_sent_again(): void
    {
        $refused = self::graphError(401, 'InvalidAuthenticationToken', 'Access token has expired or is not yet valid.');
        $this->http->queue($refused, $this->token('test-access-2'), self::answer(200, ['value' => []]));

        [$status] = $this->api->request($this->connection, 'GET', 'me/calendars');

        $this->assertSame(200, $status);
        $this->assertSame([
            'GET ' . self::CALENDARS . ' Bearer test-access-1',
            'POST ' . self::TOKEN_URL . ' -',
            'GET ' . self::CALENDARS . ' Bearer test-access-2',
        ], $this->bearers());
        // Microsoft rotates the refresh token on every refresh.
        $this->assertSame('test-refresh-2', $this->connection->fresh()->credentials()['refresh_token']);

        $this->http->queue($refused, $this->token('test-access-3'), $refused);
        $e = $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'me/calendars');
        });
        $this->assertSame([ConnectionNeedsReauth::class, 401], [get_class($e), $e->httpStatus()]);
        $this->assertCount(6, $this->http->history());
    }

    public function test_throttling_waits_as_long_as_retry_after_says(): void
    {
        $this->http->queue(
            self::graphError(429, 'TooManyRequests', 'Please retry again later.', ['Retry-After' => '2']),
            self::graphError(503, 'ServiceUnavailable', 'Service Unavailable', ['Retry-After' => '3']),
            self::answer(200, ['value' => []])
        );

        [$status] = $this->api->request($this->connection, 'GET', 'me/calendars');

        $this->assertSame(200, $status);
        $this->assertSame([2.0, 3.0], $this->sleeps);
        $this->assertCount(3, $this->http->history());
    }

    public function test_throttling_without_retry_after_backs_off_and_then_gives_up(): void
    {
        foreach (range(1, 4) as $attempt) {
            $this->http->queue(self::graphError(429, 'TooManyRequests', 'Please retry again later.'));
        }

        $e = $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'me/calendars');
        });

        $this->assertSame([ProviderUnavailable::class, 'Microsoft is limiting requests right now. Try again in a minute.', 429], [get_class($e), $e->userMessage(), $e->httpStatus()]);
        $this->assertCount(4, $this->http->history());
        $this->assertCount(GraphApi::MAX_RETRIES, $this->sleeps);
        foreach ([1, 2, 4] as $index => $base) {
            $this->assertGreaterThanOrEqual($base, $this->sleeps[$index]);
            $this->assertLessThanOrEqual($base + 1, $this->sleeps[$index]);
        }
    }

    public function test_a_long_retry_after_ends_the_request_and_someone_waiting_waits_less(): void
    {
        $this->http->queue(self::graphError(429, 'TooManyRequests', 'Please retry again later.', ['Retry-After' => '30']));
        $e = $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'me/calendars');
        });
        $this->assertSame(429, $e->httpStatus());
        $this->assertSame([], $this->sleeps);

        // A sync waits 10 seconds; an agent's request only up to 4.
        $this->http->queue(self::graphError(429, 'TooManyRequests', 'Please retry again later.', ['Retry-After' => '10']), self::answer(200, ['value' => []]));
        $this->assertSame(200, $this->api->request($this->connection, 'GET', 'me/calendars')[0]);
        $this->assertSame([10.0], $this->sleeps);
        $this->http->queue(self::graphError(429, 'TooManyRequests', 'Please retry again later.', ['Retry-After' => '10']));
        $this->assertSame(429, $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'me/calendars', ['max_wait' => GraphApi::INTERACTIVE_MAX_WAIT_SECONDS]);
        })->httpStatus());
        $this->assertSame([10.0], $this->sleeps);
    }

    public function test_errors_get_our_own_messages_and_a_scrubbed_log_line(): void
    {
        $logs = LogRecorder::install();
        $cases = [
            [self::graphError(412, 'ErrorIrresolvableConflict', 'The send or update operation could not be performed because the change key passed in the request does not match the current change key for the item.'), ProviderConflict::class, 'This event was changed elsewhere. Reload and try again.', 412],
            [self::graphError(403, 'ErrorAccessDenied', 'Access is denied. Check credentials and try again.'), ProviderUnavailable::class, 'Microsoft refused this request. The account may not have access to this calendar, or may only read it.', 403],
            [self::graphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.'), ProviderUnavailable::class, 'Microsoft could not find this calendar or event. It may have been deleted, or the account no longer has access to it.', 404],
            [self::graphError(400, 'ErrorOccurrenceCrossingBoundary', 'Modified occurrence is crossing or overlapping adjacent occurrence.'), ProviderUnavailable::class, 'Microsoft does not let an occurrence move onto or past the day of the occurrence before or after it.', 400],
            [self::graphError(400, 'ErrorInvalidRequest', 'Your request is invalid.'), ProviderUnavailable::class, 'Microsoft did not accept the event as FreeScout sent it.', 400],
            [self::answer(500, ['error' => ['code' => 'generalException', 'message' => 'General exception', 'access_token' => 'test-leaked-token']]), ProviderUnavailable::class, 'The calendar provider could not be reached. Try again later.', 500],
            [new ConnectException('cURL error 6: Could not resolve host: graph.microsoft.com', new Psr7Request('GET', self::CALENDARS)), ProviderUnavailable::class, 'The calendar provider could not be reached. Try again later.', null],
        ];
        foreach ($cases as [$answer, $class, $message, $status]) {
            $this->http->queue($answer);

            $e = $this->failure(function () {
                $this->api->request($this->connection, 'PATCH', 'me/calendars/AAMkADExampleCalendar01AAA%3D/events/AAMkADExampleSingle0001AAA%3D', ['json' => ['subject' => 'x']], [], ['calendar_id' => 7]);
            });

            $this->assertSame([$class, $message, $status], [get_class($e), $e->userMessage(), $e->httpStatus()], $message);
        }
        // Errors other than throttling are not retried here: the next sync or the agent tries again.
        $this->assertCount(count($cases), $this->http->history());
        $this->assertSame([], $this->sleeps);

        $records = $logs->records('warning');
        $this->assertCount(count($cases), $records);
        $this->assertSame('A Microsoft Graph request failed', $records[0]['message']);
        $this->assertSame(['calendar_id' => 7, 'connection_id' => $this->connection->id, 'provider' => 'microsoft', 'status' => 412], array_diff_key($records[0]['context'], ['body' => true]));
        $logged = json_encode($records);
        foreach (['test-leaked-token', 'test-access-1', 'test-refresh-1', 'test-microsoft-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    public function test_statuses_the_caller_handles_are_returned_but_never_throttling(): void
    {
        $this->http->queue(self::graphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.'));
        $this->assertSame(404, $this->api->request($this->connection, 'GET', 'me/events/AAMkADGone%3D', [], [404])[0]);

        foreach (range(1, 4) as $attempt) {
            $this->http->queue(self::graphError(503, 'ServiceUnavailable', 'Service Unavailable'));
        }
        $e = $this->failure(function () {
            $this->api->request($this->connection, 'GET', 'me/events/AAMkADGone%3D', [], [404, 503]);
        });
        $this->assertSame([ProviderUnavailable::class, 503], [get_class($e), $e->httpStatus()]);

        $this->http->queue(new Response(204));
        $this->assertSame([204, []], $this->api->request($this->connection, 'DELETE', 'me/events/AAMkADGone%3D'));
    }
}
