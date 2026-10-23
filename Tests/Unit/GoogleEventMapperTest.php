<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeZone;
use InvalidArgumentException;
use Modules\LJPcCalendarModule\CalendarProviders\Google\GoogleEventMapper;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

class GoogleEventMapperTest extends TestCase
{
    private const ZONE = 'Europe/Amsterdam';
    private const SERIES = '7r3c9u1v5n2k8m4p6q0s2t4u6v';

    public static function fixture(string $name, array $changes = []): array
    {
        return array_merge(json_decode((string) file_get_contents(__DIR__ . '/../fixtures/google/' . $name . '.json'), true), $changes);
    }

    private static function vcal(array $event, array $exceptions = [], string $zone = self::ZONE): VCalendar
    {
        return Reader::read(GoogleEventMapper::toVCalendar($event, $exceptions, $zone));
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
        $expanded = $vcal->expand(new \DateTime('2026-10-01T00:00:00Z'), new \DateTime('2026-12-01T00:00:00Z'));
        $starts = [];
        foreach ($expanded->select('VEVENT') as $vevent) {
            $starts[] = $vevent->DTSTART->getDateTime()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
        }
        sort($starts);

        return $starts;
    }

    public function test_a_timed_event_keeps_its_zone_its_fields_and_its_last_change(): void
    {
        $vcal = self::vcal(self::fixture('single-timed'));

        $master = self::master($vcal);
        $this->assertCount(1, $vcal->select('VEVENT'));
        $this->assertSame('5c1h8k3d9q2m4n6p7r0s1t2u3v@google.com', (string) $master->UID);
        $this->assertSame(['Intake call with Example Corp', 'Discuss the support plan.', 'Room 2, Example Street 1, Amsterdam'], [(string) $master->SUMMARY, (string) $master->DESCRIPTION, (string) $master->LOCATION]);
        $this->assertSame('DTSTART;TZID=Europe/Amsterdam:20261014T100000', trim($master->DTSTART->serialize()));
        $this->assertSame('DTEND;TZID=Europe/Amsterdam:20261014T113000', trim($master->DTEND->serialize()));
        $this->assertSame(['CONFIRMED', '2', '20261001T093000Z', '20261001T093000Z'], [(string) $master->STATUS, (string) $master->SEQUENCE, (string) $master->{'LAST-MODIFIED'}, (string) $master->DTSTAMP]);
        $this->assertSame('mailto:agent@example.com', (string) $master->ORGANIZER);
        $this->assertFalse(isset($master->CLASS));
        $this->assertSame(['Europe/Amsterdam'], array_map(function ($zone) {
            return (string) $zone->TZID;
        }, $vcal->select('VTIMEZONE')));
        $derived = EventIcs::derive($vcal, (string) $master->UID, 'UTC');
        $this->assertSame(['2026-10-14 08:00:00', '2026-10-14 09:30:00', 'Europe/Amsterdam', false], [$derived['starts_at'], $derived['ends_at'], $derived['timezone'], $derived['all_day']]);
    }

    public function test_a_timed_event_comes_back_with_the_same_times_zone_and_fields(): void
    {
        $event = self::fixture('single-timed');

        $json = GoogleEventMapper::fromVEvent(self::master(self::vcal($event)), true, self::ZONE);

        $this->assertSame([
            'iCalUID'     => $event['iCalUID'],
            'summary'     => $event['summary'],
            'description' => $event['description'],
            'location'    => $event['location'],
            'start'       => $event['start'],
            'end'         => $event['end'],
            'recurrence'  => null,
            'status'      => 'confirmed',
            'visibility'  => 'default',
        ], $json);
    }

