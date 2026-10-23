<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavClient;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavRequestFailed;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\Tests\Support\DavResponses;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;

class CalDavClientTest extends TestCase
{
    /** Documentation addresses (RFC 5737), allowed below, so the host check needs no DNS lookup. */
    private const SERVER = 'https://192.0.2.10';
    private const WORK = self::SERVER . '/dav/calendars/calendar-user/work/';
    private const OBJECT = self::WORK . 'standup.ics';
    private const BASIC = 'Basic ' . 'Y2FsZW5kYXItdXNlcjpmYWtlLXBhc3N3b3Jk'; // calendar-user:fake-password

    private FakeHttp $http;

    private CalDavClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.remote_host_white_list' => '192.0.2.10,192.0.2.20']);
        $this->http = FakeHttp::install();
        $this->client = new CalDavClient(app(HttpClientFactory::class), 'calendar-user', 'fake-password', ['calendar_id' => 7]);
    }

    private function refused(callable $call): ProviderException
    {
        try {
            $call();
        } catch (ProviderException $e) {
            return $e;
        }
        $this->fail('The request should have failed.');
    }

    public function test_propfind_sends_depth_basic_auth_and_the_properties_and_reads_only_found_properties(): void
    {
        $this->http->queue(DavResponses::fixture('home.xml'));

        $responses = $this->client->propfind(self::SERVER . '/dav/calendars/calendar-user/', 1, ['{DAV:}displayname', '{http://apple.com/ns/ical/}calendar-color']);

        $request = $this->http->history()[0];
        $this->assertSame(['PROPFIND', ['1'], [self::BASIC]], [$request['method'], $request['headers']['Depth'], $request['headers']['Authorization']]);
        $this->assertStringContainsString('<d:propfind xmlns:d="DAV:"', $request['body']);
        $this->assertStringContainsString('<d:prop><d:displayname/><ic:calendar-color/></d:prop>', $request['body']);
        $this->assertSame([
            self::SERVER . '/dav/calendars/calendar-user/',
            self::WORK,
            self::SERVER . '/dav/calendars/calendar-user/public%20holidays/',
            self::SERVER . '/dav/calendars/calendar-user/tasks/',
            self::SERVER . '/dav/calendars/calendar-user/inbox/',
        ], array_keys($responses));
        $home = $responses[self::SERVER . '/dav/calendars/calendar-user/'];
        $this->assertSame(200, $home['status']);
        $this->assertArrayNotHasKey('{DAV:}displayname', $home['props'], 'a property in a 404 propstat is not found');
        $this->assertSame('Work & support', CalDavClient::text($responses[self::WORK]['props']['{DAV:}displayname']));
        $this->assertContains('{urn:ietf:params:xml:ns:caldav}calendar', CalDavClient::names($responses[self::WORK]['props']['{DAV:}resourcetype']));
        $this->assertSame(['VEVENT', 'VTODO'], CalDavClient::components($responses[self::WORK]['props']['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set']));
    }

    public function test_reports_return_status_only_responses_and_the_sync_token(): void
    {
        $this->http->queue(DavResponses::fixture('sync-changes.xml'));

        $result = $this->client->report(self::WORK, CalDavClient::syncCollectionBody('http://sabre.io/ns/sync/7', ['{DAV:}getetag']), 0);

        $this->assertSame('http://sabre.io/ns/sync/8', $result['sync_token']);
        $this->assertSame(404, $result['responses'][self::WORK . 'cancelled.ics']['status']);
        $this->assertSame('"visit-v1"', $result['responses'][self::WORK . 'visit%40example.com.ics']['props']['{DAV:}getetag']);
        $request = $this->http->history()[0];
        $this->assertSame(['REPORT', ['0']], [$request['method'], $request['headers']['Depth']]);
        $this->assertStringContainsString('<d:sync-token>http://sabre.io/ns/sync/7</d:sync-token><d:sync-level>1</d:sync-level>', $request['body']);
    }

    public function test_multiget_names_each_object_by_its_path(): void
    {
        $body = CalDavClient::multigetBody([self::OBJECT, self::WORK . 'a&b.ics'], ['{DAV:}getetag', '{urn:ietf:params:xml:ns:caldav}calendar-data']);

        $this->assertStringContainsString('<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"', $body);
        $this->assertStringContainsString('<d:prop><d:getetag/><c:calendar-data/></d:prop><d:href>/dav/calendars/calendar-user/work/standup.ics</d:href><d:href>/dav/calendars/calendar-user/work/a&amp;b.ics</d:href>', $body);
    }

    public function test_a_digest_challenge_is_answered_with_digest_from_then_on(): void
    {
        // A response of its own each time: Guzzle closes the body of a challenge it answers.
        $challenge = function () {
            return new Response(401, ['WWW-Authenticate' => 'Digest realm="dav", nonce="abc", qop="auth"']);
        };
        // Guzzle 8 does digest itself: a probe without credentials gets the challenge, the retry answers it.
        $this->http->queue($challenge(), $challenge(), DavResponses::fixture('principal.xml'), $challenge(), DavResponses::fixture('principal.xml'));

        $this->client->propfind(self::SERVER . '/dav/principals/calendar-user/', 0, ['{urn:ietf:params:xml:ns:caldav}calendar-home-set']);
        $this->client->propfind(self::SERVER . '/dav/principals/calendar-user/', 0, ['{urn:ietf:params:xml:ns:caldav}calendar-home-set']);

        $authorization = array_map(function (array $request) {
            return substr($request['headers']['Authorization'][0] ?? '', 0, 6);
        }, $this->http->history());
        $this->assertSame(['Basic ', '', 'Digest', '', 'Digest'], $authorization, 'basic once, then digest for every request');
        $this->assertStringContainsString('username="calendar-user"', $this->http->history()[2]['headers']['Authorization'][0]);
        $this->assertStringNotContainsString('fake-password', $this->http->history()[2]['headers']['Authorization'][0]);
    }

    public function test_a_redirect_to_another_host_is_asked_again_with_the_credentials(): void
    {
        $log = LogRecorder::install();
        $this->http->queue(
            new Response(301, ['Location' => 'https://192.0.2.20/dav/']),
            new Response(401, ['WWW-Authenticate' => 'Basic realm="dav"']),
            DavResponses::fixture('well-known.xml')
        );

        $responses = $this->client->propfind(self::SERVER . '/.well-known/caldav', 0, ['{DAV:}current-user-principal']);

        $history = $this->http->history();
        $this->assertSame([self::SERVER . '/.well-known/caldav', 'https://192.0.2.20/dav/', 'https://192.0.2.20/dav/'], array_column($history, 'url'));
        $this->assertSame(['PROPFIND', 'PROPFIND', 'PROPFIND'], array_column($history, 'method'), 'a strict redirect keeps the method');
        $this->assertArrayNotHasKey('Authorization', $history[1]['headers'], 'Guzzle drops credentials on a cross-origin redirect');
        $this->assertSame([self::BASIC], $history[2]['headers']['Authorization']);
        $this->assertSame(['https://192.0.2.20/dav/principals/calendar-user/'], CalDavClient::hrefs($responses['https://192.0.2.20/dav/']['props']['{DAV:}current-user-principal'], 'https://192.0.2.20/dav/'));
        $this->assertSame(['192.0.2.10', '192.0.2.20'], [$log->records('info')[0]['context']['from'], $log->records('info')[0]['context']['to']], 'the host switch is logged');
    }

    public function test_credentials_never_follow_a_redirect_from_https_to_http(): void
    {
        $this->http->queue(new Response(302, ['Location' => 'http://192.0.2.20/dav/']), new Response(401));

        $e = $this->refused(function () {
            $this->client->propfind(self::SERVER . '/.well-known/caldav', 0, ['{DAV:}current-user-principal']);
        });

        $this->assertSame([401, 'The CalDAV server refused the username or password.'], [$e->httpStatus(), $e->userMessage()]);
        $this->assertCount(2, $this->http->history());
    }

    public function test_put_creates_with_if_none_match_and_updates_with_if_match(): void
    {
        $this->http->queue(new Response(201, ['ETag' => '"v1"']), new Response(204, ['ETag' => 'W/"v2"']));

        $created = $this->client->put(self::OBJECT, "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", null, true);
        $updated = $this->client->put(self::OBJECT, "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", '"v1"', false);

        [$create, $update] = $this->http->history();
        $this->assertSame(['PUT', ['*'], ['text/calendar; charset=utf-8']], [$create['method'], $create['headers']['If-None-Match'], $create['headers']['Content-Type']]);
        $this->assertArrayNotHasKey('If-Match', $create['headers']);
        $this->assertSame(['"v1"'], $update['headers']['If-Match']);
        $this->assertArrayNotHasKey('If-None-Match', $update['headers']);
        $this->assertSame(['"v1"', null], [$created, $updated], 'a weak ETag means the server changed the data, so it is not kept');
    }

    public function test_an_update_without_a_known_etag_still_never_recreates_a_deleted_object(): void
    {
        // A server without ETags: an object deleted elsewhere answers If-Match: * with 412, so the write is a conflict.
        $this->http->queue(new Response(204), new Response(412));

        $this->client->put(self::OBJECT, "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", null, false);
        $e = $this->refused(function () {
            $this->client->put(self::OBJECT, "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", '', false);
        });

        $this->assertSame([['*'], ['*']], array_map(function (array $request) {
            return $request['headers']['If-Match'] ?? null;
        }, $this->http->history()));
        $this->assertInstanceOf(ProviderConflict::class, $e);
    }

    public function test_a_failed_precondition_is_a_conflict(): void
    {
        $this->http->queue(new Response(412));

        $e = $this->refused(function () {
            $this->client->put(self::OBJECT, 'BEGIN:VCALENDAR', '"old"', false);
        });

        $this->assertInstanceOf(ProviderConflict::class, $e);
        $this->assertSame([409, 'changed_elsewhere', 412], [$e->status(), $e->code(), $e->httpStatus()]);
    }

    public function test_delete_sends_if_match_and_an_object_that_is_already_gone_counts_as_deleted(): void
    {
        $this->http->queue(
            new Response(204),
            new Response(404),
            new Response(412),
            new Response(404),
            new Response(412),
            DavResponses::multistatus([self::OBJECT => 404]),
            new Response(412),
            DavResponses::multistatus([self::OBJECT => ['getetag' => '"newer"']])
        );

        $this->client->delete(self::OBJECT, '"v1"');
        $this->client->delete(self::OBJECT, null);
        $this->client->delete(self::OBJECT, '"v1"'); // 412, and a PROPFIND finds nothing: deleted elsewhere
        $this->client->delete(self::OBJECT, '"v1"'); // 412, and the PROPFIND's own answer for the object is 404
        $e = $this->refused(function () {
            $this->client->delete(self::OBJECT, '"v1"'); // 412, and the object is still there with a newer ETag
        });

        $history = $this->http->history();
        $this->assertSame(['DELETE', ['"v1"']], [$history[0]['method'], $history[0]['headers']['If-Match']]);
        $this->assertArrayNotHasKey('If-Match', $history[1]['headers']);
        $this->assertSame(['DELETE', 'DELETE', 'DELETE', 'PROPFIND', 'DELETE', 'PROPFIND', 'DELETE', 'PROPFIND'], array_column($history, 'method'));
        $this->assertInstanceOf(ProviderConflict::class, $e);
    }

    public function test_get_reads_an_object_and_a_missing_one_is_null(): void
    {
        $this->http->queue(new Response(200, ['ETag' => '"v3"'], DavResponses::event('standup@example.com', 'Standup')), new Response(410));

        $this->assertSame(['ics' => DavResponses::event('standup@example.com', 'Standup'), 'etag' => '"v3"'], $this->client->get(self::OBJECT));
        $this->assertNull($this->client->get(self::OBJECT));
    }

    public function test_error_statuses_become_translated_messages_with_the_status(): void
    {
        $this->http->queue(
            new Response(401),
            new Response(403),
            new Response(403, [], '<?xml version="1.0"?><d:error xmlns:d="DAV:"><d:need-privileges/></d:error>'),
            new Response(404),
            new Response(500, [], 'Internal error'),
            new Response(207, [], '<html>not xml</html>')
        );
        $propfind = function () {
            $this->client->propfind(self::WORK, 0, ['{DAV:}getetag']);
        };

        $results = [];
        for ($i = 0; $i < 6; $i++) {
            $e = $this->refused($propfind);
            $results[] = [$e->userMessage(), $e->httpStatus()];
        }

        $this->assertSame([
            ['The CalDAV server refused the username or password.', 401],
            ['The CalDAV server refused the username or password.', 403],
            ['The CalDAV server refused this request (HTTP 403).', 403],
            ['The CalDAV server has nothing at this address (HTTP 404).', 404],
            ['The calendar provider could not be reached. Try again later.', 500],
            ['The CalDAV server sent an answer FreeScout can\'t read.', 207],
        ], $results);
    }

    public function test_a_write_the_server_refuses_with_403_names_the_request_and_not_the_login(): void
    {
        // A shared calendar picked as writable on a server that doesn't report privileges: the login works for reading.
        $this->http->queue(new Response(403), new Response(403), new Response(401));

        $put = $this->refused(function () {
            $this->client->put(self::OBJECT, DavResponses::event('standup@example.com', 'Standup'), '"v1"', false);
        });
        $delete = $this->refused(function () {
            $this->client->delete(self::OBJECT, '"v1"');
        });
        $login = $this->refused(function () {
            $this->client->put(self::OBJECT, DavResponses::event('standup@example.com', 'Standup'), '"v1"', false);
        });

        $this->assertSame([
            ['The CalDAV server refused this request (HTTP 403).', 403],
            ['The CalDAV server refused this request (HTTP 403).', 403],
            ['The CalDAV server refused the username or password.', 401],
        ], [[$put->userMessage(), $put->httpStatus()], [$delete->userMessage(), $delete->httpStatus()], [$login->userMessage(), $login->httpStatus()]]);
    }

    public function test_an_answer_over_the_size_limit_is_refused(): void
    {
        $this->http->queue(new Response(207, ['Content-Length' => (string) (CalDavClient::MAX_BYTES + 1)], '<d:multistatus xmlns:d="DAV:"/>'));

        $e = $this->refused(function () {
            $this->client->report(self::WORK, CalDavClient::multigetBody([self::OBJECT], ['{DAV:}getetag']));
        });

        $this->assertSame('The CalDAV server sent an answer over 50 MB.', $e->userMessage());
    }

    public function test_preconditions_of_an_error_body_are_kept(): void
    {
        $this->http->queue(DavResponses::fixture('sync-invalid-token.xml', 403));

        $e = $this->refused(function () {
            $this->client->report(self::WORK, CalDavClient::syncCollectionBody('stale', ['{DAV:}getetag']), 0);
        });

        $this->assertInstanceOf(CalDavRequestFailed::class, $e);
        $this->assertContains('valid-sync-token', $e->preconditions());
    }

    public function test_a_network_error_is_unavailable_and_logs_only_the_host_without_credentials(): void
    {
        $log = LogRecorder::install();
        $this->http->queue(new ConnectException('cURL error 7: Failed to connect to 192.0.2.10 port 443 for ' . self::OBJECT, new Request('GET', self::OBJECT)));

        $e = $this->refused(function () {
            $this->client->get(self::OBJECT);
        });

        $this->assertInstanceOf(ProviderUnavailable::class, $e);
        $this->assertNull($e->getPrevious());
        $record = $log->records('warning')[0];
        $this->assertSame(['calendar_id' => 7, 'provider' => 'caldav', 'host' => '192.0.2.10'], array_intersect_key($record['context'], array_flip(['calendar_id', 'provider', 'host'])));
        $this->assertStringNotContainsString('standup.ics', json_encode($record));
        $this->assertStringNotContainsString('fake-password', json_encode($log->records()));
    }

    public function test_hrefs_are_normalized_so_one_object_has_one_address(): void
    {
        $this->assertSame(self::WORK . 'visit%40example.com.ics', CalDavClient::absolute(self::WORK, 'visit@example.com.ics'));
        $this->assertSame(self::WORK . 'visit%40example.com.ics', CalDavClient::absolute(self::SERVER . '/x', '/dav/calendars/calendar-user/work/visit%40example.com.ics'));
        $this->assertSame('https://192.0.2.10/dav/y/', CalDavClient::absolute('HTTPS://192.0.2.10:443/dav/x/', '../y/'));
        $this->assertTrue(CalDavClient::sameResource(self::WORK, rtrim(self::WORK, '/')));
    }
}
