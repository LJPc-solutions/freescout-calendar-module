<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\Conversation;
use App\Thread;
use App\User;
use Dallgoot\Yaml\Yaml;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Services\EventImporter;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\LineItemText;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

class EventImporterTest extends TestCase
{
    private FakeProvider $provider;
    private CalendarConnection $connection;
    private Calendar $calendar;
    private EventImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->provider = new FakeProvider(true);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_CALDAV, $this->provider);
        $this->connection = CalendarConnection::create(['provider' => Calendar::PROVIDER_CALDAV, 'account' => 'agent@example.com']);
        $this->calendar = Calendar::create([
            'name'          => 'Remote ' . uniqid(),
            'provider'      => Calendar::PROVIDER_CALDAV,
            'connection_id' => $this->connection->id,
            'timezone'      => 'Europe/Amsterdam',
            'fields'        => [['id' => 7, 'name' => 'Kind', 'type' => 'dropdown', 'required' => false, 'options' => ['Call', 'Visit']]],
        ]);
        $this->importer = app(EventImporter::class);
    }

    private static function ics(string ...$vevents): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Remote//EN\r\n" . implode('', $vevents) . "END:VCALENDAR\r\n";
    }

    private static function vevent(string ...$lines): string
    {
        return "BEGIN:VEVENT\r\n" . implode("\r\n", $lines) . "\r\nEND:VEVENT\r\n";
    }

    private static function single(string $uid, string $summary, string ...$more): string
    {
        return self::ics(self::vevent('UID:' . $uid, 'DTSTAMP:20261001T000000Z', 'SUMMARY:' . $summary, 'DTSTART:20261020T080000Z', 'DTEND:20261020T090000Z', ...$more));
    }

    private function import(string $remoteId, string $ics, int $generation = 1, ...$options): ?CalendarEvent
    {
        return $this->importer->upsert($this->calendar, new RemoteEvent($remoteId, $ics, ...$options), $generation);
    }

    private function occurrences(CalendarEvent $event): array
    {
        return CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->get(['starts_at', 'summary'])->map(function ($row) {
            return [$row->starts_at, $row->summary];
        })->all();
    }

    public function test_creates_an_event_with_its_remote_identifiers(): void
    {
        $event = $this->import('/cal/a.ics', self::single('a@example.com', 'Call', 'LOCATION:Office'), 3, '/cal/a.ics', '"1"');

        $this->assertSame('a@example.com', $event->uid);
        $this->assertSame('Call', $event->summary);
        $this->assertSame('2026-10-20 08:00:00', $event->starts_at);
        $this->assertSame(['/cal/a.ics', '/cal/a.ics', '"1"', 3], [$event->remote_id, $event->remote_href, $event->etag, $event->sync_generation]);
        $this->assertSame([false, false, null, null, []], [$event->read_only, $event->is_private, $event->author_id, $event->title_input, $event->field_values]);
        $this->assertStringContainsString('LOCATION:Office', $event->ics);
        $this->assertSame([['2026-10-20 08:00:00', null]], $this->occurrences($event));
        $this->assertSame($event->id, CalendarEvent::findByRemoteId($this->calendar->id, '/cal/a.ics')->id);
    }

    public function test_an_update_keeps_freescouts_own_fields(): void
    {
        $event = $this->import('/cal/a.ics', self::single('a@example.com', 'Call'));
        $user = $this->makeUser();
        $event->update(['author_id' => $user->id, 'conversation_id' => 4242, 'field_values' => ['7' => 'Visit'], 'reminder_minutes' => 15, 'legacy_id' => 'v2-uid', 'title_input' => 'Call']);

        $moved = $this->import('/cal/a.ics', str_replace('20261020T080000Z', '20261020T070000Z', self::single('a@example.com', 'Call')), 2, '/cal/a.ics', '"2"');

        $this->assertSame($event->id, $moved->id);
        $this->assertSame('2026-10-20 07:00:00', $moved->starts_at);
        $this->assertSame(['"2"', 2], [$moved->etag, $moved->sync_generation]);
        $this->assertSame([$user->id, 4242, ['7' => 'Visit'], 15, 'v2-uid', 'Call'], [$moved->author_id, $moved->conversation_id, $moved->field_values, $moved->reminder_minutes, $moved->legacy_id, $moved->title_input]);

        $renamed = $this->import('/cal/a.ics', self::single('a@example.com', 'Call about the contract'));

        $this->assertSame('Call about the contract', $renamed->summary);
        $this->assertNull($renamed->title_input, 'the form shows the title that was typed elsewhere');
        $this->assertSame([4242, ['7' => 'Visit']], [$renamed->conversation_id, $renamed->field_values]);
    }

    public function test_an_event_created_in_freescout_is_matched_by_uid_until_it_has_a_remote_id(): void
    {
        $local = app(EventService::class)->create($this->calendar, $this->makeUser(), ['title' => 'Local', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00']);

        $event = $this->import('/cal/local.ics', self::single($local->uid, 'Local'), 1, '/cal/local.ics', '"9"');

        $this->assertSame($local->id, $event->id);
        $this->assertSame(['/cal/local.ics', '"9"'], [$event->remote_id, $event->etag]);
        $this->assertSame(1, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
    }

    public function test_a_partial_update_merges_overrides_and_cancellations_into_the_stored_series(): void
    {
        $master = self::vevent('UID:s@example.com', 'SUMMARY:Standup', 'DTSTART:20261019T080000Z', 'DTEND:20261019T081500Z', 'RRULE:FREQ=DAILY;COUNT=5');
        $event = $this->import('google-s', self::ics(
            $master,
            self::vevent('UID:s@example.com', 'RECURRENCE-ID:20261020T080000Z', 'SUMMARY:Moved', 'DTSTART:20261020T100000Z', 'DTEND:20261020T101500Z'),
            self::vevent('UID:s@example.com', 'RECURRENCE-ID:20261022T080000Z', 'SUMMARY:Doomed', 'DTSTART:20261022T090000Z', 'DTEND:20261022T091500Z')
        ), 1, null, '"m1"');

        $merged = $this->import('google-s', self::ics(
            self::vevent('UID:s@example.com', 'RECURRENCE-ID:20261020T080000Z', 'SUMMARY:Moved again', 'DTSTART:20261020T120000Z', 'DTEND:20261020T121500Z'),
            self::vevent('UID:s@example.com', 'RECURRENCE-ID:20261021T080000Z', 'SUMMARY:Added', 'DTSTART:20261021T080000Z', 'DTEND:20261021T081500Z')
        ), 2, null, null, false, true, ['20261022T080000Z', '20261023T080000Z']);

        $this->assertSame($event->id, $merged->id);
        $this->assertSame('"m1"', $merged->etag, 'a partial without an etag keeps the stored one');
        $this->assertSame('Standup', $merged->summary);
        $this->assertSame([
            ['2026-10-19 08:00:00', null],
            ['2026-10-20 12:00:00', 'Moved again'],
            ['2026-10-21 08:00:00', 'Added'],
        ], $this->occurrences($merged));
        $vcal = Reader::read($merged->ics);
        $this->assertCount(3, $vcal->select('VEVENT'));
        $this->assertStringContainsString('EXDATE:20261022T080000Z', $merged->ics);
        $this->assertStringContainsString('EXDATE:20261023T080000Z', $merged->ics);
        $this->assertStringNotContainsString('Doomed', $merged->ics);
    }

    public function test_cancelled_occurrences_alone_reach_the_stored_series(): void
    {
        $event = $this->import('google-c', self::ics(self::vevent('UID:c@example.com', 'SUMMARY:Standup', 'DTSTART:20261019T080000Z', 'DTEND:20261019T081500Z', 'RRULE:FREQ=DAILY;COUNT=5')));

        $merged = $this->import('google-c', self::ics(), 2, null, null, false, true, ['20261021T080000Z']);

        $this->assertSame($event->id, $merged->id);
        $this->assertSame(['2026-10-19 08:00:00', '2026-10-20 08:00:00', '2026-10-22 08:00:00', '2026-10-23 08:00:00'], array_column($this->occurrences($merged), 0));
        $this->assertStringContainsString('EXDATE:20261021T080000Z', $merged->ics);
    }

    public function test_a_partial_update_for_an_unknown_event_needs_its_master(): void
    {
        $log = LogRecorder::install();
        $override = self::vevent('UID:unknown@example.com', 'RECURRENCE-ID:20261020T080000Z', 'SUMMARY:Moved', 'DTSTART:20261020T100000Z', 'DTEND:20261020T101500Z');

        $this->assertNull($this->import('google-unknown', self::ics($override), 1, null, null, false, true));
        $this->assertSame(0, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
        $this->assertSame('A partial calendar update for an unknown event was skipped', $log->records('info')[0]['message']);

        $master = self::vevent('UID:unknown@example.com', 'SUMMARY:Standup', 'DTSTART:20261019T080000Z', 'DTEND:20261019T081500Z', 'RRULE:FREQ=DAILY;COUNT=3');
        $event = $this->import('google-unknown', self::ics($master, $override), 1, null, null, false, true);
        $this->assertSame(['2026-10-19 08:00:00', '2026-10-20 10:00:00', '2026-10-21 08:00:00'], array_column($this->occurrences($event), 0));
    }

    public function test_a_new_dtstamp_alone_leaves_the_event_and_its_occurrences_alone(): void
    {
        $event = $this->import('/cal/a.ics', self::single('a@example.com', 'Call'), 1, '/cal/a.ics', '"1"');
        $occurrenceIds = CalendarOccurrence::where('event_id', $event->id)->pluck('id')->all();
        DB::table('calendar_events')->where('id', $event->id)->update(['updated_at' => '2026-01-01 00:00:00']);

        $again = $this->import('/cal/a.ics', str_replace('DTSTAMP:20261001T000000Z', 'DTSTAMP:20261007T120000Z', self::single('a@example.com', 'Call')), 2, '/cal/a.ics', '"1"');

        $this->assertSame($event->id, $again->id);
        $stored = $event->fresh();
        $this->assertSame([2, '2026-01-01 00:00:00'], [$stored->sync_generation, (string) $stored->updated_at]);
        $this->assertStringContainsString('DTSTAMP:20261001T000000Z', $stored->ics);
        $this->assertSame($occurrenceIds, CalendarOccurrence::where('event_id', $event->id)->pluck('id')->all());
    }

    public function test_private_events_are_reduced_to_busy_and_read_only(): void
    {
        $event = $this->import('/cal/p.ics', self::single('p@example.com', 'Doctor', 'CLASS:PRIVATE', 'LOCATION:Clinic', 'DESCRIPTION:Bring the results'));

        $this->assertSame('Busy', $event->summary);
        $this->assertSame([true, true], [$event->is_private, $event->read_only]);
        $this->assertStringContainsString('CLASS:PRIVATE', $event->ics);
        foreach (['Doctor', 'Clinic', 'Bring the results'] as $secret) {
            $this->assertStringNotContainsString($secret, $event->ics);
        }
    }

    public function test_a_series_with_a_private_occurrence_is_read_only(): void
    {
        // A write would send the stored copy, and the server's private occurrence would become the busy stub.
        $event = $this->import('/cal/po.ics', self::ics(
            self::vevent('UID:po@example.com', 'SUMMARY:Standup', 'DTSTART:20261019T080000Z', 'DTEND:20261019T081500Z', 'RRULE:FREQ=DAILY;COUNT=3'),
            self::vevent('UID:po@example.com', 'RECURRENCE-ID:20261020T080000Z', 'SUMMARY:Doctor', 'CLASS:CONFIDENTIAL', 'LOCATION:Clinic', 'DTSTART:20261020T100000Z', 'DTEND:20261020T101500Z')
        ));

        $this->assertSame(['Standup', false, true], [$event->summary, $event->is_private, $event->read_only]);
        $this->assertSame([['2026-10-19 08:00:00', null], ['2026-10-20 10:00:00', 'Busy'], ['2026-10-21 08:00:00', null]], $this->occurrences($event));
        $this->assertStringNotContainsString('Clinic', $event->ics);
    }

    public function test_the_readable_block_is_stripped_from_every_description(): void
    {
        $event = $this->import('/cal/b.ics', self::ics(
            self::vevent('UID:b@example.com', 'SUMMARY:Visit', 'DTSTART:20261019T080000Z', 'RRULE:FREQ=DAILY;COUNT=2', 'DESCRIPTION:Bring the contract\n\n[FreeScout]\nKind: Visit\nTicket #12: https://desk.example.com/conversation/12'),
            self::vevent('UID:b@example.com', 'RECURRENCE-ID:20261020T080000Z', 'SUMMARY:Visit', 'DTSTART:20261020T090000Z', 'DESCRIPTION:[FreeScout]\nKind: Visit')
        ));

        $vevents = Reader::read($event->ics)->select('VEVENT');
        $this->assertSame('Bring the contract', (string) $vevents[0]->DESCRIPTION);
        $this->assertFalse(isset($vevents[1]->DESCRIPTION));
    }

    public function test_v2_metadata_in_the_description_links_the_event_once(): void
    {
        $v2 = function (array $customFields, string $body): string {
            return 'DESCRIPTION:' . preg_replace('/([\,;])/', '\\\$1', str_replace("\n", '\n', Yaml::dump(['body' => $body, 'custom_fields' => $customFields, 'custom_fields_mapping' => []])));
        };

        $event = $this->import('/cal/v2.ics', self::single('v2@example.com', 'Visit', $v2(['conversation_id' => 12, 'author_id' => 3, 'custom_field_7' => 'Visit'], 'Notes')));

        $this->assertSame([['7' => 'Visit'], 12, 3], [$event->field_values, $event->conversation_id, $event->author_id]);
        $this->assertSame('Notes', (string) Reader::read($event->ics)->VEVENT->DESCRIPTION);

        $event->update(['field_values' => ['7' => 'Call']]);
        $again = $this->import('/cal/v2.ics', self::single('v2@example.com', 'Visit', $v2(['conversation_id' => 99, 'author_id' => 4, 'custom_field_7' => 'Visit'], 'Notes')));
        $this->assertSame([['7' => 'Call'], 12, 3], [$again->field_values, $again->conversation_id, $again->author_id]);
    }

    public function test_text_that_only_looks_like_yaml_stays_as_it_is(): void
    {
        $event = $this->import('/cal/t.ics', self::single('t@example.com', 'Visit', 'DESCRIPTION:body: x'));

        $this->assertSame('body: x', (string) Reader::read($event->ics)->VEVENT->DESCRIPTION);
        $this->assertNull($event->conversation_id);
    }

    public function test_unreadable_data_is_skipped_and_leaves_the_stored_event_alone(): void
    {
        $stored = $this->import('/cal/a.ics', self::single('a@example.com', 'Call'));
        $log = LogRecorder::install();

        $this->assertNull($this->import('/cal/a.ics', 'This is not iCalendar data'));
        $this->assertNull($this->import('/cal/a.ics', self::ics(self::vevent('UID:a@example.com', 'SUMMARY:No start'))));
        $this->assertNull($this->import('/cal/n.ics', self::ics()));

        $this->assertSame($stored->ics, $stored->fresh()->ics);
        $this->assertCount(3, $log->records('warning'));
        $this->assertSame(['calendar_id' => $this->calendar->id, 'remote_id_hash' => sha1('/cal/a.ics')], array_intersect_key($log->records('warning')[0]['context'], ['calendar_id' => 0, 'remote_id_hash' => 0]));
    }

    public function test_occurrences_follow_the_pulled_rule(): void
    {
        $series = function (int $count): string {
            return self::ics(self::vevent('UID:r@example.com', 'SUMMARY:Standup', 'DTSTART:20261019T080000Z', 'DTEND:20261019T081500Z', 'RRULE:FREQ=DAILY;COUNT=' . $count));
        };

        $event = $this->import('/cal/r.ics', $series(5));
        $this->assertCount(5, $this->occurrences($event));

        $this->assertCount(3, $this->occurrences($this->import('/cal/r.ics', $series(3))));
    }

    public function test_events_are_read_only_when_the_provider_cant_write_them(): void
    {
        $this->assertTrue($this->import('/cal/ro.ics', self::single('ro@example.com', 'Shared'), 1, null, null, true)->read_only);

        // A rule the provider can't write locks only whole-series changes (EventService); occurrences stay editable.
        $this->provider->writableRecurrence = false;
        $series = self::ics(self::vevent('UID:rule@example.com', 'SUMMARY:Odd', 'DTSTART:20261019T080000Z', 'RRULE:FREQ=MONTHLY;BYDAY=MO,TU;BYSETPOS=2'));
        $this->assertFalse($this->import('/cal/rule.ics', $series)->read_only);
        $this->assertFalse($this->import('/cal/single.ics', self::single('single@example.com', 'Single'))->read_only);

        // Whether the calendar can be written is checked on every request (CalendarPolicy) and never stored on its
        // events, so they need no new import once the calendar can be written again.
        $this->connection->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->calendar = $this->calendar->fresh();
        $this->assertFalse($this->import('/cal/single.ics', self::single('single@example.com', 'Single'))->read_only);
    }

    public function test_overrides_without_their_master_are_kept_read_only(): void
    {
        $event = $this->import('/cal/o.ics', self::ics(self::vevent('UID:o@example.com', 'RECURRENCE-ID:20261020T080000Z', 'SUMMARY:Invited once', 'DTSTART:20261020T100000Z', 'DTEND:20261020T110000Z')));

        $this->assertSame(['o@example.com', 'Invited once', '2026-10-20 10:00:00', true], [$event->uid, $event->summary, $event->starts_at, $event->read_only]);
    }

    public function test_a_v2_line_item_finds_the_pulled_event_by_uid(): void
    {
        $user = $this->makeUser(['time_format' => User::TIME_FORMAT_24]);
        $this->actingAs($user);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $thread = Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'created_by_user_id' => $user->id,
            'action_type'        => CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR,
            'source_via'         => Thread::PERSON_USER,
            'source_type'        => Thread::SOURCE_TYPE_WEB,
            'meta'               => ['calendar_id' => $this->calendar->id, 'event_uid' => 'legacy@example.com'],
        ]);
        $this->assertStringContainsString('the event has been deleted', LineItemText::render($thread, false));

        $this->import('/cal/legacy.ics', self::single('legacy@example.com', 'Visit'));

        $this->assertStringContainsString('Starts at 20-10-2026 10:00', LineItemText::render($thread, false));
    }
}
