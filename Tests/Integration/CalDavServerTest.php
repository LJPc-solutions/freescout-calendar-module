<?php

namespace Modules\LJPcCalendarModule\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\EventImporter;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\SyncService;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

/**
 * Against a real CalDAV server: the Radicale container that Tests/caldav/install.sh adds to ddev. Each test works in
 * a calendar collection of its own, which it removes again.
 *
 * @group caldav-server
 */
class CalDavServerTest extends TestCase
{
    private const SERVER = 'http://radicale:5232';
    private const USER = 'calendar-test';

    private string $collection;

    private CalDavProvider $provider;

    private CalendarConnection $connection;

    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $ip = gethostbyname('radicale');
        if ($ip === 'radicale' || !@fsockopen($ip, 5232, $errno, $error, 1.0)) {
            $this->markTestSkipped('Radicale is not running. Run Tests/caldav/install.sh.');
        }
        // Radicale lives on ddev's private network, which FreeScout only reaches when it is allowed.
        config(['app.remote_host_white_list' => 'radicale,' . $ip]);
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->provider = app(CalDavProvider::class);
        $this->collection = self::SERVER . '/' . self::USER . '/it-' . bin2hex(random_bytes(6)) . '/';
        $created = $this->dav('MKCALENDAR', $this->collection, '<?xml version="1.0" encoding="utf-8"?><C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:A="http://apple.com/ns/ical/">'
            . '<D:set><D:prop><D:displayname>FreeScout integration</D:displayname><A:calendar-color>#1F6FEBFF</A:calendar-color></D:prop></D:set></C:mkcalendar>');
        $this->assertSame(201, $created);

