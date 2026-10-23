<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use InvalidArgumentException;
use Modules\LJPcCalendarModule\CalendarProviders\CalendarProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\CalendarProviders\LocalProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteCalendar;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    private function calendar(string $provider, ?string $connectionStatus = null): Calendar
    {
        $connectionId = null;
        if ($connectionStatus !== null) {
            $connectionId = CalendarConnection::create(['provider' => $provider, 'account' => 'agent@example.com', 'status' => $connectionStatus])->id;
        }

        return Calendar::create(['name' => 'Provider ' . uniqid(), 'provider' => $provider, 'connection_id' => $connectionId]);
    }

    public function test_the_registry_and_the_http_factory_are_singletons(): void
    {
        $this->assertSame(app(ProviderRegistry::class), app(ProviderRegistry::class));
        $this->assertSame(app(HttpClientFactory::class), app(HttpClientFactory::class));
    }

    public function test_providers_are_resolved_on_first_use_and_then_reused(): void
    {
        $registry = app(ProviderRegistry::class);

        $local = $registry->forProvider(Calendar::PROVIDER_LOCAL);

        $this->assertInstanceOf(LocalProvider::class, $local);
        $this->assertSame($local, $registry->for($this->calendar(Calendar::PROVIDER_LOCAL)));
        $this->assertSame(
            [Calendar::PROVIDER_LOCAL, Calendar::PROVIDER_ICS, Calendar::PROVIDER_CALDAV, Calendar::PROVIDER_GOOGLE, Calendar::PROVIDER_MICROSOFT],
            array_keys(ProviderRegistry::PROVIDERS)
        );
        foreach (ProviderRegistry::PROVIDERS as $class) {
            $this->assertTrue(is_subclass_of($class, CalendarProvider::class) || !class_exists($class), $class);
        }
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProviderRegistry::class)->forProvider('exchange');
    }

    public function test_swap_replaces_one_provider(): void
    {
        $fake = new FakeProvider(false);

        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_ICS, $fake);

        $this->assertSame($fake, app(ProviderRegistry::class)->for($this->calendar(Calendar::PROVIDER_ICS)));
        $this->expectException(InvalidArgumentException::class);
        app(ProviderRegistry::class)->swap('exchange', $fake);
    }

    public function test_the_local_provider_writes_and_pulls_nothing(): void
    {
        $provider = new LocalProvider();
        $calendar = $this->calendar(Calendar::PROVIDER_LOCAL);
        $vcal = EventIcs::build(['uid' => 'local@example.com', 'title' => 'Call', 'all_day' => true, 'start' => '2026-10-20', 'end' => '2026-10-21', 'rrule' => 'FREQ=DAILY;COUNT=2']);

        $changes = $provider->pull($calendar, ['token' => 'kept'], SyncBudget::seconds(5));

        $this->assertTrue($provider->canWrite());
        $this->assertTrue($provider->writableRecurrence($vcal->VEVENT));
        $this->assertSame([], $provider->listCalendars(new CalendarConnection()));
        $this->assertTrue($changes->complete);
        $this->assertFalse($changes->fullListing);
        $this->assertSame([[], [], [], ['token' => 'kept']], [$changes->upserts, $changes->deletes, $changes->unchanged, $changes->state]);
        foreach ([$provider->create($calendar, $vcal), $provider->update($calendar, new CalendarEvent(), $vcal, '20261021')] as $ref) {
            $this->assertSame(['', null, null, null], [$ref->remoteId, $ref->href, $ref->etag, $ref->uid]);
        }
        $provider->delete($calendar, new CalendarEvent());
    }

    public function test_calendars_are_writable_through_their_provider_and_a_healthy_connection(): void
    {
        $registry = app(ProviderRegistry::class);
        $registry->swap(Calendar::PROVIDER_ICS, new FakeProvider(false));
        foreach ([Calendar::PROVIDER_CALDAV, Calendar::PROVIDER_GOOGLE, Calendar::PROVIDER_MICROSOFT] as $provider) {
            $registry->swap($provider, new FakeProvider(true));
        }

        $this->assertTrue($this->calendar(Calendar::PROVIDER_LOCAL)->isWritable());
        $this->assertFalse($this->calendar(Calendar::PROVIDER_ICS)->isWritable());
        foreach ([Calendar::PROVIDER_CALDAV, Calendar::PROVIDER_GOOGLE, Calendar::PROVIDER_MICROSOFT] as $provider) {
            $this->assertTrue($this->calendar($provider, CalendarConnection::STATUS_OK)->isWritable(), $provider . ' with a working connection');
            $this->assertFalse($this->calendar($provider, CalendarConnection::STATUS_NEEDS_REAUTH)->isWritable(), $provider . ' needing reauthorization');
            $this->assertFalse($this->calendar($provider, CalendarConnection::STATUS_ERROR)->isWritable(), $provider . ' with a broken connection');
            $this->assertFalse($this->calendar($provider)->isWritable(), $provider . ' without a connection');
        }

        $registry->swap(Calendar::PROVIDER_CALDAV, new FakeProvider(false));
        $this->assertFalse($this->calendar(Calendar::PROVIDER_CALDAV, CalendarConnection::STATUS_OK)->isWritable());
    }

    public function test_a_connection_knows_its_calendars_and_starts_healthy(): void
    {
        $connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'agent@example.com']);
        $first = Calendar::create(['name' => 'Linked ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $connection->id]);
        $second = Calendar::create(['name' => 'Linked ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $connection->id]);
        $this->calendar(Calendar::PROVIDER_LOCAL);

        $this->assertSame(CalendarConnection::STATUS_OK, $connection->status);
        $this->assertSame([$first->id, $second->id], $connection->calendars()->orderBy('id')->pluck('id')->all());
    }

    public function test_provider_errors_carry_their_answer_to_the_agent(): void
    {
        $previous = new \RuntimeException('cURL error 7');
        $cases = [
            [new ProviderConflict(412), 409, 'changed_elsewhere', 'This event was changed elsewhere. Reload and try again.', 412],
            [new ProviderUnavailable(), 502, 'provider_unavailable', 'The calendar provider could not be reached. Try again later.', null],
            [new ProviderUnavailable('The calendar feed is too large (over 20 MB).', 200, $previous), 502, 'provider_unavailable', 'The calendar feed is too large (over 20 MB).', 200],
            [new ConnectionNeedsReauth(401), 502, 'connection_needs_reauth', "This calendar's connection needs to be re-authorized by an administrator.", 401],
        ];
        foreach ($cases as [$exception, $status, $code, $message, $httpStatus]) {
            $this->assertInstanceOf(ProviderException::class, $exception);
            $this->assertSame([$status, $code, $message, $httpStatus], [$exception->status(), $exception->code(), $exception->userMessage(), $exception->httpStatus()]);
            $response = $exception->toResponse();
            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame(['code' => $code, 'message' => $message], json_decode($response->getContent(), true));
        }
        $this->assertSame($previous, $cases[2][0]->getPrevious());
    }

    public function test_a_sync_budget_runs_out(): void
    {
        $budget = SyncBudget::seconds(30);
        $short = SyncBudget::seconds(0.05);

        $this->assertTrue(SyncBudget::seconds(0)->exhausted());
        $this->assertFalse($budget->exhausted());
        $this->assertGreaterThan(29.0, $budget->remaining());
        $this->assertLessThanOrEqual(30.0, $budget->remaining());
        usleep(60000);
        $this->assertTrue($short->exhausted());
        $this->assertSame(0.0, $short->remaining());
    }

    public function test_value_objects_keep_what_they_are_given(): void
    {
        $event = new RemoteEvent('evt-1', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
        $partial = new RemoteEvent('evt-2', 'ics', '/cal/2.ics', '"7"', true, true, ['20261021T080000Z']);
        $changes = new ChangeSet([$event], ['evt-3'], ['token' => 'next'], false, true);
        $ref = new RemoteRef('evt-4', '/cal/4.ics', '"1"', 'uid-4@example.com');
        $remoteCalendar = new RemoteCalendar('me/calendars/AAMk', 'Support', '#3498db', 'Europe/Amsterdam', true);

        $this->assertSame(['evt-1', null, null, false, false, []], [$event->remoteId, $event->href, $event->etag, $event->readOnly, $event->partial, $event->cancelledKeys]);
        $this->assertSame(['/cal/2.ics', '"7"', true, true, ['20261021T080000Z']], [$partial->href, $partial->etag, $partial->readOnly, $partial->partial, $partial->cancelledKeys]);
        $this->assertSame([[$event], ['evt-3'], ['token' => 'next'], false, true, []], [$changes->upserts, $changes->deletes, $changes->state, $changes->complete, $changes->fullListing, $changes->unchanged]);
        $this->assertSame(['evt-4', '/cal/4.ics', '"1"', 'uid-4@example.com'], [$ref->remoteId, $ref->href, $ref->etag, $ref->uid]);
        $this->assertSame(['me/calendars/AAMk', 'Support', '#3498db', 'Europe/Amsterdam', true, false], [$remoteCalendar->remoteId, $remoteCalendar->name, $remoteCalendar->color, $remoteCalendar->timezone, $remoteCalendar->writable, $remoteCalendar->primary]);
    }
}