    public function test_an_all_day_event_keeps_its_exclusive_end_date(): void
    {
        $event = self::fixture('all-day');

        $master = self::master(self::vcal($event));

        $this->assertSame('DTSTART;VALUE=DATE:20261019', trim($master->DTSTART->serialize()));
        $this->assertSame('DTEND;VALUE=DATE:20261022', trim($master->DTEND->serialize()));
        $this->assertSame('TRANSPARENT', (string) $master->TRANSP);
        $json = GoogleEventMapper::fromVEvent($master, true, self::ZONE);
        $this->assertSame([['date' => '2026-10-19'], ['date' => '2026-10-22'], 'Trade fair'], [$json['start'], $json['end'], $json['summary']]);
        $this->assertNull($json['description']);
        $this->assertNull($json['location']);
    }

    public function test_a_series_keeps_its_rule_and_exdates_both_ways(): void
    {
        $event = self::fixture('recurring');

        $master = self::master(self::vcal($event));

        $this->assertSame('FREQ=WEEKLY;BYDAY=MO;COUNT=6', (string) $master->RRULE);
        $this->assertSame(['20261019T080000Z'], self::exdateKeys($master));
        $json = GoogleEventMapper::fromVEvent($master, true, self::ZONE);
        $this->assertSame($event['recurrence'], $json['recurrence']);
        $this->assertSame([$event['start'], $event['end']], [$json['start'], $json['end']]);
    }

    public function test_exceptions_land_on_their_occurrence_across_the_dst_change(): void
    {
        $vcal = self::vcal(self::fixture('recurring'), [self::fixture('instance-modified'), self::fixture('instance-cancelled')]);
        $tz = new DateTimeZone(self::ZONE);
        $uid = self::SERIES . '@google.com';

        $master = self::master($vcal);
        // 12 October was cancelled in Google, 19 October was an EXDATE of the rule already.
        $this->assertSame(['20261012T080000Z', '20261019T080000Z'], self::exdateKeys($master));
        // The occurrence of 26 October (10:00 in winter time is 09:00 UTC) moved to 11:00.
        $override = EventIcs::findOverride($vcal, $uid, '20261026T090000Z', $tz);
        $this->assertNotNull($override);
        $this->assertSame('RECURRENCE-ID;TZID=Europe/Amsterdam:20261026T100000', trim($override->{'RECURRENCE-ID'}->serialize()));
        $this->assertSame(['Weekly planning (moved)', 'Meeting room 4', '20261026T110000'], [(string) $override->SUMMARY, (string) $override->LOCATION, (string) $override->DTSTART]);
        $this->assertFalse(isset($override->RRULE));
        $this->assertSame(['2026-10-05 08:00', '2026-10-26 10:00', '2026-11-02 09:00', '2026-11-09 09:00'], self::expandedStarts($vcal));
    }

    public function test_the_overrides_of_a_stored_series_come_without_a_master(): void
    {
        $vcal = Reader::read(GoogleEventMapper::overridesToVCalendar([self::fixture('instance-modified'), self::fixture('instance-cancelled')], self::SERIES . '@google.com', self::ZONE));

        $this->assertCount(1, $vcal->select('VEVENT'));
        $override = $vcal->VEVENT;
        $this->assertSame(self::SERIES . '@google.com', (string) $override->UID);
        $this->assertSame('20261026T090000Z', EventIcs::recurrenceKey($override->{'RECURRENCE-ID'}, new DateTimeZone('UTC')));
        $this->assertSame('DTSTART;TZID=Europe/Amsterdam:20261026T110000', trim($override->DTSTART->serialize()));
    }

