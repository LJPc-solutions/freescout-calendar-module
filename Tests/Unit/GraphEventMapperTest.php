<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Modules\LJPcCalendarModule\CalendarProviders\Microsoft\GraphEventMapper;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

class GraphEventMapperTest extends TestCase
{
    private const ZONE = 'Europe/Amsterdam';

    public static function fixture(string $name, array $changes = []): array
    {
        return array_merge(json_decode((string) file_get_contents(__DIR__ . '/../fixtures/microsoft/' . $name . '.json'), true), $changes);
    }

    /** The occurrences and exceptions of the weekly series, as events/{id}/instances lists them. */
    public static function instances(): array
    {
        return self::fixture('instances')['value'];
    }

    /** The window the instances were read for. */
    private static function window(): array
    {
        return [new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2027-01-01T00:00:00Z')];
    }

    private static function vcal(array $event, array $instances = [], ?array $window = null, string $zone = self::ZONE): VCalendar
    {
        return Reader::read(GraphEventMapper::toVCalendar($event, $instances, $window, $zone));
    }

    private static function master(VCalendar $vcal): VEvent
    {
        foreach ($vcal->select('VEVENT') as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $vevent;
            }
        }
        self::fail('The VCALENDAR has no master');
    }

    /** The occurrence keys of the master's EXDATEs. */
    private static function exdateKeys(VEvent $master): array
    {
        $keys = [];
        foreach ($master->select('EXDATE') as $exdate) {
            foreach ($exdate->getDateTimes() as $dateTime) {
                $keys[] = $exdate->hasTime() ? $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') : $dateTime->format('Ymd');
            }
        }
        sort($keys);

        return $keys;
    }

    /** What sabre/vobject expands the series to, as UTC starts. */
    private static function expandedStarts(VCalendar $vcal): array
    {
        $expanded = $vcal->expand(new \DateTime('2026-10-01T00:00:00Z'), new \DateTime('2027-01-01T00:00:00Z'));
        $starts = [];
        foreach ($expanded->select('VEVENT') as $vevent) {
            $starts[] = $vevent->DTSTART->getDateTime()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
        }
        sort($starts);

        return $starts;
    }

    public function test_a_timed_event_keeps_its_zone_its_fields_and_its_attendees(): void
    {
        $vcal = self::vcal(self::fixture('single'));

        $master = self::master($vcal);
        $this->assertCount(1, $vcal->select('VEVENT'));
        $this->assertSame('040000008200E00074C5B7101A82E00800000000000000000000000000000000000000000000000000000000000001', (string) $master->UID);
        $this->assertSame(['Intake call with Example Corp', 'Room 2, Example Street 1, Amsterdam'], [(string) $master->SUMMARY, (string) $master->LOCATION]);
        // Outlook's HTML body becomes the text it shows.
        $this->assertSame("Discuss the support plan.\n\nAgenda: the agenda (https://docs.example.com/agenda) & notes", (string) $master->DESCRIPTION);
        // The Windows zone the event was made in is the calendar's own zone, so it keeps that name.
        $this->assertSame('DTSTART;TZID=Europe/Amsterdam:20261014T100000', trim($master->DTSTART->serialize()));
        $this->assertSame('DTEND;TZID=Europe/Amsterdam:20261014T113000', trim($master->DTEND->serialize()));
        $this->assertSame(['20261001T093000Z', '20261001T093000Z'], [(string) $master->{'LAST-MODIFIED'}, (string) $master->DTSTAMP]);
        $this->assertFalse(isset($master->CLASS) || isset($master->TRANSP) || isset($master->STATUS) || isset($master->RRULE));
        $this->assertSame(['Europe/Amsterdam'], array_map(function ($zone) {
            return (string) $zone->TZID;
        }, $vcal->select('VTIMEZONE')));
        $this->assertSame('ORGANIZER;CN=Support Agent:mailto:agent@example.com', trim($master->ORGANIZER->serialize()));
        $this->assertSame([
            'ATTENDEE;CN=Customer Contact;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:contact@example.com',
            'ATTENDEE;CN=Colleague;ROLE=OPT-PARTICIPANT;PARTSTAT=TENTATIVE:mailto:colleague@example.com',
            'ATTENDEE;CN=Room 2;CUTYPE=RESOURCE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:room2@example.com',
        ], array_map(function ($attendee) {
            return str_replace("\r\n ", '', trim($attendee->serialize())); // unfolded
        }, $master->select('ATTENDEE')));
        $derived = EventIcs::derive($vcal, (string) $master->UID, self::ZONE);
        $this->assertSame(['2026-10-14 08:00:00', '2026-10-14 09:30:00', 'Europe/Amsterdam', false], [$derived['starts_at'], $derived['ends_at'], $derived['timezone'], $derived['all_day']]);

        // In a calendar of another zone the Windows name becomes the zone CLDR names for it.
        $this->assertSame('DTSTART;TZID=Europe/Berlin:20261014T100000', trim(self::master(self::vcal(self::fixture('single'), [], null, 'UTC'))->DTSTART->serialize()));
    }

