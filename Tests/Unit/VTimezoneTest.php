<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Services\VTimezone;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class VTimezoneTest extends TestCase
{
    private function build(string $tzid, int $from, int $to): VCalendar
    {
        $vcal = new VCalendar();
        VTimezone::addTo($vcal, $tzid, $from, $to);

        return $vcal;
    }

    private function last(VCalendar $vcal, string $type)
    {
        $parts = $vcal->VTIMEZONE->select($type);

        return end($parts);
    }

    public function test_amsterdam_ends_with_recurring_rules(): void
    {
        $vcal = $this->build('Europe/Amsterdam', 2026, 2027);

        $daylight = $this->last($vcal, 'DAYLIGHT');
        $this->assertSame('FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', (string) $daylight->RRULE);
        $this->assertSame('+0100', (string) $daylight->TZOFFSETFROM);
        $this->assertSame('+0200', (string) $daylight->TZOFFSETTO);
        $this->assertSame('20270328T020000', (string) $daylight->DTSTART);

        $standard = $this->last($vcal, 'STANDARD');
        $this->assertSame('FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', (string) $standard->RRULE);
        $this->assertSame('+0200', (string) $standard->TZOFFSETFROM);
        $this->assertSame('+0100', (string) $standard->TZOFFSETTO);

        $rules = substr_count($vcal->serialize(), 'RRULE:');
        $this->assertSame(2, $rules);
    }

    public function test_sydney_uses_first_sunday_rules(): void
    {
        $ics = $this->build('Australia/Sydney', 2026, 2027)->serialize();

        $this->assertStringContainsString('BYMONTH=4;BYDAY=1SU', $ics);
        $this->assertStringContainsString('BYMONTH=10;BYDAY=1SU', $ics);
    }

    public function test_zone_without_dst_is_a_single_standard(): void
    {
        $vcal = $this->build('Asia/Tokyo', 2026, 2027);

        $this->assertCount(1, $vcal->VTIMEZONE->select('STANDARD'));
        $this->assertCount(0, $vcal->VTIMEZONE->select('DAYLIGHT'));
        $this->assertSame('+0900', (string) $vcal->VTIMEZONE->STANDARD->TZOFFSETTO);
        $this->assertStringNotContainsString('RRULE', $vcal->serialize());
    }

    public function test_dates_after_the_last_explicit_year_resolve_to_the_right_offset(): void
    {
        $vcal = $this->build('Europe/Amsterdam', 2026, 2027);
        $vcal->add('VEVENT', ['UID' => 'x', 'DTSTART' => '20310715T100000', 'DTSTAMP' => '20260101T000000Z']);
        $vcal->VEVENT->DTSTART['TZID'] = 'Europe/Amsterdam';

        $parsed = Reader::read($vcal->serialize());
        $utc = $parsed->VEVENT->DTSTART->getDateTime()->setTimezone(new \DateTimeZone('UTC'));

        // Sabre may resolve the TZID through PHP's IANA data instead of this VTIMEZONE, so the RRULE assertions above carry the proof.
        $this->assertSame('2031-07-15 08:00', $utc->format('Y-m-d H:i'));
    }

    public function test_resolve_finds_the_zone_sabre_reads_and_null_for_anything_else(): void
    {
        $this->assertSame('Europe/Amsterdam', VTimezone::resolve('Europe/Amsterdam')->getName());
        $this->assertSame('Europe/Kiev', VTimezone::resolve('Europe/Kiev')->getName());
        $this->assertSame('Europe/Berlin', VTimezone::resolve('W. Europe Standard Time')->getName());
        $this->assertSame('+01:00', VTimezone::resolve('GMT+0100')->getName());
        // An unknown name, an offset sabre's own finder chokes on, and files the system's tzdata lists.
        foreach (['Mars/Olympus', 'GMT+9999', 'tzdata.zi', 'localtime', 'CEST'] as $tzid) {
            $this->assertNull(VTimezone::resolve($tzid), $tzid);
            $this->assertFalse(VTimezone::isNamedZone($tzid), $tzid);
        }
        $this->assertTrue(VTimezone::isNamedZone('Europe/Kiev'));
        $this->assertFalse(VTimezone::isNamedZone('GMT+0100'));
    }
}