    public function test_occurrence_keys_and_instance_ids(): void
    {
        $modified = self::fixture('instance-modified');
        $cancelled = self::fixture('instance-cancelled');

        $this->assertSame('20261026T090000Z', GoogleEventMapper::exceptionKey($modified, 'UTC'));
        $this->assertSame('20261012T080000Z', GoogleEventMapper::exceptionKey($cancelled, 'UTC'));
        // The form the API returns (UNVERIFIED: Google doesn't document it); the fixtures were written in it.
        $this->assertSame($modified['id'], GoogleEventMapper::instanceId(self::SERIES, '20261026T090000Z'));
        $this->assertSame($cancelled['id'], GoogleEventMapper::instanceId(self::SERIES, '20261012T080000Z'));
        $this->assertSame('0a2b4c6d8e0f1g3h5i7j9k1l3m_20261026', GoogleEventMapper::instanceId('0a2b4c6d8e0f1g3h5i7j9k1l3m', '20261026'));
        // All-day: the date. A time without an offset is read in its own zone, else in the zone given.
        $this->assertSame('20261026', GoogleEventMapper::exceptionKey(['originalStartTime' => ['date' => '2026-10-26']], self::ZONE));
        $this->assertSame('20261026T090000Z', GoogleEventMapper::exceptionKey(['originalStartTime' => ['dateTime' => '2026-10-26T10:00:00', 'timeZone' => 'Europe/Amsterdam']], 'UTC'));
        $this->assertSame('20261026T140000Z', GoogleEventMapper::exceptionKey(['originalStartTime' => ['dateTime' => '2026-10-26T10:00:00']], 'America/New_York'));
    }

    public function test_visibility_becomes_the_class_and_back(): void
    {
        $cases = ['private' => 'PRIVATE', 'confidential' => 'CONFIDENTIAL', 'public' => 'PUBLIC', 'default' => null];
        foreach ($cases as $visibility => $class) {
            $event = ['visibility' => $visibility] + self::fixture('private');

            $master = self::master(self::vcal($event));

            $this->assertSame($class, isset($master->CLASS) ? (string) $master->CLASS : null, $visibility);
            $this->assertSame($visibility, GoogleEventMapper::fromVEvent($master, true, self::ZONE)['visibility'], $visibility);
        }
    }

    public function test_attendees_keep_their_answers_and_are_never_written_back(): void
    {
        $master = self::master(self::vcal(self::fixture('attendees')));

        $this->assertSame('mailto:manager@example.com', (string) $master->ORGANIZER);
        $this->assertSame('Morgan Manager', (string) $master->ORGANIZER['CN']);
        $attendees = [];
        foreach ($master->select('ATTENDEE') as $attendee) {
            $attendees[(string) $attendee] = [(string) $attendee['PARTSTAT'], (string) $attendee['ROLE'], isset($attendee['CUTYPE']) ? (string) $attendee['CUTYPE'] : null];
        }
        $this->assertSame([
            'mailto:manager@example.com'                                 => ['ACCEPTED', 'REQ-PARTICIPANT', null],
            'mailto:agent@example.com'                                   => ['NEEDS-ACTION', 'REQ-PARTICIPANT', null],
            'mailto:colleague@example.com'                               => ['DECLINED', 'REQ-PARTICIPANT', null],
            'mailto:advisor@example.com'                                 => ['TENTATIVE', 'OPT-PARTICIPANT', null],
            'mailto:c_1883abcdef0123456789@resource.calendar.google.com' => ['ACCEPTED', 'REQ-PARTICIPANT', 'RESOURCE'],
        ], $attendees);
        $this->assertSame('TENTATIVE', (string) $master->STATUS);
        $this->assertSame('DTSTART:20261016T130000Z', trim($master->DTSTART->serialize()));
        $json = GoogleEventMapper::fromVEvent($master, true, self::ZONE);
        $this->assertArrayNotHasKey('attendees', $json);
        $this->assertArrayNotHasKey('organizer', $json);
        $this->assertSame([['dateTime' => '2026-10-16T13:00:00+00:00', 'timeZone' => 'UTC'], 'tentative'], [$json['start'], $json['status']]);
    }