    public function test_a_timed_event_comes_back_with_the_same_times_and_fields(): void
    {
        $event = self::fixture('single');
        $master = self::master(self::vcal($event));

        $this->assertSame([
            'subject'    => 'Intake call with Example Corp',
            'body'       => ['contentType' => 'text', 'content' => "Discuss the support plan.\n\nAgenda: the agenda (https://docs.example.com/agenda) & notes"],
            'location'   => ['displayName' => 'Room 2, Example Street 1, Amsterdam'],
            'start'      => ['dateTime' => '2026-10-14T10:00:00', 'timeZone' => 'W. Europe Standard Time'],
            'end'        => ['dateTime' => '2026-10-14T11:30:00', 'timeZone' => 'W. Europe Standard Time'],
            'isAllDay'   => false,
            'recurrence' => null,
        ], GraphEventMapper::fromVEvent($master, true, self::ZONE));
        // An override is written without a rule, and attendees and the organizer are never written.
        $this->assertSame(['subject', 'body', 'location', 'start', 'end', 'isAllDay'], array_keys(GraphEventMapper::fromVEvent($master, false, self::ZONE)));
    }

    public function test_an_all_day_event_keeps_its_exclusive_end_date(): void
    {
        $master = self::master(self::vcal(self::fixture('all-day')));

        $this->assertSame('DTSTART;VALUE=DATE:20261019', trim($master->DTSTART->serialize()));
        $this->assertSame('DTEND;VALUE=DATE:20261022', trim($master->DTEND->serialize()));
        $this->assertSame('TRANSPARENT', (string) $master->TRANSP);
        $this->assertFalse(isset($master->DESCRIPTION) || isset($master->LOCATION));
        $this->assertSame([
            'subject'    => 'Trade fair',
            'body'       => ['contentType' => 'text', 'content' => ''],
            'location'   => ['displayName' => ''],
            'start'      => ['dateTime' => '2026-10-19T00:00:00', 'timeZone' => 'W. Europe Standard Time'],
            'end'        => ['dateTime' => '2026-10-22T00:00:00', 'timeZone' => 'W. Europe Standard Time'],
            'isAllDay'   => true,
            'recurrence' => null,
        ], GraphEventMapper::fromVEvent($master, true, self::ZONE));
    }

    public function test_an_all_day_event_rendered_in_utc_keeps_its_dates(): void
    {
        // UNVERIFIED how Graph renders an all-day event under outlook.timezone="UTC": as the dates, or as the midnights of
        // the zone it was made in. Both read as the same dates.
        $shifted = self::fixture('all-day', ['start' => ['dateTime' => '2026-10-18T22:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-21T22:00:00.0000000', 'timeZone' => 'UTC']]);
        $master = self::master(self::vcal($shifted));
        $this->assertSame(['20261019', '20261022'], [(string) $master->DTSTART, (string) $master->DTEND]);

        $newYork = self::fixture('all-day', [
            'originalStartTimeZone' => 'Eastern Standard Time', 'originalEndTimeZone' => 'Eastern Standard Time',
            'start' => ['dateTime' => '2026-10-19T04:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-22T04:00:00.0000000', 'timeZone' => 'UTC'],
        ]);
        $master = self::master(self::vcal($newYork));
        $this->assertSame(['20261019', '20261022'], [(string) $master->DTSTART, (string) $master->DTEND]);
    }

