<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Jobs\SendReminder;
use Modules\LJPcCalendarModule\Mail\ReminderMail;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\ReminderService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class ReminderTest extends TestCase
{
    private User $agent;
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->agent = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $this->calendar = $this->calendar();
    }

    private function calendar(array $attributes = []): Calendar
    {
        $calendar = Calendar::create($attributes + ['name' => 'Team ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'timezone' => 'Europe/Amsterdam']);
        $this->allow($calendar, $this->agent);

        return $calendar;
    }

    private function allow(Calendar $calendar, User $user): void
    {
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true]);
    }

    private function event(array $input, ?Conversation $conversation = null, ?Calendar $calendar = null): CalendarEvent
    {
        return app(EventService::class)->create($calendar ?? $this->calendar, $this->agent, $input + ['title' => 'Contract review'], $conversation);
    }

    /** Scans at a UTC time and returns the reminders queued. */
    private static function scan(string $utc): int
    {
        return app(ReminderService::class)->scan(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
    }

    /** @return int[] the users of the reminders queued so far */
    private static function queuedFor(): array
    {
        return Queue::pushed(SendReminder::class)->map(function (SendReminder $job) {
            return $job->userId;
        })->values()->all();
    }

    public function test_mails_the_author_once_when_the_reminder_time_passes(): void
    {
        // 10:00 in Amsterdam is 09:00 UTC; 30 minutes before is 08:30 UTC.
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 30]);

        $this->assertSame(0, self::scan('2026-11-03 08:00:00'), 'the first scan only starts the clock');
        $this->assertSame(0, self::scan('2026-11-03 08:29:00'));
        $this->assertSame(1, self::scan('2026-11-03 08:30:00'));
        $this->assertSame(0, self::scan('2026-11-03 08:31:00'));
        Queue::assertPushedOn('emails', SendReminder::class);
        $this->assertSame([$this->agent->id], self::queuedFor());
        $job = Queue::pushed(SendReminder::class)->first();
        $this->assertSame('2026-11-03 09:00:00', $job->occurrence['starts_at']);
    }

    public function test_a_scan_that_overlaps_another_mails_nobody_twice(): void
    {
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 30]);
        self::scan('2026-11-03 08:00:00');
        $this->assertSame(1, self::scan('2026-11-03 08:31:00'));

        // A second run that still read the old scan time finds the reminder logged.
        \App\Option::set(ReminderService::OPTION, ['scanned_at' => '2026-11-03 08:00:00']);
        $this->assertSame(0, self::scan('2026-11-03 08:31:30'));
        $this->assertCount(1, self::queuedFor());
    }

    public function test_follows_the_calendar_default_unless_the_event_has_its_own_or_none(): void
    {
        $this->calendar->update(['default_reminder_minutes' => 60]);
        $this->event(['start' => '2026-11-03T10:00']);
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => -1, 'title' => 'No reminder']);
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 15, 'title' => 'Own reminder']);
        self::scan('2026-11-03 07:00:00');

        $this->assertSame(1, self::scan('2026-11-03 08:00:00'), 'the default: an hour before');
        $this->assertSame(0, self::scan('2026-11-03 08:44:00'));
        $this->assertSame(1, self::scan('2026-11-03 08:45:00'), 'its own: 15 minutes before');
        $this->assertSame(0, self::scan('2026-11-03 08:59:00'));
    }

    public function test_also_mails_the_assignee_of_the_linked_conversation_while_they_can_view_the_calendar(): void
    {
        $colleague = $this->makeUser();
        $this->allow($this->calendar, $colleague);
        $mailbox = $this->makeMailbox([$this->agent->id, $colleague->id]);
        $assigned = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE, 'user_id' => $colleague->id]);
        $outsider = $this->makeUser();
        $elsewhere = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE, 'user_id' => $outsider->id]);
        $own = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE, 'user_id' => $this->agent->id]);
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 30], $assigned);
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 30, 'title' => 'Outsider'], $elsewhere);
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 30, 'title' => 'Own'], $own);
        self::scan('2026-11-03 08:00:00');

        $this->assertSame(4, self::scan('2026-11-03 08:30:00'));
        $queued = self::queuedFor();
        sort($queued);
        // The author three times; the colleague who can view the calendar once; the outsider, who can't, never.
        $this->assertSame([$this->agent->id, $this->agent->id, $this->agent->id, $colleague->id], $queued);
    }

    public function test_nobody_gets_one_who_left_or_lost_access(): void
    {
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 30]);
        $this->event(['start' => '2026-11-03T11:00', 'reminder_minutes' => 30, 'title' => 'Later']);
        self::scan('2026-11-03 08:00:00');
        CalendarPermission::where('calendar_id', $this->calendar->id)->delete();
        $this->assertSame(0, self::scan('2026-11-03 08:30:00'));

        $this->allow($this->calendar, $this->agent);
        $this->agent->status = User::STATUS_DISABLED;
        $this->agent->save();
        $this->assertSame(0, self::scan('2026-11-03 09:30:00'));
    }

    public function test_an_all_day_event_reminds_at_nine_in_the_calendar_zone(): void
    {
        // Amsterdam is UTC+1 in November: 09:00 there is 08:00 UTC.
        $this->event(['all_day' => true, 'start' => '2026-11-03', 'reminder_minutes' => 1440, 'title' => 'Day before']);
        $this->event(['all_day' => true, 'start' => '2026-11-03', 'reminder_minutes' => 30, 'title' => 'On the day']);
        self::scan('2026-11-02 07:00:00');

        $this->assertSame(0, self::scan('2026-11-02 07:59:00'));
        $this->assertSame(1, self::scan('2026-11-02 08:00:00'));
        $this->assertSame(0, self::scan('2026-11-03 07:59:00'));
        $this->assertSame(1, self::scan('2026-11-03 08:00:00'));
    }

    public function test_after_downtime_only_occurrences_that_have_not_started_get_one(): void
    {
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 30, 'title' => 'Missed']);
        $this->event(['start' => '2026-11-03T15:00', 'reminder_minutes' => 10080, 'title' => 'Next week']);
        self::scan('2026-10-20 08:00:00');

        $this->assertSame(1, self::scan('2026-11-03 10:00:00'));
        $this->assertSame('2026-11-03 14:00:00', Queue::pushed(SendReminder::class)->first()->occurrence['starts_at']);
    }

    public function test_every_occurrence_of_a_series_reminds_and_declined_events_never(): void
    {
        $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 30, 'recurrence' => ['freq' => 'daily', 'count' => 3]]);
        $declined = $this->event(['start' => '2026-11-03T12:00', 'reminder_minutes' => 30, 'title' => 'Declined']);
        $declined->invite_state = 'declined';
        $declined->save();
        self::scan('2026-11-03 08:00:00');

        $this->assertSame(1, self::scan('2026-11-03 08:30:00'));
        $this->assertSame(0, self::scan('2026-11-03 10:30:00'), 'the declined event');
        $this->assertSame(1, self::scan('2026-11-04 08:30:00'));
        $job = Queue::pushed(SendReminder::class)->last();
        $this->assertSame('20261104T090000Z', $job->occurrence['recurrence_id']);
    }

    public function test_calendar_sync_scans_before_it_syncs(): void
    {
        // Typed to the minute, so the reminder time falls one to two minutes ago, after the last scan.
        $this->event(['start' => (new DateTimeImmutable('+29 minutes', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d\TH:i'), 'reminder_minutes' => 30]);
        \App\Option::set(ReminderService::OPTION, ['scanned_at' => gmdate('Y-m-d H:i:s', time() - 300)]);

        Artisan::call('calendar:sync');
        $this->assertStringContainsString('Queued 1 reminders.', Artisan::output());
    }

    public function test_log_rows_of_past_occurrences_are_removed(): void
    {
        $event = $this->event(['start' => '2026-11-03T10:00', 'reminder_minutes' => 30]);
        self::scan('2026-11-03 08:00:00');
        self::scan('2026-11-03 08:30:00');
        $this->assertSame(1, DB::table('calendar_reminder_log')->where('event_id', $event->id)->count());

        self::scan('2026-11-05 09:01:00');
        $this->assertSame(0, DB::table('calendar_reminder_log')->where('event_id', $event->id)->count());
    }

    public function test_the_mail_is_in_the_agents_zone_clock_and_language(): void
    {
        // Core swaps the Mail facade when it applies a changed mail config, which would replace the fake.
        \MailHelper::setSystemMailDriver();
        Mail::fake();
        $this->agent->timezone = 'America/New_York';
        $this->agent->time_format = User::TIME_FORMAT_12;
        $this->agent->locale = 'nl';
        $this->agent->save();
        $mailbox = $this->makeMailbox([$this->agent->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE, 'user_id' => $this->agent->id, 'subject' => 'Contract']);
        $event = $this->event(['start' => '2026-11-03T10:00', 'end' => '2026-11-03T11:30', 'reminder_minutes' => 30, 'location' => 'Room 4', 'title' => "Contract\nreview"], $conversation);
        self::scan('2026-11-03 13:00:00');
        self::scan('2026-11-03 14:30:00');

        Queue::pushed(SendReminder::class)->first()->handle();

        $this->assertSame('nl', app()->getLocale());
        app()->setLocale('en');
        Mail::assertSent(ReminderMail::class, function (ReminderMail $mail) use ($event, $conversation) {
            $mail->build();
            $html = view($mail->view, $mail->viewData)->render(); // Mailable::render() needs the real mailer
            // 10:00 in the agent's own New York zone (UTC-5 in November), on their 12-hour clock.
            $this->assertSame('Reminder: Contract review, 03-11-2026, 10:00am – 11:30am', $mail->subject);
            $this->assertStringContainsString('Where: Room 4', $html);
            $this->assertStringContainsString(e(CalendarEvent::permalink($event->id)), $html);
            $this->assertStringContainsString(e($conversation->url()), $html);

            return $mail->hasTo($this->agent->email);
        });
    }
}