    public function test_an_html_description_becomes_plain_text_with_its_line_breaks(): void
    {
        $master = self::master(self::vcal(self::fixture('attendees')));

        $this->assertSame("Agenda\n1. Numbers\n2. Plans\n\nNotes: review document (https://docs.example.com/review) & slides", (string) $master->DESCRIPTION);
        $quoted = self::master(self::vcal(['description' => "<p>See <a href='https://docs.example.com/x?a=1&amp;b=2'>the doc</a>&nbsp;</p><p>[FreeScout]<br>Ticket&nbsp;#7</p>"] + self::fixture('single-timed')));
        $this->assertSame("See the doc (https://docs.example.com/x?a=1&b=2)\n[FreeScout]\nTicket #7", (string) $quoted->DESCRIPTION);
        // Plain text stays exactly as it is, also with a "<" in it.
        $plain = self::master(self::vcal(['description' => "Line one\nPrice < 10 & more"] + self::fixture('single-timed')));
        $this->assertSame("Line one\nPrice < 10 & more", (string) $plain->DESCRIPTION);
    }

    public function test_a_time_with_an_offset_and_no_zone_is_written_in_the_calendars_zone(): void
    {
        $master = self::master(self::vcal(self::fixture('offset-without-timezone')));

        // 16:00 in India (+05:30) is 10:30 UTC, which is 12:30 in Amsterdam.
        $this->assertSame('DTSTART;TZID=Europe/Amsterdam:20261014T123000', trim($master->DTSTART->serialize()));
        $this->assertSame('DTEND;TZID=Europe/Amsterdam:20261014T133000', trim($master->DTEND->serialize()));
        $json = GoogleEventMapper::fromVEvent($master, true, self::ZONE);
        $this->assertSame(['dateTime' => '2026-10-14T12:30:00+02:00', 'timeZone' => 'Europe/Amsterdam'], $json['start']);
        // A calendar in UTC gets UTC times.
        $utc = self::master(self::vcal(self::fixture('offset-without-timezone'), [], 'UTC'));
        $this->assertSame('DTSTART:20261014T103000Z', trim($utc->DTSTART->serialize()));
    }

