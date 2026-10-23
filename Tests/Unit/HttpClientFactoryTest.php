<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;

class HttpClientFactoryTest extends TestCase
{
    private const NOT_ALLOWED = 'This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST.';

    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = FakeHttp::install();
    }

    public function test_clients_use_cores_options_and_hand_error_statuses_back(): void
    {
        // .env values arrive as strings, which Guzzle 8 refuses for timeouts.
        config(['app.curl_timeout' => '25', 'app.curl_connect_timeout' => 7, 'app.proxy' => '', 'app.curl_ssl_verifypeer' => true]);
        $this->http->queue(new Response(500, [], 'Server error'));

        $client = app(HttpClientFactory::class)->client(['headers' => ['Accept' => 'text/calendar']]);
        $response = $client->request('GET', 'https://calendar.example.com/feed.ics');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(25.0, $client->getConfig('timeout'));
        $this->assertSame(7.0, $client->getConfig('connect_timeout'));
        $this->assertSame('', $client->getConfig('proxy'));
        $this->assertTrue($client->getConfig('verify'));
        $this->assertFalse($client->getConfig('http_errors'));
        $this->assertSame(['text/calendar'], $this->http->history()[0]['headers']['Accept']);
    }

    public function test_checked_clients_follow_at_most_five_http_redirects(): void
    {
        $client = app(HttpClientFactory::class)->client([], true);

        $this->assertSame(5, $client->getConfig('allow_redirects')['max']);
        $this->assertSame(['http', 'https'], $client->getConfig('allow_redirects')['protocols']);
    }

    public function test_a_caller_may_ask_for_strict_redirects_but_never_for_more_hops_other_schemes_or_a_referer(): void
    {
        $asked = ['strict' => true, 'track_redirects' => true, 'max' => 50, 'protocols' => ['http', 'https', 'file'], 'referer' => true];
        $client = app(HttpClientFactory::class)->client(['allow_redirects' => $asked], true);

        $redirects = $client->getConfig('allow_redirects');
        $this->assertSame([5, ['http', 'https'], false, true, true], [$redirects['max'], $redirects['protocols'], $redirects['referer'], $redirects['strict'], $redirects['track_redirects']]);
        $this->assertFalse(app(HttpClientFactory::class)->client(['allow_redirects' => false], true)->getConfig('allow_redirects'));
    }

    public function test_checked_clients_refuse_a_private_address_before_sending_anything(): void
    {
        try {
            app(HttpClientFactory::class)->client([], true)->request('GET', 'http://127.0.0.1/feed.ics');
            $this->fail('The request should have been refused.');
        } catch (ProviderUnavailable $e) {
            $this->assertSame(self::NOT_ALLOWED, $e->userMessage());
            $this->assertNotNull($e->getPrevious());
        }
        $this->assertSame([], $this->http->history());
    }

    public function test_checked_clients_refuse_a_redirect_to_a_private_address(): void
    {
        $this->http->queue(new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']));

        try {
            app(HttpClientFactory::class)->client([], true)->request('GET', 'https://calendar.example.com/feed.ics');
            $this->fail('The redirect should have been refused.');
        } catch (ProviderUnavailable $e) {
            $this->assertSame(self::NOT_ALLOWED, $e->userMessage());
        }
        $this->assertSame(['https://calendar.example.com/feed.ics'], array_column($this->http->history(), 'url'));
    }

    public function test_a_whitelisted_private_host_is_allowed(): void
    {
        config(['app.remote_host_white_list' => 'radicale.test, 127.0.0.1']);
        $this->http->queue(new Response(200, [], 'BEGIN:VCALENDAR'));

        $response = app(HttpClientFactory::class)->client([], true)->request('GET', 'http://127.0.0.1:5232/feed.ics');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->http->history());
    }

    public function test_ports_and_credentials_in_an_address_do_not_block_it(): void
    {
        $this->http->queue(new Response(207, [], '<multistatus/>'));

        $response = app(HttpClientFactory::class)->client([], true)->request('PROPFIND', 'https://user:secret@dav.example.com:8443/calendars/');

        $this->assertSame(207, $response->getStatusCode());
    }

    public function test_only_http_and_https_addresses_are_allowed(): void
    {
        foreach (['ftp://calendar.example.com/feed.ics', 'file:///etc/passwd', 'https:///feed.ics'] as $url) {
            try {
                app(HttpClientFactory::class)->assertAllowed($url);
                $this->fail($url . ' should have been refused.');
            } catch (ProviderUnavailable $e) {
                $this->assertSame('This is not a valid http or https address.', $e->userMessage(), $url);
            }
        }
    }

    public function test_scrub_removes_tokens_secrets_and_passwords(): void
    {
        $cases = [
            '{"access_token":"ya29.a0AfB_byC","token_type":"Bearer"}' => '{"access_token":"***","token_type":"Bearer"}',
            '{"refresh_token": "1//0gLx-abc"}'                         => '{"refresh_token": "***"}',
            '{"client_secret":"GOCSPX-abc","password":"a\"b c"}'      => '{"client_secret":"***","password":"***"}',
            'grant_type=refresh_token&refresh_token=1%2F%2F0g&client_secret=s3cr3t' => 'grant_type=refresh_token&refresh_token=***&client_secret=***',
            'access_token=abc&password=hunter2'                        => 'access_token=***&password=***',
            "Authorization: Basic dXNlcjpwYXNz\r\nHost: dav.example.com" => "Authorization: ***\r\nHost: dav.example.com",
            'error: invalid token Bearer eyJ0eXAiOiJKV1Qi.eyJhdWQi.sig' => 'error: invalid token Bearer ***',
            '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}',
        ];
        foreach ($cases as $body => $expected) {
            $this->assertSame($expected, HttpClientFactory::scrub($body), $body);
        }
    }

    public function test_scrub_keeps_500_characters_and_never_half_a_token(): void
    {
        $this->assertSame(500, mb_strlen(HttpClientFactory::scrub(str_repeat('é', 600))));

        // The token is cut off at character 500; what is left of it must still go.
        $scrubbed = HttpClientFactory::scrub(str_repeat('x', 480) . '{"access_token":"' . str_repeat('t', 100) . '"}');
        $this->assertStringNotContainsString('t', str_replace('access_token', '', $scrubbed));
    }

    public function test_log_context_keeps_ids_status_and_host_only(): void
    {
        $context = HttpClientFactory::logContext([
            'calendar_id' => 4,
            'provider'    => 'ics',
            'status'      => 401,
            'url'         => 'https://calendar.example.com/private/abc123secret/basic.ics',
            'body'        => '{"error":"denied","access_token":"leaked"}',
            'error'       => 'cURL error 28: Operation timed out for https://calendar.example.com/private/abc123secret/basic.ics',
            'headers'     => ['Authorization' => 'Basic c2VjcmV0'],
        ]);

        $this->assertSame([
            'calendar_id' => 4,
            'provider'    => 'ics',
            'status'      => 401,
            'host'        => 'calendar.example.com',
            'body'        => '{"error":"denied","access_token":"***"}',
            'error'       => 'cURL error 28: Operation timed out for calendar.example.com',
        ], $context);
    }

    public function test_an_address_without_a_host_is_never_logged(): void
    {
        $this->assertSame(['host' => '[address]'], HttpClientFactory::logContext(['url' => 'calendar.example.com/private/abc123secret/basic.ics']));
        $this->assertSame(['host' => 'calendar.example.com'], HttpClientFactory::logContext(['host' => 'calendar.example.com']));
    }

    public function test_failures_are_logged_as_warnings_with_that_context(): void
    {
        $log = LogRecorder::install();

        HttpClientFactory::logFailure('Calendar feed could not be fetched', ['calendar_id' => 4, 'url' => 'https://calendar.example.com/secret.ics']);

        $this->assertSame([['level' => 'warning', 'message' => 'Calendar feed could not be fetched', 'context' => ['calendar_id' => 4, 'host' => 'calendar.example.com']]], $log->records());
    }
}
