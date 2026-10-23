<?php

namespace Modules\LJPcCalendarModule\Mail;

use App\Conversation;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Mail\Mailable;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\TimeInput;
use Sabre\VObject\Reader;

/** The reminder of one occurrence, in the agent's zone, clock and language (SendReminder sets the language). */
final class ReminderMail extends Mailable
{
    public User $user;

    public CalendarEvent $event;

    public array $occurrence;

    public function __construct(User $user, CalendarEvent $event, array $occurrence)
    {
        $this->user = $user;
        $this->event = $event;
        $this->occurrence = $occurrence;
    }

    public function build()
    {
        \MailHelper::prepareMailable($this);
        $occurrence = $this->occurrence;
        $tz = new DateTimeZone(TimeInput::userTimezone($this->user));
        $utc = new DateTimeZone('UTC');
        $when = $occurrence['all_day']
            ? TimeInput::range(new DateTimeImmutable(substr($occurrence['starts_at'], 0, 10)), new DateTimeImmutable(substr($occurrence['ends_at'], 0, 10)), true, $tz, $this->user)
            : TimeInput::range(new DateTimeImmutable($occurrence['starts_at'], $utc), new DateTimeImmutable($occurrence['ends_at'], $utc), false, $tz, $this->user);
        $title = (string) ($occurrence['summary'] ?? '') !== '' ? $occurrence['summary'] : (string) $this->event->summary;
        $title = $title !== '' ? $title : __('(no title)');
        $conversation = $this->event->conversation_id ? Conversation::find($this->event->conversation_id) : null;

        // The title is the sender's text, which may hold line breaks; a subject is one line.
        return $this->subject(trim((string) preg_replace('/\s+/u', ' ', __('Reminder: :title, :when', ['title' => $title, 'when' => $when]))))
            ->view('calendar::emails/reminder', [
                'title'        => $title,
                'when'         => $when,
                'calendar'     => $this->event->calendar->name,
                'location'     => $this->location($occurrence['recurrence_id'] ?? null),
                'link'         => CalendarEvent::permalink($this->event->id, $occurrence['recurrence_id'] ?? null),
                'conversation' => $conversation && $this->user->can('view', $conversation) ? $conversation : null,
            ]);
    }

    /** The occurrence's own location when it was changed, the series' otherwise. */
    private function location(?string $recurrenceId): string
    {
        $vcal = Reader::read($this->event->ics);
        $tz = new DateTimeZone($this->event->timezone ?: $this->event->calendar->timezoneName());
        $vevent = ($recurrenceId ? EventIcs::findOverride($vcal, $this->event->uid, $recurrenceId, $tz) : null)
            ?? EventIcs::master($vcal, $this->event->uid);

        return trim((string) ($vevent->LOCATION ?? ''));
    }
}