    public function test_events_from_other_apps_are_written_with_a_zone_google_knows(): void
    {
        $vcal = Reader::read(implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Example//Test//EN',
            'BEGIN:VEVENT', 'UID:floating@example.com', 'DTSTAMP:20261001T000000Z', 'DTSTART:20261005T090000', 'DURATION:PT90M',
            'RRULE:FREQ=DAILY;UNTIL=20261009T090000', 'EXDATE:20261007T090000', 'SUMMARY:Floating', 'STATUS:CANCELLED', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:utc@example.com', 'DTSTAMP:20261001T000000Z', 'DTSTART:20261005T090000Z', 'DTEND:20261005T100000Z',
            'RRULE:FREQ=WEEKLY', 'EXDATE:20261012T090000Z', 'RDATE;VALUE=DATE-TIME:20261013T090000Z', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:offset@example.com', 'DTSTAMP:20261001T000000Z', 'DTSTART;TZID=GMT+0530:20261005T090000', 'DTEND;TZID=GMT+0530:20261005T100000', 'END:VEVENT',
            'END:VCALENDAR', '',
        ]));
        [$floating, $utc, $offset] = $vcal->select('VEVENT');

        $json = GoogleEventMapper::fromVEvent($floating, true, 'America/New_York');
        $this->assertSame([['dateTime' => '2026-10-05T09:00:00-04:00', 'timeZone' => 'America/New_York'], ['dateTime' => '2026-10-05T10:30:00-04:00', 'timeZone' => 'America/New_York']], [$json['start'], $json['end']]);
        // A floating UNTIL is a local time, which Google wants in UTC when the series has a zone.
        $this->assertSame(['RRULE:FREQ=DAILY;UNTIL=20261009T130000Z', 'EXDATE;TZID=America/New_York:20261007T090000'], $json['recurrence']);
        $this->assertSame(['Floating', 'cancelled', 'default'], [$json['summary'], $json['status'], $json['visibility']]);

        $json = GoogleEventMapper::fromVEvent($utc, true, 'America/New_York');
        $this->assertSame(['dateTime' => '2026-10-05T09:00:00+00:00', 'timeZone' => 'UTC'], $json['start']);
        $this->assertSame(['RRULE:FREQ=WEEKLY', 'RDATE:20261013T090000Z', 'EXDATE:20261012T090000Z'], $json['recurrence']);
        $this->assertSame('', $json['summary']);
        $this->assertNull($json['status']);

        // An offset is no zone Google accepts: the time goes in UTC.
        $json = GoogleEventMapper::fromVEvent($offset, true, 'America/New_York');
        $this->assertSame([['dateTime' => '2026-10-05T03:30:00+00:00', 'timeZone' => 'UTC'], ['dateTime' => '2026-10-05T04:30:00+00:00', 'timeZone' => 'UTC']], [$json['start'], $json['end']]);
    }

    public function test_an_exception_from_an_earlier_form_of_the_series_is_left_out(): void
    {
        // The series became all-day: exceptions with an original start time no longer belong to it, and the other way round.
        $allDay = array_merge(self::fixture('recurring'), ['start' => ['date' => '2026-10-05'], 'end' => ['date' => '2026-10-06'], 'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=6']]);
        $vcal = self::vcal($allDay, [self::fixture('instance-modified'), self::fixture('instance-cancelled'), ['status' => 'cancelled', 'originalStartTime' => ['date' => '2026-11-02']]]);

        $this->assertCount(1, $vcal->select('VEVENT'));
        $this->assertSame(['20261102'], self::exdateKeys(self::master($vcal)));
        $timed = self::vcal(self::fixture('recurring'), [['status' => 'cancelled', 'originalStartTime' => ['date' => '2026-10-12']]]);
        $this->assertSame(['20261019T080000Z'], self::exdateKeys(self::master($timed)));
    }

    public function test_instances_that_differ_from_their_series_are_its_exceptions(): void
    {
        $master = self::fixture('recurring');
        $regular = ['id' => self::SERIES . '_20261102T090000Z', 'status' => 'confirmed', 'summary' => 'Weekly planning', 'description' => 'Plan the support week.',
            'start' => ['dateTime' => '2026-11-02T10:00:00+01:00', 'timeZone' => self::ZONE], 'end' => ['dateTime' => '2026-11-02T11:00:00+01:00', 'timeZone' => self::ZONE],
            'recurringEventId' => self::SERIES, 'originalStartTime' => ['dateTime' => '2026-11-02T10:00:00+01:00', 'timeZone' => self::ZONE],
            'attendees' => [['email' => 'colleague@example.com', 'responseStatus' => 'declined']]];

        $this->assertFalse(GoogleEventMapper::isException($regular, $master, self::ZONE));
        // Google leaves out fields with their default value, on either side.
        $this->assertFalse(GoogleEventMapper::isException(array_diff_key($regular, ['status' => 1]) + ['transparency' => 'opaque'], $master, self::ZONE));
        $this->assertTrue(GoogleEventMapper::isException(self::fixture('instance-modified'), $master, self::ZONE));
        $this->assertTrue(GoogleEventMapper::isException(self::fixture('instance-cancelled'), $master, self::ZONE));
        $this->assertTrue(GoogleEventMapper::isException(['summary' => 'Planning (agenda changed)'] + $regular, $master, self::ZONE));
        $this->assertTrue(GoogleEventMapper::isException(['end' => ['dateTime' => '2026-11-02T11:30:00+01:00', 'timeZone' => self::ZONE]] + $regular, $master, self::ZONE));

        $allDay = array_merge(self::fixture('all-day'), ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3']]);
        $occurrence = ['start' => ['date' => '2026-10-26'], 'end' => ['date' => '2026-10-29'], 'originalStartTime' => ['date' => '2026-10-26']] + $allDay;
        $this->assertFalse(GoogleEventMapper::isException($occurrence, $allDay, self::ZONE));
        $this->assertTrue(GoogleEventMapper::isException(['end' => ['date' => '2026-10-28']] + $occurrence, $allDay, self::ZONE));
    }

    public function test_an_instance_differs_by_each_field_freescout_shows(): void
    {
        $master = self::fixture('recurring');
        $regular = ['status' => 'confirmed', 'summary' => 'Weekly planning', 'description' => 'Plan the support week.',
            'start' => ['dateTime' => '2026-11-02T10:00:00+01:00', 'timeZone' => self::ZONE], 'end' => ['dateTime' => '2026-11-02T11:00:00+01:00', 'timeZone' => self::ZONE],
            'originalStartTime' => ['dateTime' => '2026-11-02T10:00:00+01:00', 'timeZone' => self::ZONE]];
        $changes = [
            'description'  => ['description' => 'Bring the numbers.'],
            'location'     => ['location' => 'Meeting room 4'],
            'status'       => ['status' => 'tentative'],
            'visibility'   => ['visibility' => 'private'],
            'transparency' => ['transparency' => 'transparent'],
            'start'        => ['start' => ['dateTime' => '2026-11-02T10:30:00+01:00', 'timeZone' => self::ZONE]],
        ];
        foreach ($changes as $field => $change) {
            $this->assertTrue(GoogleEventMapper::isException($change + $regular, $master, self::ZONE), $field);
        }

        // A night that spans the end of DST keeps its wall-clock end, as FreeScout's own expansion does: 22:00 CEST to
        // 08:00 CET is eleven hours, and still the series' occurrence.
        $night = self::fixture('recurring', []);
        $night['start'] = ['dateTime' => '2026-10-03T22:00:00+02:00', 'timeZone' => self::ZONE];
        $night['end'] = ['dateTime' => '2026-10-04T08:00:00+02:00', 'timeZone' => self::ZONE];
        $night['recurrence'] = ['RRULE:FREQ=WEEKLY;BYDAY=SA'];
        $shift = ['summary' => 'Weekly planning', 'description' => 'Plan the support week.',
            'start' => ['dateTime' => '2026-10-24T22:00:00+02:00', 'timeZone' => self::ZONE], 'end' => ['dateTime' => '2026-10-25T08:00:00+01:00', 'timeZone' => self::ZONE],
            'originalStartTime' => ['dateTime' => '2026-10-24T22:00:00+02:00', 'timeZone' => self::ZONE]];
        $this->assertFalse(GoogleEventMapper::isException($shift, $night, self::ZONE));
        $stored = Reader::read(GoogleEventMapper::toVCalendar($night, [], self::ZONE));
        $this->assertTrue(GoogleEventMapper::sameOccurrence($shift, $stored, self::SERIES . '@google.com', '20261024T200000Z', self::ZONE));
        $expanded = $stored->expand(new \DateTime('2026-10-24T00:00:00Z'), new \DateTime('2026-10-26T00:00:00Z'));
        $this->assertSame('20261025T070000Z', (string) $expanded->VEVENT->DTEND);
    }

    public function test_an_occurrence_is_compared_with_what_freescout_stored(): void
    {
        $stored = self::vcal(self::fixture('recurring'), [self::fixture('instance-modified')]);
        $uid = self::SERIES . '@google.com';
        $regular = ['summary' => 'Weekly planning', 'description' => 'Changed in Google', 'status' => 'confirmed',
            'start' => ['dateTime' => '2026-11-02T10:00:00+01:00', 'timeZone' => self::ZONE], 'end' => ['dateTime' => '2026-11-02T11:00:00+01:00', 'timeZone' => self::ZONE]];

        // The override FreeScout stored, and the series at the time of a regular occurrence. The description doesn't count.
        $this->assertTrue(GoogleEventMapper::sameOccurrence(self::fixture('instance-modified'), $stored, $uid, '20261026T090000Z', self::ZONE));
        $this->assertTrue(GoogleEventMapper::sameOccurrence($regular, $stored, $uid, '20261102T090000Z', self::ZONE));
        // Changed in Google since: moved, renamed, another place.
        $this->assertFalse(GoogleEventMapper::sameOccurrence(['start' => ['dateTime' => '2026-10-26T15:00:00+01:00', 'timeZone' => self::ZONE]] + self::fixture('instance-modified'), $stored, $uid, '20261026T090000Z', self::ZONE));
        $this->assertFalse(GoogleEventMapper::sameOccurrence(['summary' => 'Planning with Example Corp'] + $regular, $stored, $uid, '20261102T090000Z', self::ZONE));
        $this->assertFalse(GoogleEventMapper::sameOccurrence(['location' => 'Meeting room 4'] + $regular, $stored, $uid, '20261102T090000Z', self::ZONE));
        $this->assertFalse(GoogleEventMapper::sameOccurrence(['status' => 'tentative'] + $regular, $stored, $uid, '20261102T090000Z', self::ZONE));
        // FreeScout has an override, Google the regular occurrence (someone reset it).
        $this->assertFalse(GoogleEventMapper::sameOccurrence(['start' => $regular['start'], 'end' => $regular['end'], 'summary' => 'Weekly planning', 'location' => null] + self::fixture('instance-modified'), $stored, $uid, '20261026T090000Z', self::ZONE));

        // An all-day series compares dates.
        $days = Reader::read(GoogleEventMapper::toVCalendar(self::fixture('all-day', ['recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=3']]), [], self::ZONE));
        $occurrence = ['summary' => 'Trade fair', 'status' => 'confirmed', 'start' => ['date' => '2026-10-26'], 'end' => ['date' => '2026-10-29']];
        $this->assertTrue(GoogleEventMapper::sameOccurrence($occurrence, $days, '0a2b4c6d8e0f1g3h5i7j9k1l3m@google.com', '20261026', self::ZONE));
        $this->assertFalse(GoogleEventMapper::sameOccurrence(['end' => ['date' => '2026-10-28']] + $occurrence, $days, '0a2b4c6d8e0f1g3h5i7j9k1l3m@google.com', '20261026', self::ZONE));
    }

    public function test_an_override_is_written_without_a_rule_or_uid(): void
    {
        $vcal = self::vcal(self::fixture('recurring'), [self::fixture('instance-modified')]);
        $override = EventIcs::findOverride($vcal, self::SERIES . '@google.com', '20261026T090000Z', new DateTimeZone(self::ZONE));

        $json = GoogleEventMapper::fromVEvent($override, false, self::ZONE);

        $this->assertSame(['summary', 'description', 'location', 'start', 'end', 'status', 'visibility'], array_keys($json));
        $this->assertSame(['dateTime' => '2026-10-26T11:00:00+01:00', 'timeZone' => 'Europe/Amsterdam'], $json['start']);
    }

    public function test_unusable_data_is_refused_and_foreign_recurrence_lines_are_ignored(): void
    {
        foreach ([['start' => null], ['start' => ['dateTime' => 'next tuesday']], ['iCalUID' => '', 'id' => '']] as $broken) {
            try {
                GoogleEventMapper::toVCalendar(array_merge(self::fixture('single-timed'), $broken), [], self::ZONE);
                $this->fail('Mapped an unusable event: ' . json_encode($broken));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $event = ['recurrence' => ["RRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:injected", 'SUMMARY:Injected', 'RRULE:FREQ=DAILY;COUNT=3']] + self::fixture('single-timed');
        $vcal = self::vcal($event);
        $this->assertCount(1, $vcal->select('VEVENT'));
        $this->assertSame(['FREQ=DAILY;COUNT=3', 'Intake call with Example Corp'], [(string) $vcal->VEVENT->RRULE, (string) $vcal->VEVENT->SUMMARY]);
    }

    public function test_the_same_event_maps_to_the_same_text(): void
    {
        $event = self::fixture('recurring');
        $exceptions = [self::fixture('instance-modified'), self::fixture('instance-cancelled')];

        $this->assertSame(GoogleEventMapper::toVCalendar($event, $exceptions, self::ZONE), GoogleEventMapper::toVCalendar($event, $exceptions, self::ZONE));
        $this->assertStringContainsString('BEGIN:VTIMEZONE', GoogleEventMapper::toVCalendar($event, $exceptions, self::ZONE));
    }
}
