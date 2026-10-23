<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavProvider;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteCalendar;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Tests\Support\DavResponses;
use Modules\LJPcCalendarModule\Tests\Support\FakeHttp;
use Modules\LJPcCalendarModule\Tests\TestCase;

class CalDavDiscoveryTest extends TestCase
{
    private const SERVER = 'https://192.0.2.10';
    private const NOT_ALLOWED = 'This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST.';
    private const NONE_FOUND = 'No CalDAV calendars were found at this address.';

    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.remote_host_white_list' => '192.0.2.10']);
        $this->http = FakeHttp::install();
    }

    private function connection(string $serverUrl): CalendarConnection
    {
        $connection = new CalendarConnection(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'calendar-user', 'server_url' => $serverUrl]);
        $connection->setCredentials(['username' => 'calendar-user', 'password' => 'fake-password']);
        $connection->save();

        return $connection;
    }

    /** @return RemoteCalendar[] */
    private function discover(string $serverUrl = self::SERVER): array
    {
        return app(CalDavProvider::class)->listCalendars($this->connection($serverUrl));
    }

    private function refusal(string $serverUrl = self::SERVER): ProviderUnavailable
    {
        try {
            $this->discover($serverUrl);
        } catch (ProviderUnavailable $e) {
            return $e;
        }
        $this->fail('Discovery should have failed.');
    }

    private function requests(): array
    {
        return array_map(function (array $request) {
            return [$request['method'], $request['url'], $request['headers']['Depth'][0] ?? null];
        }, $this->http->history());
    }

    private static function described(array $calendars): array
    {
        return array_map(function (RemoteCalendar $calendar) {
            return [$calendar->remoteId, $calendar->name, $calendar->color, $calendar->timezone, $calendar->writable, $calendar->primary];
        }, $calendars);
    }

    /** The answer for an address that is not a calendar, naming the user's principal. */
    private static function principalOf(string $href): Response
    {
        return new Response(207, [], '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>' . $href . '</d:href>'
            . '<d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype><d:current-user-principal><d:href>/dav/principals/calendar-user/</d:href></d:current-user-principal></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>');
    }

    public function test_finds_the_event_calendars_through_well_known_the_principal_and_the_home(): void
    {
        $this->http->queue(
            new Response(301, ['Location' => '/dav/']),
            DavResponses::fixture('well-known.xml'),
            DavResponses::fixture('principal.xml'),
            DavResponses::fixture('home.xml')
        );

        $calendars = $this->discover();

        $this->assertSame([
            [self::SERVER . '/dav/calendars/calendar-user/work/', 'Work & support', '#1f6feb', 'Europe/Amsterdam', true, false],
            [self::SERVER . '/dav/calendars/calendar-user/public%20holidays/', 'public holidays', '#e5534b', null, false, false],
        ], self::described($calendars), 'the to-do list and the scheduling inbox are left out');
        $this->assertSame([
            ['PROPFIND', self::SERVER . '/.well-known/caldav', '0'],
            ['PROPFIND', self::SERVER . '/dav/', '0'],
            ['PROPFIND', self::SERVER . '/dav/principals/calendar-user/', '0'],
            ['PROPFIND', self::SERVER . '/dav/calendars/calendar-user/', '1'],
        ], $this->requests());
    }

    public function test_an_address_that_is_a_calendar_is_that_one_calendar(): void
    {
        $this->http->queue(new Response(404), DavResponses::fixture('collection.xml'));

        $calendars = $this->discover(self::SERVER . '/caldav/a1b2c3d4/events/');

        $this->assertSame([[self::SERVER . '/caldav/a1b2c3d4/events/', 'Team', '#2ecc71', null, true, false]], self::described($calendars));
        $this->assertSame([
            ['PROPFIND', self::SERVER . '/.well-known/caldav', '0'],
            ['PROPFIND', self::SERVER . '/caldav/a1b2c3d4/events/', '0'],
        ], $this->requests());
    }

    public function test_without_well_known_the_principal_the_address_names_is_used(): void
    {
        $this->http->queue(
            new Response(405),
            self::principalOf('/remote.php/dav/'),
            DavResponses::fixture('principal.xml'),
            DavResponses::fixture('home.xml')
        );

        $calendars = $this->discover(self::SERVER . '/remote.php/dav/');

        $this->assertCount(2, $calendars);
        $this->assertSame(self::SERVER . '/dav/principals/calendar-user/', $this->http->history()[2]['url']);
    }

    public function test_a_redirect_to_a_blocked_host_is_refused_before_it_is_requested(): void
    {
        $this->http->queue(new Response(301, ['Location' => 'http://169.254.169.254/latest/meta-data/']));

        $e = $this->refusal();

        $this->assertSame(self::NOT_ALLOWED, $e->userMessage());
        $this->assertSame([['PROPFIND', self::SERVER . '/.well-known/caldav', '0']], $this->requests());
    }

    public function test_refused_credentials_say_so(): void
    {
        $this->http->queue(new Response(401), new Response(401));

        $e = $this->refusal();

        $this->assertSame(['The CalDAV server refused the username or password.', 401], [$e->userMessage(), $e->httpStatus()]);
    }

    public function test_a_server_without_event_calendars_says_so(): void
    {
        $this->http->queue(
            new Response(404),
            self::principalOf('/'),
            DavResponses::fixture('principal.xml'),
            DavResponses::multistatus([self::SERVER . '/dav/calendars/calendar-user/' => ['resourcetype' => 'collection']])
        );

        $this->assertSame(self::NONE_FOUND, $this->refusal()->userMessage());
    }

    public function test_an_address_without_a_principal_says_so(): void
    {
        $this->http->queue(new Response(404), DavResponses::multistatus([self::SERVER . '/' => ['resourcetype' => 'collection']]));

        $this->assertSame(self::NONE_FOUND, $this->refusal()->userMessage());
    }
}
