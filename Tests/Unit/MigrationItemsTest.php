<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\Conversation;
use App\Thread;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Services\LineItemText;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class MigrationItemsTest extends TestCase
{
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Amsterdam']);
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->calendar = Calendar::create(['name' => 'Items ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'fields' => [['id' => 172604180330, 'name' => 'Kind', 'type' => 'text', 'required' => false, 'options' => []]]]);
    }

    private function item(array $attributes): int
    {
        return DB::table('calendar_items')->insertGetId($attributes + [
            'calendar_id'  => $this->calendar->id,
            'author_id'    => 1,
            'is_all_day'   => 0,
            'is_private'   => 0,
            'is_read_only' => 0,
            'title'        => 'Item',
            'body'         => '',
            'state'        => 'published',
            'location'     => null,
            'created_at'   => '2025-01-01 10:00:00',
            'updated_at'   => '2025-01-01 10:00:00',
        ]);
    }

    private function converted(int $itemId): CalendarEvent
    {
        return CalendarEvent::where('legacy_id', (string) $itemId)->firstOrFail();
    }

    public function test_timed_items_keep_their_utc_instant_and_links(): void
    {
        $user = $this->makeUser();
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $id = $this->item([
            'title' => 'Call Acme', 'body' => 'Notes', 'location' => 'Office', 'author_id' => $user->id,
            'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00',
            'custom_fields' => json_encode(['author_id' => $user->id, 'conversation_id' => (string) $conversation->id, 'custom_field_172604180330' => 'Visit']),
        ]);

        app(MigrationService::class)->convertItems();

        $event = $this->converted($id);
        $this->assertSame(['2026-10-20 08:00:00', '2026-10-20 09:00:00', 'Europe/Amsterdam'], [$event->starts_at, $event->ends_at, $event->timezone]);
        $this->assertSame(['Call Acme', $user->id, $conversation->id], [$event->summary, $event->author_id, $event->conversation_id]);
        $this->assertSame(['172604180330' => 'Visit'], $event->field_values);
        $this->assertStringContainsString('DTSTART;TZID=Europe/Amsterdam:20261020T100000', $event->ics);
        $this->assertSame(1, CalendarOccurrence::where('event_id', $event->id)->count());
    }

    public function test_all_day_items_become_dates_even_when_stored_from_another_browser_timezone(): void
    {
        $amsterdamBrowser = $this->item(['is_all_day' => 1, 'start' => '2026-10-04 22:00:00', 'end' => '2026-10-05 21:59:59']);
        $utcBrowser = $this->item(['is_all_day' => 1, 'start' => '2026-10-05 00:00:00', 'end' => '2026-10-05 23:59:59']);
        $threeDays = $this->item(['is_all_day' => 1, 'start' => '2026-10-04 22:00:00', 'end' => '2026-10-07 21:59:59']);

        app(MigrationService::class)->convertItems();

        foreach ([$amsterdamBrowser, $utcBrowser] as $id) {
            $this->assertSame(['2026-10-05 00:00:00', '2026-10-06 00:00:00', true], [$this->converted($id)->starts_at, $this->converted($id)->ends_at, $this->converted($id)->all_day]);
        }
        $this->assertSame('2026-10-08 00:00:00', $this->converted($threeDays)->ends_at);
    }

    public function test_is_idempotent_and_reports_items_it_cannot_convert(): void
    {
        $this->item(['start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $orphan = $this->item(['calendar_id' => 99999999, 'title' => 'Orphan', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $external = Calendar::create(['name' => 'Ext ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS]);
        $stale = $this->item(['calendar_id' => $external->id, 'title' => 'Stale', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);

        $service = app(MigrationService::class);
        $baseline = count($service->report());
        $service->convertItems();
        $afterFirst = $service->report();
        $service->convertItems();
        $afterSecond = $service->report();

        $this->assertSame(1, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
        $this->assertEqualsCanonicalizing([$orphan, $stale], $service->skippedItemIds());
        $errors = array_values(array_filter(array_slice($afterFirst, $baseline), function (array $entry) {
            return $entry['level'] === 'error';
        }));
        $this->assertSame('Orphan', $errors[0]['context']['title']);
        $this->assertSame('2026-10-20 08:00:00', $errors[0]['context']['start']);
        // The second run only adds its summary line.
        $added = array_slice($afterSecond, count($afterFirst));
        $this->assertCount(1, $added);
        $this->assertSame(':count events were converted. All-day events were read in the timezone :timezone.', $added[0]['message']);
        $this->assertSame(0, $added[0]['context']['count']);
    }

    public function test_unreadable_custom_fields_convert_the_event_without_values_and_warn(): void
    {
        $id = $this->item(['title' => 'Broken fields', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00', 'custom_fields' => '"not an object"']);

        $service = app(MigrationService::class);
        $service->convertItems();

        $this->assertSame([], $this->converted($id)->field_values ?? []);
        $this->assertNull($this->converted($id)->conversation_id);
        $warnings = array_values(array_filter($service->report(), function (array $entry) {
            return $entry['level'] === 'warning' && ($entry['context']['title'] ?? null) === 'Broken fields';
        }));
        $this->assertCount(1, $warnings);
        $this->assertSame($id, $warnings[0]['context']['id']);
    }

    /** Entries added to the report after the given baseline whose context title matches. */
    private function entriesFor(MigrationService $service, int $baseline, string $title): array
    {
        return array_values(array_filter(array_slice($service->report(), $baseline), function (array $entry) use ($title) {
            return ($entry['context']['title'] ?? null) === $title;
        }));
    }

    private function relaxedSqlMode(callable $work): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Zero dates only exist in MySQL without strict mode.');
        }
        $previous = DB::selectOne('select @@sql_mode as mode')->mode;
        DB::statement("set sql_mode = ''");
        try {
            $work();
        } finally {
            DB::statement('set sql_mode = ?', [$previous]);
        }
    }

    public function test_items_with_unusable_times_are_skipped_with_an_error_and_the_rest_convert(): void
    {
        $zero = $emptyEnd = null;
        $this->relaxedSqlMode(function () use (&$zero, &$emptyEnd) {
            $zero = $this->item(['title' => 'Zero start', 'start' => '0000-00-00 00:00:00', 'end' => '2026-10-20 09:00:00']);
            $emptyEnd = $this->item(['title' => 'Empty end', 'start' => '2026-10-20 08:00:00', 'end' => '']);
        });
        $good = $this->item(['title' => 'Fine', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);

        $service = app(MigrationService::class);
        $baseline = count($service->report());
        $service->convertItems();

        $this->assertSame(1, CalendarEvent::where('legacy_id', (string) $good)->count());
        $this->assertEqualsCanonicalizing([$zero, $emptyEnd], $service->skippedItemIds());
        foreach (['Zero start', 'Empty end'] as $title) {
            $entries = $this->entriesFor($service, $baseline, $title);
            $this->assertCount(1, $entries, $title);
            $this->assertSame('error', $entries[0]['level']);
        }
    }

    public function test_null_and_unparsable_times_are_rejected(): void
    {
        $service = app(MigrationService::class);
        $convert = new \ReflectionMethod($service, 'convertItem');
        $convert->setAccessible(true);
        foreach ([[null, '2026-10-20 09:00:00'], ['2026-10-20 08:00:00', ''], ['yesterday', '2026-10-20 09:00:00'], ['1899-12-31 00:00:00', '2026-10-20 09:00:00'], ['2026-02-31 08:00:00', '2026-10-20 09:00:00']] as [$start, $end]) {
            $item = (object) ['id' => 1, 'title' => 'Bad', 'author_id' => 1, 'is_all_day' => 0, 'start' => $start, 'end' => $end, 'location' => null, 'body' => '', 'custom_fields' => null, 'calendar_id' => $this->calendar->id];
            try {
                $convert->invoke($service, $item, $this->calendar, new DateTimeZone('Europe/Amsterdam'));
                $this->fail('Expected an exception for ' . var_export($start, true) . ' / ' . var_export($end, true));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('is not a usable date', $e->getMessage());
            }
        }
    }

    /** On PostgreSQL v2's dateTimeTz columns are "timestamp with time zone", read back with the session's offset. */
    public function test_postgresql_times_with_an_offset_are_converted_to_utc(): void
    {
        $service = app(MigrationService::class);
        $convert = new \ReflectionMethod($service, 'convertItem');
        $convert->setAccessible(true);
        $cases = [
            ['2023-01-12 16:45:00+00', '2023-01-12 17:45:00+00', '2023-01-12 16:45:00'],
            ['2023-01-12 17:45:00+01', '2023-01-12 18:45:00+01', '2023-01-12 16:45:00'],
            ['2023-01-12 22:15:00+05:30', '2023-01-12 23:15:00+05:30', '2023-01-12 16:45:00'],
            ['2023-01-12 13:45:00-03', '2023-01-12 14:45:00-03', '2023-01-12 16:45:00'],
        ];
        foreach ($cases as $i => [$start, $end, $utc]) {
            $item = (object) ['id' => 900 + $i, 'title' => 'Offset ' . $i, 'author_id' => 1, 'is_all_day' => 0, 'start' => $start, 'end' => $end, 'location' => null, 'body' => '', 'custom_fields' => null, 'calendar_id' => $this->calendar->id];
            $convert->invoke($service, $item, $this->calendar, new DateTimeZone('Europe/Amsterdam'));
            $this->assertSame($utc, CalendarEvent::where('legacy_id', (string) (900 + $i))->value('starts_at'), $start);
        }
    }

    public function test_an_end_before_the_start_is_clamped_with_a_warning(): void
    {
        $id = $this->item(['title' => 'Backwards', 'start' => '2026-10-20 09:00:00', 'end' => '2026-10-20 08:00:00']);

        $service = app(MigrationService::class);
        $baseline = count($service->report());
        $service->convertItems();

        $event = $this->converted($id);
        $this->assertSame($event->starts_at, $event->ends_at);
        $warnings = $this->entriesFor($service, $baseline, 'Backwards');
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]['level']);
        $this->assertSame([$id, '2026-10-20 09:00:00', '2026-10-20 08:00:00'], [$warnings[0]['context']['id'], $warnings[0]['context']['start'], $warnings[0]['context']['end']]);
    }

    public function test_an_all_day_item_shorter_than_half_a_day_becomes_one_day_with_a_warning(): void
    {
        $id = $this->item(['title' => 'Too short', 'is_all_day' => 1, 'start' => '2026-10-04 22:00:00', 'end' => '2026-10-04 22:00:00']);

        $service = app(MigrationService::class);
        $baseline = count($service->report());
        $service->convertItems();

        $event = $this->converted($id);
        $this->assertSame(['2026-10-05 00:00:00', '2026-10-06 00:00:00'], [$event->starts_at, $event->ends_at]);
        $warnings = $this->entriesFor($service, $baseline, 'Too short');
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]['level']);
    }

    public function test_an_all_day_span_across_the_autumn_clock_change_keeps_its_two_days(): void
    {
        $id = $this->item(['is_all_day' => 1, 'start' => '2026-10-23 22:00:00', 'end' => '2026-10-25 22:59:59']);

        app(MigrationService::class)->convertItems();

        $event = $this->converted($id);
        $this->assertSame(['2026-10-24 00:00:00', '2026-10-26 00:00:00'], [$event->starts_at, $event->ends_at]);
    }

    public function test_all_day_items_are_read_in_a_negative_offset_timezone(): void
    {
        config(['app.timezone' => 'America/New_York']);
        $id = $this->item(['is_all_day' => 1, 'start' => '2026-10-05 04:00:00', 'end' => '2026-10-06 03:59:59']);

        app(MigrationService::class)->convertItems();

        $event = $this->converted($id);
        $this->assertSame(['2026-10-05 00:00:00', '2026-10-06 00:00:00'], [$event->starts_at, $event->ends_at]);
    }

    public function test_double_encoded_custom_fields_are_converted_with_a_warning(): void
    {
        $id = $this->item(['title' => 'Double', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00', 'custom_fields' => json_encode(json_encode(['custom_field_172604180330' => 'Visit']))]);

        $service = app(MigrationService::class);
        $baseline = count($service->report());
        $service->convertItems();

        $this->assertSame(['172604180330' => 'Visit'], $this->converted($id)->field_values);
        $warnings = $this->entriesFor($service, $baseline, 'Double');
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]['level']);
    }

    public function test_more_items_than_one_chunk_all_convert(): void
    {
        $rows = [];
        for ($i = 0; $i < 201; $i++) {
            $rows[] = ['calendar_id' => $this->calendar->id, 'author_id' => 1, 'is_all_day' => 0, 'is_private' => 0, 'is_read_only' => 0, 'title' => 'Bulk ' . $i, 'body' => '', 'state' => 'published', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00', 'created_at' => '2025-01-01 10:00:00', 'updated_at' => '2025-01-01 10:00:00'];
        }
        DB::table('calendar_items')->insert($rows);

        $service = app(MigrationService::class);
        $service->convertItems();

        $this->assertSame(201, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
        $summary = array_values(array_filter($service->report(), function (array $entry) {
            return $entry['message'] === ':count events were converted. All-day events were read in the timezone :timezone.';
        }));
        $this->assertSame(201, end($summary)['context']['count']);
    }

    public function test_a_failing_item_is_rolled_back_reported_and_left_retryable_not_skipped(): void
    {
        $failing = $this->item(['title' => 'Explodes', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00', 'custom_fields' => json_encode(json_encode([]))]);
        $good = $this->item(['title' => 'Survives', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $occurrences = $this->explodingOccurrences();
        $service = new MigrationService($occurrences);
        $baseline = count($service->report());

        $service->convertItems();

        $this->assertSame(0, CalendarEvent::where('legacy_id', (string) $failing)->count());
        $this->assertSame(1, CalendarEvent::where('legacy_id', (string) $good)->count());
        $this->assertSame([], $service->skippedItemIds());
        $entries = $this->entriesFor($service, $baseline, 'Explodes');
        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]['level']);
        $this->assertStringContainsString('could not be converted yet', $entries[0]['message']);
        $this->assertStringContainsString('forced failure', $entries[0]['context']['error']);
        $this->assertFalse($service->isComplete());

        // Once the cause is gone the next run converts it.
        $occurrences->fail = false;
        $service->convertItems();
        $this->assertSame(1, CalendarEvent::where('legacy_id', (string) $failing)->count());
        $this->assertTrue($service->isComplete());
    }

    public function test_a_skip_whose_report_write_fails_is_not_recorded_and_the_next_run_reports_it(): void
    {
        $orphan = $this->item(['calendar_id' => 99999999, 'title' => 'Unreported', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        // The report write fails (a lost connection, a lock timeout, max_allowed_packet) after the item was handled.
        \App\Option::saving(function (\App\Option $option) {
            if ($option->name === MigrationService::REPORT_OPTION) {
                throw new \RuntimeException('report write failed');
            }
        });
        try {
            app(MigrationService::class)->convertItems();
            $this->fail('The failing report write should have stopped the run.');
        } catch (\RuntimeException $e) {
            $this->assertSame('report write failed', $e->getMessage());
        } finally {
            app('events')->forget('eloquent.saving: ' . \App\Option::class);
        }

        $service = app(MigrationService::class);
        $this->assertSame(0, CalendarEvent::where('legacy_id', (string) $orphan)->count());
        $this->assertNotContains($orphan, $service->skippedItemIds());
        $this->assertFalse($service->isComplete());

        $baseline = count($service->report());
        $service->convertItems();

        $this->assertSame([$orphan], $service->skippedItemIds());
        $entries = $this->entriesFor($service, $baseline, 'Unreported');
        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]['level']);
    }

    public function test_items_of_a_calendar_whose_conversion_failed_wait_for_that_calendar(): void
    {
        // A v2 CalDAV calendar whose conversion fails keeps the column default provider "local" and no export token.
        $caldav = DB::table('calendars')->insertGetId([
            'name' => 'Dav ' . uniqid(), 'enabled' => 1, 'color' => '#3498db', 'type' => 'caldav',
            'custom_fields' => json_encode(['url' => 'https://dav.example.com/', 'username' => 'explode', 'password' => 'x']),
            'created_at' => '2024-05-01 10:00:00', 'updated_at' => '2024-05-01 10:00:00',
        ]);
        CalendarConnection::created(function (CalendarConnection $connection) {
            if ($connection->account === 'explode') {
                throw new \RuntimeException('Simulated failure');
            }
        });
        try {
            app(MigrationService::class)->convertCalendars();
        } finally {
            app('events')->forget('eloquent.created: ' . CalendarConnection::class);
        }
        $this->assertSame(['local', null], [Calendar::find($caldav)->provider, Calendar::find($caldav)->export_token]);
        $id = $this->item(['calendar_id' => $caldav, 'title' => 'Remote copy', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);

        $service = app(MigrationService::class);
        $service->convertItems();

        // Retryable: neither converted as a local event nor skipped, so the cleanup stays blocked.
        $this->assertSame(0, CalendarEvent::where('legacy_id', (string) $id)->count());
        $this->assertNotContains($id, $service->skippedItemIds());
        $this->assertFalse($service->isComplete());

        // Once the calendar converts, its items are handled like those of any external calendar.
        $service->convertCalendars();
        $service->convertItems();
        $this->assertSame('caldav', Calendar::find($caldav)->provider);
        $this->assertSame(0, CalendarEvent::where('legacy_id', (string) $id)->count());
        $this->assertContains($id, $service->skippedItemIds());
    }

    public function test_a_permanently_skipped_item_is_reported_with_its_full_row(): void
    {
        $id = null;
        $this->relaxedSqlMode(function () use (&$id) {
            $id = $this->item([
                'title' => 'Broken', 'body' => 'Body text', 'location' => 'Room 4', 'start' => '0000-00-00 00:00:00', 'end' => '2026-10-20 09:00:00',
                'custom_fields' => '{"custom_field_1":"Visit"}',
            ]);
        });
        $service = app(MigrationService::class);
        $baseline = count($service->report());

        $service->convertItems();

        $this->assertSame([$id], $service->skippedItemIds());
        $context = $this->entriesFor($service, $baseline, 'Broken')[0]['context'];
        $this->assertSame(['Body text', 'Room 4', '{"custom_field_1":"Visit"}'], [$context['body'], $context['location'], $context['custom_fields']]);
        $this->assertSame($this->calendar->id, $context['calendar_id']);
        $this->assertSame(0, $context['is_all_day']);
    }

    public function test_a_v2_line_item_finds_its_event_after_the_item_moved_to_another_calendar(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        // In v2 an item could move to another calendar; its line item kept the calendar it was added to.
        $addedTo = Calendar::create(['name' => 'Added to ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $id = $this->item(['start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $moved = $this->lineItem($conversation, ['calendar_item_id' => $id, 'calendar_id' => (string) $addedTo->id]);
        // A v2 external line item carries the remote event id, so a number that matches a v2 item id must not resolve.
        $external = Calendar::create(['name' => 'Ext ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS]);
        $foreign = $this->lineItem($conversation, ['calendar_item_id' => $id, 'calendar_id' => (string) $external->id]);

        app(MigrationService::class)->convertItems();

        $text = LineItemText::render($moved, true);
        $this->assertStringContainsString('#e' . $this->converted($id)->id . '"', $text);
        $this->assertStringNotContainsString('deleted by now', $text);
        $this->assertStringContainsString('deleted by now', LineItemText::render($foreign, true));
    }

    private function lineItem(Conversation $conversation, array $meta): Thread
    {
        return Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'created_by_user_id' => auth()->id(),
            'action_type' => CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR,
            'source_via'  => Thread::PERSON_USER,
            'source_type' => Thread::SOURCE_TYPE_WEB,
            'meta'        => $meta,
        ]);
    }

    private function explodingOccurrences(): OccurrenceService
    {
        return new class extends OccurrenceService {
            public bool $fail = true;

            public function refresh(CalendarEvent $event): void
            {
                if ($this->fail && $event->title_input === 'Explodes') {
                    throw new \RuntimeException('forced failure');
                }
                parent::refresh($event);
            }
        };
    }

    public function test_identical_report_entries_are_kept_once(): void
    {
        $service = app(MigrationService::class);
        $service->convertItems();
        $entries = count($service->report());

        $service->convertItems();

        $this->assertSame($entries, count($service->report()));
    }

    public function test_pending_items_are_the_ones_that_still_block_the_upgrade(): void
    {
        $failing = $this->item(['title' => 'Explodes', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $this->item(['title' => 'Survives', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $service = new MigrationService($this->explodingOccurrences());

        $service->convertItems();

        $pending = $service->pendingItems();
        $this->assertSame([$failing], array_column($pending, 'id'));
        $this->assertSame(['Explodes', '2026-10-20 08:00:00', $this->calendar->name], [$pending[0]['title'], $pending[0]['start'], $pending[0]['calendar']]);
    }

    public function test_an_admin_can_skip_a_pending_item_which_is_then_reported_with_its_full_row(): void
    {
        $failing = $this->item(['title' => 'Explodes', 'location' => 'Room 4', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $service = new MigrationService($this->explodingOccurrences());
        $service->convertItems();
        $baseline = count($service->report());

        $service->skipItem($failing, 'Lars Admin');

        $this->assertSame([$failing], $service->skippedItemIds());
        $this->assertSame([], $service->pendingItems());
        $entries = $this->entriesFor($service, $baseline, 'Explodes');
        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]['level']);
        $this->assertSame(['Lars Admin', 'Room 4'], [$entries[0]['context']['user'], $entries[0]['context']['location']]);
        $this->assertTrue($service->isComplete());

        foreach ([$failing, 99999999] as $id) {
            try {
                $service->skipItem($id, 'Lars Admin');
                $this->fail('Skipped item ' . $id);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('item', $e->errors());
            }
        }
    }

    public function test_a_skip_whose_report_write_fails_is_not_recorded(): void
    {
        $failing = $this->item(['title' => 'Explodes', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $service = new MigrationService($this->explodingOccurrences());
        $service->convertItems();
        \App\Option::saving(function (\App\Option $option) {
            if ($option->name === MigrationService::REPORT_OPTION) {
                throw new \RuntimeException('report write failed');
            }
        });
        try {
            $service->skipItem($failing, 'Lars Admin');
            $this->fail('The failing report write should have stopped the skip.');
        } catch (\RuntimeException $e) {
            $this->assertSame('report write failed', $e->getMessage());
        } finally {
            app('events')->forget('eloquent.saving: ' . \App\Option::class);
        }

        $this->assertNotContains($failing, app(MigrationService::class)->skippedItemIds());
    }

    public function test_report_entries_with_invalid_utf8_keep_distinct_keys(): void
    {
        $key = new \ReflectionMethod(MigrationService::class, 'entryKey');
        $key->setAccessible(true);
        $a = ['level' => 'warning', 'message' => 'x', 'context' => ['title' => "Broken \xB1 one"]];
        $b = ['level' => 'warning', 'message' => 'x', 'context' => ['title' => "Broken \xB2 two"]];

        $this->assertNotSame($key->invoke(null, $a), $key->invoke(null, $b));
        $this->assertSame($key->invoke(null, $a), $key->invoke(null, $a));
    }
}
