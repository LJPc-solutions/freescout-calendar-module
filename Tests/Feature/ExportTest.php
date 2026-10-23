<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Illuminate\Support\Facades\Log;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\ReadableBlock;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

class ExportTest extends TestCase
{
    private Calendar $calendar;
    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->calendar = Calendar::create([
            'name'     => 'Export ' . uniqid(),
            'provider' => Calendar::PROVIDER_LOCAL,
            'fields'   => [['id' => 5, 'name' => 'Customer', 'type' => 'text', 'required' => false, 'options' => []]],
        ]);
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $this->event = app(EventService::class)->create($this->calendar, $user, [
            'title' => 'Call', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00',
            'description' => 'Bring the contract', 'location' => 'Office', 'field_values' => ['5' => 'Acme'],
            'recurrence' => ['freq' => 'WEEKLY', 'count' => 4],
        ], $conversation);
    }

    private function url(string $token): string
    {
        return '/calendar/' . $this->calendar->id . '/export/' . $token . '.ics';
    }

    public function test_full_export_is_valid_icalendar_with_timezone_and_readable_block(): void
    {
        $response = $this->get($this->url($this->calendar->export_token));

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'));
        $ics = $response->getContent();
        $parsed = Reader::read($ics);
        $this->assertCount(1, $parsed->select('VEVENT'));
        $this->assertSame('Europe/Amsterdam', (string) $parsed->VTIMEZONE->TZID);
        $description = (string) $parsed->VEVENT->DESCRIPTION;
        $this->assertStringContainsString("Bring the contract\n\n[FreeScout]\nCustomer: Acme\nTicket #", $description);
        $this->assertSame('Call', (string) $parsed->VEVENT->SUMMARY);
    }

    public function test_busy_export_hides_everything_but_times(): void
    {
        $parsed = Reader::read($this->get($this->url($this->calendar->export_busy_token))->getContent());

        $this->assertSame('Busy', (string) $parsed->VEVENT->SUMMARY);
        $this->assertFalse(isset($parsed->VEVENT->DESCRIPTION));
        $this->assertFalse(isset($parsed->VEVENT->LOCATION));
        $this->assertTrue(isset($parsed->VEVENT->RRULE));
    }

    public function test_wrong_tokens_and_disabled_calendars_are_not_found(): void
    {
        $this->get($this->url(str_repeat('a', 40)))->assertStatus(404);
        $this->get('/calendar/' . $this->calendar->id . '/export/' . md5((string) $this->calendar->id) . '.ics')->assertStatus(404);

        $this->calendar->update(['enabled' => false]);
        $this->get($this->url($this->calendar->export_token))->assertStatus(404);
    }

    public function test_export_route_is_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('ljpccalendarmodule.export');

        $this->assertContains('throttle:60,1', $route->middleware());
        $this->assertNotContains('web', $route->middleware());
    }

    private function captureLogs(): \ArrayObject
    {
        $logged = new \ArrayObject();
        Log::listen(function ($event) use ($logged) {
            $logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });

        return $logged;
    }

    private function setIcs(CalendarEvent $event, string $ics): void
    {
        $event->update(['ics' => $ics]);
    }

    private function leakyIcs(string $uid, string $extraMaster = ''): string
    {
        $common = "ATTENDEE;CN=Bob:mailto:bob@example.com\r\nORGANIZER:mailto:boss@example.com\r\nURL:https://example.com/x\r\n"
            . "ATTACH:https://example.com/file.pdf\r\nCLASS:PRIVATE\r\nX-SECRET:1\r\nDESCRIPTION:Secret notes\r\nLOCATION:Secret place\r\n"
            . "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Alarm\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\n";

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;TZID=Europe/Amsterdam:20261020T100000\r\nDTEND;TZID=Europe/Amsterdam:20261020T110000\r\n"
            . "RRULE:FREQ=WEEKLY;COUNT=4\r\nSUMMARY:Top secret\r\n$extraMaster$common"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nRECURRENCE-ID;TZID=Europe/Amsterdam:20261027T100000\r\nDTSTART;TZID=Europe/Amsterdam:20261027T140000\r\nDTEND;TZID=Europe/Amsterdam:20261027T150000\r\n"
            . "SUMMARY:Top secret override\r\n$common"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function test_busy_export_leaks_nothing_from_master_or_override(): void
    {
        $this->setIcs($this->event, $this->leakyIcs($this->event->uid));

        $ics = $this->get($this->url($this->calendar->export_busy_token))->getContent();
        $parsed = Reader::read($ics);
        $events = $parsed->select('VEVENT');

        $this->assertCount(2, $events);
        foreach (['ATTENDEE', 'ORGANIZER', 'URL:', 'ATTACH', 'CLASS', 'X-SECRET', 'DESCRIPTION', 'LOCATION', 'VALARM', 'Top secret', 'Secret', 'bob@example.com'] as $needle) {
            $this->assertStringNotContainsString($needle, $ics, $needle);
        }
        foreach ($events as $vevent) {
            $this->assertSame('Busy', (string) $vevent->SUMMARY);
        }
    }

    public function test_busy_export_keeps_transparent_and_defaults_to_opaque(): void
    {
        $this->setIcs($this->event, $this->leakyIcs($this->event->uid, "TRANSP:TRANSPARENT\r\n"));
        $events = Reader::read($this->get($this->url($this->calendar->export_busy_token))->getContent())->select('VEVENT');

        $this->assertSame('TRANSPARENT', (string) $events[0]->TRANSP);
        $this->assertSame('OPAQUE', (string) $events[1]->TRANSP);
    }

    public function test_timezones_come_from_the_ics_and_precede_the_events(): void
    {
        $this->setIcs($this->event, str_replace('DTSTART;TZID=Europe/Amsterdam:20261020T100000', 'DTSTART;TZID=America/New_York:20261020T100000', $this->leakyIcs($this->event->uid)));
        $ics = $this->get($this->url($this->calendar->export_token))->getContent();

        $this->assertSame(1, substr_count($ics, "TZID:America/New_York\r\n"));
        $this->assertSame(1, substr_count($ics, "TZID:Europe/Amsterdam\r\n"));
        $this->assertLessThan(strpos($ics, 'BEGIN:VEVENT'), strpos($ics, 'BEGIN:VTIMEZONE'));
        $this->assertLessThan(strpos($ics, 'BEGIN:VEVENT'), strrpos($ics, 'BEGIN:VTIMEZONE'));
    }

    public function test_unknown_tzid_is_skipped_without_failing_the_feed(): void
    {
        $logged = $this->captureLogs();
        $this->setIcs($this->event, str_replace('DTSTART;TZID=Europe/Amsterdam:20261020T100000', 'DTSTART;TZID=Mars/Olympus:20261020T100000', $this->leakyIcs($this->event->uid)));

        $response = $this->get($this->url($this->calendar->export_token));

        $response->assertStatus(200);
        $this->assertStringNotContainsString('TZID:Mars/Olympus', $response->getContent());
        $this->assertSame(['warning'], array_column($logged->getArrayCopy(), 'level'));
    }

    public function test_a_windows_tzid_gets_its_vtimezone_without_a_warning(): void
    {
        $logged = $this->captureLogs();
        $this->setIcs($this->event, str_replace(';TZID=Europe/Amsterdam:', ';TZID=W. Europe Standard Time:', $this->leakyIcs($this->event->uid)));

        $parsed = Reader::read($this->get($this->url($this->calendar->export_token))->getContent());

        $this->assertSame('W. Europe Standard Time', (string) $parsed->VTIMEZONE->TZID);
        $this->assertSame('+0200', (string) $parsed->VTIMEZONE->DAYLIGHT->TZOFFSETTO);
        $this->assertSame([], $logged->getArrayCopy());
    }

    public function test_renamed_and_fixed_offset_tzids_get_a_vtimezone(): void
    {
        $logged = $this->captureLogs();
        $cases = ['Europe/Kiev' => ['DAYLIGHT', '+0300'], 'GMT+0100' => ['STANDARD', '+0100']];

        foreach ($cases as $tzid => [$part, $offset]) {
            $this->setIcs($this->event, str_replace(';TZID=Europe/Amsterdam:', ';TZID=' . $tzid . ':', $this->leakyIcs($this->event->uid)));

            $response = $this->get($this->url($this->calendar->export_token));

            $response->assertStatus(200);
            $parsed = Reader::read($response->getContent());
            $this->assertSame($tzid, (string) $parsed->VTIMEZONE->TZID);
            $this->assertSame($offset, (string) $parsed->VTIMEZONE->{$part}->TZOFFSETTO, $tzid);
        }
        $this->assertSame([], $logged->getArrayCopy());
    }

    public function test_a_tzid_that_only_its_own_vtimezone_explains_gets_a_vtimezone(): void
    {
        $logged = $this->captureLogs();
        $vtimezone = "BEGIN:VTIMEZONE\r\nTZID:Office time\r\nX-LIC-LOCATION:Europe/Amsterdam\r\nBEGIN:STANDARD\r\nDTSTART:19701025T030000\r\n"
            . "TZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";
        $ics = str_replace(';TZID=Europe/Amsterdam:', ';TZID=Office time:', $this->leakyIcs($this->event->uid));
        $this->setIcs($this->event, str_replace("PRODID:-//Test//EN\r\n", "PRODID:-//Test//EN\r\n" . $vtimezone, $ics));

        $parsed = Reader::read($this->get($this->url($this->calendar->export_token))->getContent());

        // Rebuilt from the zone it names, so it carries Amsterdam's summer time too.
        $this->assertSame('Office time', (string) $parsed->VTIMEZONE->TZID);
        $this->assertSame('+0200', (string) $parsed->VTIMEZONE->DAYLIGHT->TZOFFSETTO);
        $this->assertSame([], $logged->getArrayCopy());
    }

    public function test_corrupt_event_is_skipped_and_valid_one_stays(): void
    {
        $logged = $this->captureLogs();
        $broken = $this->event->replicate(['uid_hash']);
        $broken->uid = 'broken-' . uniqid();
        $broken->ics = 'this is not an ics';
        $broken->save();

        $response = $this->get($this->url($this->calendar->export_token));

        $response->assertStatus(200);
        $this->assertStringContainsString('SUMMARY:Call', $response->getContent());
        $this->assertStringNotContainsString('broken-', $response->getContent());
        $this->assertSame(['error'], array_column($logged->getArrayCopy(), 'level'));
    }

    public function test_null_busy_token_never_matches_and_full_token_still_works(): void
    {
        \DB::table('calendars')->where('id', $this->calendar->id)->update(['export_busy_token' => null]);

        $this->get($this->url(str_repeat('a', 40)))->assertStatus(404);
        $this->get($this->url($this->calendar->export_token))->assertStatus(200);
    }

    public function test_sixty_first_request_in_a_minute_is_throttled(): void
    {
        $url = $this->url($this->calendar->export_token);
        for ($i = 0; $i < 60; $i++) {
            $this->get($url)->assertStatus(200);
        }
        $this->get($url)->assertStatus(429);
    }

    public function test_readable_block_is_replaced_not_duplicated(): void
    {
        $once = ReadableBlock::append('Notes', $this->event);
        $twice = ReadableBlock::append($once, $this->event);

        $this->assertSame($once, $twice);
        $this->assertSame('Notes', ReadableBlock::strip($twice));
        $this->assertSame("Keep [FreeScout] inline", ReadableBlock::strip("Keep [FreeScout] inline"));
    }
}