    public function test_a_series_gets_its_exceptions_and_its_missing_occurrences_across_the_dst_change(): void
    {
        $vcal = self::vcal(self::fixture('series-master'), self::instances(), self::window());

        $master = self::master($vcal);
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO;COUNT=6', (string) $master->RRULE);
        $this->assertSame('DTSTART;TZID=Europe/Amsterdam:20261005T100000', trim($master->DTSTART->serialize()));
        // 19 October is not among the instances: deleted in Outlook.
        $this->assertSame(['20261019T080000Z'], self::exdateKeys($master));
        $overrides = array_values(array_filter($vcal->select('VEVENT'), function (VEvent $vevent) {
            return isset($vevent->{'RECURRENCE-ID'});
        }));
        $this->assertCount(1, $overrides);
        [$override] = $overrides;
        // 26 October is after the change to winter time: 10:00 in Amsterdam is 09:00 UTC.
        $this->assertSame('RECURRENCE-ID;TZID=Europe/Amsterdam:20261026T100000', trim($override->{'RECURRENCE-ID'}->serialize()));
        $this->assertSame('20261026T090000Z', EventIcs::recurrenceKey($override->{'RECURRENCE-ID'}, new DateTimeZone(self::ZONE)));
        $this->assertSame((string) $master->UID, (string) $override->UID);
        $this->assertSame(['Weekly planning (moved)', 'Moved to the afternoon.', 'Room 3'], [(string) $override->SUMMARY, (string) $override->DESCRIPTION, (string) $override->LOCATION]);
        $this->assertSame('DTSTART;TZID=Europe/Amsterdam:20261026T140000', trim($override->DTSTART->serialize()));
        $this->assertSame(['2026-10-05 08:00', '2026-10-12 08:00', '2026-10-26 13:00', '2026-11-02 09:00', '2026-11-09 09:00'], self::expandedStarts($vcal));

        // Without the instances' window nothing counts as missing.
        $this->assertSame([], self::exdateKeys(self::master(self::vcal(self::fixture('series-master'), self::instances()))));
    }

    public function test_a_cancelled_instance_is_an_exdate(): void
    {
        $instances = self::instances();
        $instances[3]['isCancelled'] = true; // 2 November, cancelled by its organizer

        $vcal = self::vcal(self::fixture('series-master'), $instances, self::window());

        $this->assertSame(['20261019T080000Z', '20261102T090000Z'], self::exdateKeys(self::master($vcal)));
        $this->assertSame(['2026-10-05 08:00', '2026-10-12 08:00', '2026-10-26 13:00', '2026-11-09 09:00'], self::expandedStarts($vcal));
    }

