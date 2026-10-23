<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Tests\Support\LogRecorder;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class EventIcsTest extends TestCase
{
    private function ams(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone('Europe/Amsterdam'));
    }

    public function test_timed_event_keeps_its_tzid_and_derives_utc_columns_across_dst(): void
    {
        $vcal = EventIcs::build(['uid' => 'u1', 'title' => 'Call', 'all_day' => false, 'start' => $this->ams('2026-10-27 10:00'), 'end' => $this->ams('2026-10-27 11:00')]);
        $ics = $vcal->serialize();

        $this->assertStringContainsString('DTSTART;TZID=Europe/Amsterdam:20261027T100000', $ics);
        $this->assertStringContainsString('PRODID:-//LJPc//FreeScout Calendar 3//EN', $ics);

        $derived = EventIcs::derive(Reader::read($ics), 'u1', 'UTC');
        $this->assertSame('2026-10-27 09:00:00', $derived['starts_at']); // CET, UTC+1 after 25 October
        $this->assertSame('2026-10-27 10:00:00', $derived['ends_at']);
        $this->assertSame('Europe/Amsterdam', $derived['timezone']);
        $this->assertFalse($derived['all_day']);
        $this->assertFalse($derived['is_recurring']);
        $this->assertSame('Call', $derived['summary']);

        $summer = EventIcs::derive(EventIcs::build(['uid' => 'u2', 'title' => 'x', 'all_day' => false, 'start' => $this->ams('2026-10-20 10:00'), 'end' => $this->ams('2026-10-20 10:30')]), 'u2', 'UTC');
        $this->assertSame('2026-10-20 08:00:00', $summer['starts_at']); // CEST, UTC+2
    }

    public function test_all_day_event_is_a_date_without_timezone(): void
    {
        $vcal = EventIcs::build(['uid' => 'd1', 'title' => 'Holiday', 'all_day' => true, 'start' => '2026-10-05', 'end' => '2026-10-07']);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261005', $vcal->serialize());

        $derived = EventIcs::derive($vcal, 'd1', 'Australia/Sydney');
        $this->assertTrue($derived['all_day']);
        $this->assertNull($derived['timezone']);
        $this->assertSame('2026-10-05 00:00:00', $derived['starts_at']);
        $this->assertSame('2026-10-07 00:00:00', $derived['ends_at']);
    }

    public function test_external_shapes_floating_utc_missing_dtend_private_and_organizer(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\n"
            . "BEGIN:VEVENT\r\nUID:f1\r\nDTSTART:20261005T090000\r\nSUMMARY:Floating\r\nCLASS:PRIVATE\r\nORGANIZER;CN=Boss:mailto:Boss@Example.com\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:z1\r\nDTSTART:20261005T090000Z\r\nDURATION:PT45M\r\nSUMMARY:Utc\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:a1\r\nDTSTART;VALUE=DATE:20261005\r\nSUMMARY:No end\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $vcal = Reader::read($ics);

        $floating = EventIcs::derive($vcal, 'f1', 'Europe/Amsterdam');
        $this->assertSame('2026-10-05 07:00:00', $floating['starts_at']);
        $this->assertSame('2026-10-05 07:00:00', $floating['ends_at']);
        $this->assertTrue($floating['is_private']);
        $this->assertSame('boss@example.com', $floating['organizer_email']);

        $utc = EventIcs::derive($vcal, 'z1', 'Europe/Amsterdam');
        $this->assertSame('UTC', $utc['timezone']);
        $this->assertSame('2026-10-05 09:45:00', $utc['ends_at']);

        $allDay = EventIcs::derive($vcal, 'a1', 'Europe/Amsterdam');
        $this->assertSame('2026-10-06 00:00:00', $allDay['ends_at']);
    }

    public function test_overrides_and_exdates_use_the_masters_timezone(): void
    {
        $vcal = EventIcs::build(['uid' => 'r1', 'title' => 'Weekly', 'all_day' => false, 'start' => $this->ams('2026-10-20 10:00'), 'end' => $this->ams('2026-10-20 11:00'), 'rrule' => 'FREQ=WEEKLY']);
        $master = EventIcs::master($vcal, 'r1');
        $tz = new DateTimeZone('Europe/Amsterdam');

        $override = EventIcs::addOverride($vcal, $master, '20261027T090000Z', $tz);
        $this->assertStringContainsString('DTEND;TZID=Europe/Amsterdam:20261027T110000', $override->serialize());
        EventIcs::apply($override, ['title' => 'Moved', 'all_day' => false, 'start' => $this->ams('2026-10-27 14:00'), 'end' => $this->ams('2026-10-27 15:00')]);
        EventIcs::addExdate($master, '20261103T090000Z', $tz);
        $ics = $vcal->serialize();

        $this->assertStringContainsString('RECURRENCE-ID;TZID=Europe/Amsterdam:20261027T100000', $ics);
        $this->assertStringContainsString('EXDATE;TZID=Europe/Amsterdam:20261103T100000', $ics);
        $this->assertNotNull(EventIcs::findOverride(Reader::read($ics), 'r1', '20261027T090000Z', $tz));

        EventIcs::removeOverride($vcal, 'r1', '20261027T090000Z', $tz);
        $this->assertNull(EventIcs::findOverride($vcal, 'r1', '20261027T090000Z', $tz));
    }

    public function test_floating_master_keeps_overrides_and_exdates_floating(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:fl\r\nDTSTART:20261020T100000\r\nDTEND:20261020T110000\r\nRRULE:FREQ=WEEKLY\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $vcal = Reader::read($ics);
        $master = EventIcs::master($vcal, 'fl');
        $tz = new DateTimeZone('Europe/Amsterdam');

        $override = EventIcs::addOverride($vcal, $master, '20261027T090000Z', $tz);
        EventIcs::addExdate($master, '20261103T090000Z', $tz);
        $out = $vcal->serialize();

        $this->assertStringContainsString('RECURRENCE-ID:20261027T100000', $out);
        $this->assertStringContainsString('EXDATE:20261103T100000', $out);
        $this->assertStringContainsString('DTSTART:20261027T100000', $override->serialize());
        $this->assertStringContainsString('DTEND:20261027T110000', $override->serialize());
        $this->assertStringNotContainsString('Z' . "\r\n", preg_replace('/^(PRODID|DTSTAMP).*$/m', '', $out));
        $this->assertNotNull(EventIcs::findOverride(Reader::read($out), 'fl', '20261027T090000Z', $tz));
    }

    public function test_all_day_overrides_and_exdates_use_date_values(): void
    {
        $vcal = EventIcs::build(['uid' => 'ad', 'title' => 'Away', 'all_day' => true, 'start' => '2026-10-05', 'end' => '2026-10-07', 'rrule' => 'FREQ=WEEKLY']);
        $master = EventIcs::master($vcal, 'ad');
        $tz = new DateTimeZone('Europe/Amsterdam');

        $override = EventIcs::addOverride($vcal, $master, '20261012', $tz);
        EventIcs::addExdate($master, '20261019', $tz);
        $o = $override->serialize();

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261012', $o);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261014', $o);
        $this->assertStringContainsString('RECURRENCE-ID;VALUE=DATE:20261012', $o);
        $this->assertStringContainsString('EXDATE;VALUE=DATE:20261019', $vcal->serialize());
    }

    public function test_drop_exceptions_and_bump_sequence(): void
    {
        $vcal = EventIcs::build(['uid' => 'dx', 'title' => 'W', 'all_day' => false, 'start' => $this->ams('2026-10-20 10:00'), 'end' => $this->ams('2026-10-20 11:00'), 'rrule' => 'FREQ=WEEKLY']);
        $master = EventIcs::master($vcal, 'dx');
        $tz = new DateTimeZone('Europe/Amsterdam');
        EventIcs::addOverride($vcal, $master, '20261027T090000Z', $tz);
        EventIcs::addExdate($master, '20261103T090000Z', $tz);

        EventIcs::dropExceptions($vcal, $master);
        $this->assertCount(1, $vcal->select('VEVENT'));
        $this->assertStringNotContainsString('EXDATE', $vcal->serialize());
        $this->assertStringNotContainsString('RECURRENCE-ID', $vcal->serialize());
        $this->assertSame('dx', (string) EventIcs::master($vcal, 'dx')->UID);

        $this->assertSame(0, (int) (string) $master->SEQUENCE);
        EventIcs::bumpSequence($master);
        $this->assertSame(1, (int) (string) $master->SEQUENCE);
        EventIcs::bumpSequence($master);
        $this->assertSame(2, (int) (string) $master->SEQUENCE);
    }

    public function test_apply_with_start_requires_all_day_and_end(): void
    {
        $vcal = EventIcs::build(['uid' => 'ap', 'title' => 'x', 'all_day' => true, 'start' => '2026-10-05', 'end' => '2026-10-06']);
        $this->expectException(\InvalidArgumentException::class);
        EventIcs::apply(EventIcs::master($vcal, 'ap'), ['start' => '2026-10-07']);
    }

    public function test_adding_the_same_exdate_twice_keeps_one(): void
    {
        $vcal = EventIcs::build(['uid' => 'dup', 'title' => 'W', 'all_day' => false, 'start' => $this->ams('2026-10-20 10:00'), 'end' => $this->ams('2026-10-20 11:00'), 'rrule' => 'FREQ=WEEKLY']);
        $master = EventIcs::master($vcal, 'dup');
        $tz = new DateTimeZone('Europe/Amsterdam');
        EventIcs::addExdate($master, '20261103T090000Z', $tz);
        EventIcs::addExdate($master, '20261103T090000Z', $tz);

        $this->assertSame(1, substr_count($vcal->serialize(), 'EXDATE'));
    }

    public function test_read_returns_form_values(): void
    {
        $vcal = EventIcs::build(['uid' => 'x', 'title' => 'T', 'all_day' => false, 'start' => $this->ams('2026-10-20 10:00'), 'end' => $this->ams('2026-10-20 11:00'), 'location' => 'Office', 'description' => "Line 1\nLine 2", 'rrule' => 'FREQ=DAILY;COUNT=3']);
        $read = EventIcs::read(EventIcs::master($vcal, 'x'), new DateTimeZone('Europe/Amsterdam'));

        $this->assertSame('Office', $read['location']);
        $this->assertSame("Line 1\nLine 2", $read['description']);
        $this->assertSame('FREQ=DAILY;COUNT=3', $read['rrule']);
        $this->assertSame('2026-10-20T10:00:00+02:00', $read['start']->format(DATE_ATOM));
    }

    private function zoned(string $uid, string $tzid, string $vtimezone = ''): VCalendar
    {
        return Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\n$vtimezone"
            . "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTART;TZID=$tzid:20261020T100000\r\nDTEND;TZID=$tzid:20261020T110000\r\nRRULE:FREQ=DAILY;COUNT=3\r\n"
            . "EXDATE;TZID=$tzid:20261021T100000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
    }

    public function test_an_unknown_tzid_is_logged_once_per_request(): void
    {
        $logs = LogRecorder::install();

        foreach (['m1', 'm2', 'm3'] as $uid) {
            EventIcs::derive($this->zoned($uid, 'Mars/Olympus'), $uid, 'Europe/Amsterdam');
        }
        EventIcs::derive($this->zoned('v1', 'Venus/Maxwell'), 'v1', 'Europe/Amsterdam');
        // Sabre knows these: a Windows name from its map, and a custom name whose VTIMEZONE says which zone it is.
        $windows = EventIcs::derive($this->zoned('w1', 'W. Europe Standard Time'), 'w1', 'UTC');
        $custom = "BEGIN:VTIMEZONE\r\nTZID:Office time\r\nX-LIC-LOCATION:Europe/Amsterdam\r\nBEGIN:STANDARD\r\nDTSTART:19701025T030000\r\n"
            . "TZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";
        EventIcs::derive($this->zoned('c1', 'Office time', $custom), 'c1', 'UTC');

        $warnings = $logs->records('warning');
        $this->assertSame(['Mars/Olympus', 'Venus/Maxwell'], array_map(function (array $record) {
            return $record['context']['tzid'];
        }, $warnings));
        $this->assertSame([], $logs->records('error'));
        $this->assertSame(['2026-10-20 08:00:00', 'Europe/Berlin'], [$windows['starts_at'], $windows['timezone']]);
    }

    public function test_zone_names_tzdata_renamed_are_read_at_their_real_offset(): void
    {
        $logs = LogRecorder::install();

        // PHP still knows these older names; sabre's own lists don't, and would read them in PHP's default zone.
        $kiev = EventIcs::derive($this->zoned('k1', 'Europe/Kiev'), 'k1', 'UTC');
        $rangoon = EventIcs::derive($this->zoned('r1', 'Asia/Rangoon'), 'r1', 'UTC');

        $this->assertSame(['2026-10-20 07:00:00', 'Europe/Kiev'], [$kiev['starts_at'], $kiev['timezone']]); // EEST, UTC+3
        $this->assertSame(['2026-10-20 03:30:00', 'Asia/Rangoon'], [$rangoon['starts_at'], $rangoon['timezone']]); // UTC+6:30
        $this->assertSame([], $logs->records());
    }
}