        $this->connection = new CalendarConnection(['provider' => Calendar::PROVIDER_CALDAV, 'account' => self::USER, 'server_url' => self::SERVER]);
        $this->connection->setCredentials(['username' => self::USER, 'password' => 'calendar-test']);
        $this->connection->save();
        $this->calendar = Calendar::create([
            'name' => 'Radicale ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $this->connection->id,
            'remote_id' => $this->collection, 'timezone' => 'Europe/Amsterdam', 'sync_interval' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->collection)) {
            $this->dav('DELETE', $this->collection);
        }
        parent::tearDown();
    }

    /** A request as another CalDAV client would send it, outside FreeScout. Returns the status. */
    private function dav(string $method, string $url, string $body = '', array $headers = []): int
    {
        return (new Client(['http_errors' => false]))->request($method, $url, ['auth' => [self::USER, 'calendar-test'], 'body' => $body, 'headers' => $headers])->getStatusCode();
    }

    private function pull(array $state = []): ChangeSet
    {
        return $this->provider->pull($this->calendar, $state, SyncBudget::seconds(30));
    }

    private static function find(array $events, string $href): ?RemoteEvent
    {
        foreach ($events as $event) {
            if ($event->remoteId === $href) {
                return $event;
            }
        }

        return null;
    }

    private static function vcal(string $uid, string $title): \Sabre\VObject\Component\VCalendar
    {
        $zone = new DateTimeZone('Europe/Amsterdam');

        return EventIcs::build(['uid' => $uid, 'title' => $title, 'all_day' => false, 'start' => new DateTimeImmutable('2026-10-20 10:00', $zone), 'end' => new DateTimeImmutable('2026-10-20 11:00', $zone)]);
    }

    public function test_a_password_the_server_refuses_marks_the_connection_for_reauthorization(): void
    {
        $this->connection->setCredentials(['username' => self::USER, 'password' => 'wrong-password']);
        $this->connection->save();
        $calendar = Calendar::find($this->calendar->id);

        app(SyncService::class)->syncCalendar($calendar, SyncBudget::seconds(30));

        $connection = $this->connection->fresh();
        $this->assertSame([CalendarConnection::STATUS_NEEDS_REAUTH, 'The account must be connected again.'], [$connection->status, $connection->last_error]);
        $this->assertSame("This calendar's connection needs to be re-authorized by an administrator. (HTTP 401)", $calendar->fresh()->last_error);
    }

    public function test_discovers_creates_pulls_updates_and_deletes_an_event(): void
    {
        $found = null;
        foreach ($this->provider->listCalendars($this->connection) as $calendar) {
            $found = $calendar->remoteId === $this->collection ? $calendar : $found;
        }
        $this->assertNotNull($found, 'discovery from the server address finds the calendar');
        $this->assertSame(['FreeScout integration', '#1f6feb', true], [$found->name, $found->color, $found->writable]);

        $created = $this->provider->create($this->calendar, self::vcal('integration-' . uniqid() . '@example.com', 'Integration'));
        $this->assertNotNull($created->etag);

        $listing = $this->pull();
        $pulled = self::find($listing->upserts, $created->href);
        $this->assertSame([true, true, true], [$listing->complete, $listing->fullListing, $listing->state['supports_sync']]);
        $this->assertNotNull($pulled, 'the first listing sees the new event');
        $this->assertSame($created->etag, $pulled->etag);
        $this->assertStringContainsString('BEGIN:VTIMEZONE', $pulled->ics, 'the PUT carried the time zone');
        $event = app(EventImporter::class)->upsert($this->calendar, $pulled, 1);
        $stale = $event->etag;

        $changed = Reader::read($event->ics);
        $changed->VEVENT->SUMMARY = 'Integration (changed)';
        $updated = $this->provider->update($this->calendar, $event, $changed);
        $this->assertNotSame($stale, $updated->etag);

        try {
            $this->provider->update($this->calendar, $event, $changed); // still the old etag
            $this->fail('A write with a stale etag should conflict.');
        } catch (ProviderConflict $e) {
            $this->assertSame([409, 'changed_elsewhere', 412], [$e->status(), $e->code(), $e->httpStatus()]);
        }

        $changes = $this->pull($listing->state);
        $this->assertSame([true, false], [$changes->complete, $changes->fullListing], 'later pulls use sync-collection');
        $this->assertSame($updated->etag, self::find($changes->upserts, $created->href)->etag);
        $this->assertStringContainsString('SUMMARY:Integration (changed)', self::find($changes->upserts, $created->href)->ics);

        $event->etag = $updated->etag;
        $this->provider->delete($this->calendar, $event);
        $this->provider->delete($this->calendar, $event); // already gone: still fine

        $gone = $this->pull($changes->state);
        $this->assertSame([$created->href], $gone->deletes);
    }

    public function test_a_token_the_server_does_not_know_lists_the_calendar_again(): void
    {
        $created = $this->provider->create($this->calendar, self::vcal('token-' . uniqid() . '@example.com', 'Token'));

        $changes = $this->pull(['sync_token' => 'http://radicale.org/ns/sync/0000', 'supports_sync' => true]);

        $this->assertSame([true, true], [$changes->complete, $changes->fullListing]);
        $this->assertNotNull(self::find($changes->upserts, $created->href));
        $this->assertStringStartsWith('http://radicale.org/ns/sync/', $changes->state['sync_token']);
        $this->assertNotSame('http://radicale.org/ns/sync/0000', $changes->state['sync_token']);
    }

    public function test_the_sync_engine_imports_and_removes_what_another_client_wrote(): void
    {
        $href = $this->collection . 'from-another-client.ics';
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//Other client//EN\r\nBEGIN:VEVENT\r\nUID:other-" . uniqid() . "@example.com\r\n"
            . "DTSTAMP:20261001T080000Z\r\nDTSTART:20261021T090000Z\r\nDTEND:20261021T100000Z\r\nSUMMARY:Written elsewhere\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->assertSame(201, $this->dav('PUT', $href, $ics, ['Content-Type' => 'text/calendar; charset=utf-8']));

        app(SyncService::class)->syncCalendar($this->calendar, SyncBudget::seconds(30));

        $event = CalendarEvent::findByRemoteId($this->calendar->id, $href);
        $this->assertNotNull($event);
        $this->assertSame(['Written elsewhere', false, 3], [$event->summary, $event->read_only, $event->occurrences()->count()]);
        $this->assertNull($this->calendar->fresh()->last_error);

        $this->assertContains($this->dav('DELETE', $href), [200, 204]);
        app(SyncService::class)->syncCalendar($this->calendar->fresh(), SyncBudget::seconds(30));

        $this->assertNull(CalendarEvent::findByRemoteId($this->calendar->id, $href));
    }
}
