<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Services\InviteMessage;
use Modules\LJPcCalendarModule\Services\InviteParser;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

/** Invites as Gmail, Outlook/Teams, iCloud and booking systems send them (research section D3). */
class InviteParserTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/invites/' . $name);
    }

    /** @return InviteMessage[] */
    private static function parse(string $name): array
    {
        return (new InviteParser())->parse(self::fixture($name));
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
    }

    public function test_a_gmail_invite_is_one_request_with_its_organizer_and_attendees(): void
    {
        $messages = self::parse('gmail-request.ics');

        $this->assertCount(1, $messages);
        $invite = $messages[0];
        $this->assertSame(['REQUEST', '7kukuqrdgsj8e2a3l4nbo1m1fs@google.com', null, false, 0, '2026-10-19 12:00:00'], [$invite->method, $invite->uid, $invite->recurrenceId, $invite->thisAndFuture, $invite->sequence, $invite->dtstamp]);
        $this->assertSame(['anna@example.com', 'Anna Example', null], [$invite->organizerEmail, $invite->organizerName, $invite->organizerAlias]);
        $this->assertSame([
            ['email' => 'anna@example.com', 'name' => 'Anna Example', 'partstat' => 'ACCEPTED', 'rsvp' => true],
            ['email' => 'support@example.org', 'name' => 'support@example.org', 'partstat' => 'NEEDS-ACTION', 'rsvp' => true],
        ], $invite->attendees);
        $this->assertSame(['Contract review', 'Online', false, null], [$invite->summary, $invite->location, $invite->allDay, $invite->rrule]);
        $this->assertSame(['2026-11-03 09:00', '2026-11-03 10:00'], [self::utc($invite->start), self::utc($invite->end)]);
        $this->assertSame('Europe/Amsterdam', $invite->start->getTimezone()->getName());
    }

    public function test_an_outlook_series_reads_its_windows_zone_and_rule(): void
    {
        $invite = self::parse('outlook-request.ics')[0];

        $this->assertSame(['REQUEST', 'Ticket sync', 'Microsoft Teams Meeting'], [$invite->method, $invite->summary, $invite->location]);
        $this->assertSame('FREQ=WEEKLY;UNTIL=20261215T090000Z;INTERVAL=1;BYDAY=TU;WKST=MO', $invite->rrule);
        $this->assertSame(['2026-11-03 09:00', '2026-11-03 09:30'], [self::utc($invite->start), self::utc($invite->end)]);
        $this->assertSame('bram@example.net', $invite->organizerEmail);

        // Outlook sometimes leaves ORGANIZER out; the card then has no one to answer.
        $this->assertNull(self::parse('outlook-no-organizer.ics')[0]->organizerEmail);
    }

    public function test_an_icloud_invite_answers_the_relay_address_and_knows_the_real_one(): void
    {
        $invite = self::parse('icloud-request.ics')[0];

        $this->assertSame('2_he4dcmbuguzdambqgi4dombugm@imip.me.com', $invite->organizerEmail);
        $this->assertSame('chris@example.com', $invite->organizerAlias);
        $this->assertSame([true, '2026-11-20', '2026-11-21', 1], [$invite->allDay, $invite->start->format('Y-m-d'), $invite->end->format('Y-m-d'), $invite->sequence]);
    }

    public function test_a_file_without_method_is_a_publish_without_organizer(): void
    {
        $invite = self::parse('publish-booking.ics')[0];

        $this->assertSame(['PUBLISH', null, []], [$invite->method, $invite->organizerEmail, $invite->attendees]);
        $this->assertSame('Car service appointment', $invite->summary);
    }

    public function test_cancellations_name_the_whole_series_or_one_occurrence(): void
    {
        $series = self::parse('cancel-series.ics')[0];
        $occurrence = self::parse('cancel-occurrence.ics')[0];

        $this->assertSame(['CANCEL', null, 1], [$series->method, $series->recurrenceId, $series->sequence]);
        $this->assertSame(['CANCEL', '20261117T090000Z', false], [$occurrence->method, $occurrence->recurrenceId, $occurrence->thisAndFuture]);
    }

    public function test_an_update_of_one_occurrence_keeps_its_key_and_range(): void
    {
        $invite = self::parse('request-occurrence.ics')[0];

        $this->assertSame(['REQUEST', '20261124T090000Z', true, 2], [$invite->method, $invite->recurrenceId, $invite->thisAndFuture, $invite->sequence]);
        $this->assertSame('2026-11-24 13:00', self::utc($invite->start));
    }

    public function test_a_reply_carries_the_answering_attendee(): void
    {
        $invite = self::parse('reply-accepted.ics')[0];

        $this->assertSame(['REPLY', 'support@example.org'], [$invite->method, $invite->organizerEmail]);
        $this->assertSame([['email' => 'dana@example.com', 'name' => 'Dana Example', 'partstat' => 'ACCEPTED', 'rsvp' => true]], $invite->attendees);
    }

    public function test_each_message_keeps_only_its_own_event_and_the_zones(): void
    {
        $ics = str_replace("END:VCALENDAR\r\n", "BEGIN:VEVENT\r\nUID:second@example.com\r\nDTSTAMP:20261019T120000Z\r\nDTSTART:20261104T090000Z\r\nDTEND:20261104T100000Z\r\nSUMMARY:Second\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", self::fixture('gmail-request.ics'));

        $messages = (new InviteParser())->parse($ics);

        $this->assertSame(['7kukuqrdgsj8e2a3l4nbo1m1fs@google.com', 'second@example.com'], [$messages[0]->uid, $messages[1]->uid]);
        $first = Reader::read($messages[0]->ics);
        $this->assertSame(['7kukuqrdgsj8e2a3l4nbo1m1fs@google.com'], array_map(function ($vevent) {
            return (string) $vevent->UID;
        }, $first->select('VEVENT')));
        $this->assertCount(1, $first->select('VTIMEZONE'));
        $this->assertSame('REQUEST', (string) $first->METHOD);
    }

    public function test_what_is_not_icalendar_is_no_invite(): void
    {
        $this->assertSame([], self::parse('not-ics.txt'));
        $this->assertSame([], (new InviteParser())->parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:todo@example.com\r\nEND:VTODO\r\nEND:VCALENDAR\r\n"));
    }
}