    /** The weekly series at 10:00 New York time, with the instances Graph lists for it, in a zone Outlook calls custom. */
    private static function newYorkSeries(string $rangeZone): array
    {
        $series = self::fixture('series-master', [
            'originalStartTimeZone' => 'tzone://Microsoft/Custom', 'originalEndTimeZone' => 'tzone://Microsoft/Custom',
            'start' => ['dateTime' => '2026-10-05T14:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-10-05T15:00:00.0000000', 'timeZone' => 'UTC'],
        ]);
        $series['recurrence']['range']['recurrenceTimeZone'] = $rangeZone;
        $instances = [];
        // New York leaves summer time on 1 November, Amsterdam on 25 October.
        foreach (['2026-10-05T14', '2026-10-12T14', '2026-10-19T14', '2026-10-26T14', '2026-11-02T15', '2026-11-09T15'] as $hour) {
            $instances[] = ['id' => 'AAMkADExampleNewYork' . $hour, 'type' => 'occurrence', 'originalStart' => $hour . ':00:00Z', 'isCancelled' => false,
                'start' => ['dateTime' => $hour . ':00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => substr($hour, 0, 11) . ((int) substr($hour, 11) + 1) . ':00:00.0000000', 'timeZone' => 'UTC']];
        }

        return [$series, $instances];
    }

    public function test_a_custom_outlook_zone_falls_back_to_the_rules_zone(): void
    {
        [$series, $instances] = self::newYorkSeries('Eastern Standard Time');

        $master = self::master(self::vcal($series, $instances, self::window()));

        $this->assertSame('America/New_York', GraphEventMapper::zoneOf($series, self::ZONE));
        $this->assertSame('DTSTART;TZID=America/New_York:20261005T100000', trim($master->DTSTART->serialize()));
        $this->assertSame([], self::exdateKeys($master));
    }

    public function test_occurrences_the_rule_doesnt_produce_stop_the_deletion_inference(): void
    {
        $logs = LogRecorder::install();
        [$series, $instances] = self::newYorkSeries('tzone://Microsoft/Custom');
        unset($instances[2]); // 19 October, deleted in Outlook

        // Without any usable zone the series is read in the calendar's (Amsterdam), where 26 October is an hour later
        // than Graph's occurrence: no occurrence that Graph still has may be taken as deleted.
        $master = self::master(Reader::read(GraphEventMapper::toVCalendar($series, $instances, self::window(), self::ZONE, ['calendar_id' => 7])));

        $this->assertSame([], self::exdateKeys($master));
        $this->assertSame('The occurrences Microsoft lists for a series are not the ones its rule gives; none of them is taken as deleted', $logs->records('warning')[0]['message']);
        $this->assertSame([7, ['20261026T140000Z']], [$logs->records('warning')[0]['context']['calendar_id'], $logs->records('warning')[0]['context']['keys']]);
    }

    public function test_missing_occurrences_are_the_rules_occurrences_in_the_window_without_an_instance(): void
    {
        $master = self::master(self::vcal(self::fixture('series-master')));
        $tz = new DateTimeZone(self::ZONE);
        $present = ['20261005T080000Z', '20261012T080000Z', '20261026T090000Z', '20261102T090000Z', '20261109T090000Z'];

        $this->assertSame(['20261019T080000Z'], GraphEventMapper::missingKeys($master, $present, ...array_merge(self::window(), [$tz])));
        // Only the window counts: an occurrence that ended before it, or starts at its end, was not asked for.
        $this->assertSame(['20261012T080000Z', '20261019T080000Z'], GraphEventMapper::missingKeys($master, [], new DateTimeImmutable('2026-10-10T00:00:00Z'), new DateTimeImmutable('2026-10-26T09:00:00Z'), $tz));
        // An all-day series has date keys.
        $allDay = self::master(Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a@example.com\r\nDTSTART;VALUE=DATE:20261005\r\nDTEND;VALUE=DATE:20261006\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"));
        $this->assertSame(['20261006'], GraphEventMapper::missingKeys($allDay, ['20261005', '20261007'], ...array_merge(self::window(), [$tz])));
        // A single event has no occurrences to miss.
        $this->assertSame([], GraphEventMapper::missingKeys(self::master(self::vcal(self::fixture('single'))), [], ...array_merge(self::window(), [$tz])));
    }

    public function test_occurrence_keys_come_from_the_original_start(): void
    {
        $this->assertSame('20261026T090000Z', GraphEventMapper::occurrenceKey(['originalStart' => '2026-10-26T09:00:00Z'], false, self::ZONE));
        $this->assertSame('20261026T090000Z', GraphEventMapper::occurrenceKey(['originalStart' => '2026-10-26T09:00:00.0000000Z'], false, self::ZONE));
        // An all-day occurrence: its date, whether Graph gives the UTC midnight or the midnight of the series' zone.
        $this->assertSame('20261012', GraphEventMapper::occurrenceKey(['originalStart' => '2026-10-12T00:00:00Z'], true, 'America/New_York'));
        $this->assertSame('20261012', GraphEventMapper::occurrenceKey(['originalStart' => '2026-10-12T04:00:00Z'], true, 'America/New_York'));
        $this->assertSame('20261012', GraphEventMapper::occurrenceKey(['originalStart' => '2026-10-11T22:00:00Z'], true, self::ZONE));
        $this->expectException(InvalidArgumentException::class);
        GraphEventMapper::occurrenceKey(['start' => ['dateTime' => '2026-10-12T08:00:00', 'timeZone' => 'UTC']], false, self::ZONE);
    }

    public function test_a_relative_monthly_series_in_a_windows_zone_both_ways(): void
    {
        $master = self::master(self::vcal(self::fixture('relative-monthly')));

        $this->assertSame('DTSTART;TZID=America/New_York:20261013T090000', trim($master->DTSTART->serialize()));
        // The end date is the end of 9 March 2027 in New York, still winter time there.
        $this->assertSame('FREQ=MONTHLY;BYDAY=2TU;UNTIL=20270310T045959Z', (string) $master->RRULE);
        $json = GraphEventMapper::fromVEvent($master, true, self::ZONE);
        $this->assertSame(['dateTime' => '2026-10-13T09:00:00', 'timeZone' => 'Eastern Standard Time'], $json['start']);
        $this->assertSame([
            'pattern' => ['type' => 'relativeMonthly', 'interval' => 1, 'daysOfWeek' => ['tuesday'], 'index' => 'second'],
            'range'   => ['type' => 'endDate', 'startDate' => '2026-10-13', 'endDate' => '2027-03-09'],
        ], $json['recurrence']);
    }

    public function test_sensitivity_becomes_the_class_and_back(): void
    {
        $private = self::master(self::vcal(self::fixture('private')));
        $this->assertSame('PRIVATE', (string) $private->CLASS);
        $this->assertSame('private', GraphEventMapper::fromVEvent($private, true, self::ZONE)['sensitivity']);
        $confidential = self::master(self::vcal(self::fixture('private', ['sensitivity' => 'confidential'])));
        $this->assertSame('CONFIDENTIAL', (string) $confidential->CLASS);
        $this->assertSame('confidential', GraphEventMapper::fromVEvent($confidential, true, self::ZONE)['sensitivity']);
        // "Personal" is no reason to hide an event, and without a CLASS Graph's own sensitivity stays.
        $personal = self::master(self::vcal(self::fixture('private', ['sensitivity' => 'personal'])));
        $this->assertFalse(isset($personal->CLASS));
        $this->assertArrayNotHasKey('sensitivity', GraphEventMapper::fromVEvent($personal, true, self::ZONE));
        $personal->CLASS = 'PUBLIC';
        $this->assertSame('normal', GraphEventMapper::fromVEvent($personal, true, self::ZONE)['sensitivity']);
    }

    public function test_events_from_other_apps_are_written_with_a_zone_graph_knows(): void
    {
        $vcal = Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a@example.com\r\nDTSTART:20261016T130000Z\r\nDTEND:20261016T140000Z\r\nSUMMARY:UTC\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:b@example.com\r\nDTSTART:20261016T090000\r\nDTEND:20261016T100000\r\nSUMMARY:Floating\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:c@example.com\r\nDTSTART;TZID=Asia/Kolkata:20261016T093000\r\nDTEND;TZID=Asia/Kolkata:20261016T103000\r\nSUMMARY:India\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        [$utc, $floating, $india] = $vcal->select('VEVENT');

        $this->assertSame(['dateTime' => '2026-10-16T13:00:00', 'timeZone' => 'UTC'], GraphEventMapper::fromVEvent($utc, true, self::ZONE)['start']);
        $this->assertSame(['dateTime' => '2026-10-16T09:00:00', 'timeZone' => 'W. Europe Standard Time'], GraphEventMapper::fromVEvent($floating, true, self::ZONE)['start']);
        $this->assertSame(['dateTime' => '2026-10-16T10:30:00', 'timeZone' => 'India Standard Time'], GraphEventMapper::fromVEvent($india, true, self::ZONE)['end']);
        // A zone that is only an offset has no Windows name: the time goes in UTC.
        $offset = Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:d@example.com\r\nDTSTART;TZID=GMT+0200:20261016T093000\r\nDTEND;TZID=GMT+0200:20261016T103000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->assertSame(['dateTime' => '2026-10-16T07:30:00', 'timeZone' => 'UTC'], GraphEventMapper::fromVEvent($offset->VEVENT, true, self::ZONE)['start']);
    }

    public function test_a_text_body_stays_as_it_is(): void
    {
        $master = self::master(self::vcal(self::fixture('single', ['body' => ['contentType' => 'text', 'content' => "Line one\r\nPrice < 10 & more"]])));

        $this->assertSame("Line one\nPrice < 10 & more", (string) $master->DESCRIPTION);
    }

    public function test_an_html_body_pcre_gives_up_on_is_refused_instead_of_emptied(): void
    {
        // A comment that never closes in front of a long body: the event is skipped and its stored copy kept.
        $body = ['contentType' => 'html', 'content' => '<html><body><!-- ' . str_repeat('x', 1500000) . '<p>Agenda</p></body></html>'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The HTML body could not be read: ');
        self::vcal(self::fixture('single', ['body' => $body]));
    }

    public function test_unusable_data_is_refused(): void
    {
        $cases = [
            'no id'        => self::fixture('single', ['id' => null, 'iCalUId' => null]),
            'no start'     => self::fixture('single', ['start' => null]),
            'a bad start'  => self::fixture('single', ['start' => ['dateTime' => 'tomorrow', 'timeZone' => 'UTC']]),
            'no rule'      => self::fixture('series-master', ['recurrence' => null]),
            'an odd rule'  => self::fixture('series-master', ['recurrence' => ['pattern' => ['type' => 'hourly', 'interval' => 1], 'range' => ['type' => 'noEnd', 'startDate' => '2026-10-05']]]),
        ];
        foreach ($cases as $case => $event) {
            try {
                GraphEventMapper::toVCalendar($event, [], null, self::ZONE);
                $this->fail('Accepted ' . $case);
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_same_event_maps_to_the_same_text(): void
    {
        $this->assertSame(
            GraphEventMapper::toVCalendar(self::fixture('series-master'), self::instances(), self::window(), self::ZONE),
            GraphEventMapper::toVCalendar(self::fixture('series-master'), self::instances(), self::window(), self::ZONE)
        );
    }
}
