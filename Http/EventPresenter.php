<?php

namespace Modules\LJPcCalendarModule\Http;

use App\Conversation;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\InviteActions;
use Modules\LJPcCalendarModule\Services\InviteSender;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\RecurrenceRule;
use Modules\LJPcCalendarModule\Services\VTimezone;
use Sabre\VObject\Reader;

/** JSON shapes of the agent API. Timed values are ISO 8601 with offset in the agent's timezone; all-day values are dates. */
final class EventPresenter
{
    public static function calendar(Calendar $calendar, User $user): array
    {
        return [
            'id'                 => $calendar->id,
            'name'               => $calendar->name,
            'color'              => $calendar->color,
            'provider'           => $calendar->provider,
            'display_priority'   => $calendar->display_priority,
            'fields'             => $calendar->fieldDefinitions(),
            'has_title_template' => trim((string) $calendar->title_template) !== '',
            'rights'             => CalendarPolicy::rights($user, $calendar),
            'writable'           => $calendar->isWritable(),
            // Everyone only learns that a sync failed, never last_error: that is for the settings. A calendar whose account
            // must be connected again doesn't sync at all, also when no sync of its own failed yet.
            'sync_failed'        => trim((string) $calendar->last_error) !== '' || self::connectionBroken($calendar),
            // The last successful sync (ISO 8601 UTC), null when there was none; the page tells agents about calendars
            // that failed and last synced more than a day ago.
            'last_synced_at'     => $calendar->last_synced_at
                ? (new DateTimeImmutable($calendar->last_synced_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')
                : null,
        ];
    }

    public static function occurrence(array $row, CalendarEvent $event, bool $canEdit, string $timezone): array
    {
        return [
            'id'            => $row['recurrence_id'] ? $event->id . ':' . $row['recurrence_id'] : (string) $event->id,
            'event_id'      => $event->id,
            'calendar_id'   => $event->calendar_id,
            'recurrence_id' => $row['recurrence_id'],
            'title'         => $row['summary'] ?? $event->summary,
            'start'         => self::time($row['starts_at'], (bool) $row['all_day'], $timezone),
            'end'           => self::time($row['ends_at'], (bool) $row['all_day'], $timezone),
            'all_day'       => (bool) $row['all_day'],
            // An invite we declined stays in the calendar, struck through like a cancelled event (decision 2026-10-19).
            'status'        => $event->invite_state === InviteActions::STATE_DECLINED ? 'declined' : $row['status'],
            'is_recurring'  => (bool) $event->is_recurring,
            'editable'      => $canEdit && !$event->read_only,
        ];
    }

    public static function event(CalendarEvent $event, User $user, string $timezone, ?string $recurrenceId = null): array
    {
        $tz = new DateTimeZone($event->timezone ?: $timezone);
        $vcal = Reader::read($event->ics);
        $master = EventIcs::master($vcal, $event->uid);
        $override = $recurrenceId ? EventIcs::findOverride($vcal, $event->uid, $recurrenceId, $tz) : null;
        $data = EventIcs::read($override ?? $master, $tz);
        $rrule = isset($master->RRULE) ? (string) $master->RRULE : null;
        // UNTIL is the end of a day where the series lives, not where the agent is; EventService compares a rule that
        // comes back in the same zone.
        $ruleZone = EventIcs::ruleZone($master, $event->calendar->timezoneName());
        $recurrence = RecurrenceRule::fromRrule($rrule, $ruleZone->getName());

        $start = self::formValue($data['start'], $data['all_day'], $timezone);
        $end = self::formValue($data['end'], $data['all_day'], $timezone);
        if ($recurrenceId && !$override && ($row = app(OccurrenceService::class)->find($event, $recurrenceId))) {
            $start = self::time($row['starts_at'], (bool) $row['all_day'], $timezone);
            $end = self::time($row['ends_at'], (bool) $row['all_day'], $timezone);
        }

        $conversation = $event->conversation_id ? Conversation::find($event->conversation_id) : null;
        $author = $event->author_id ? User::find($event->author_id) : null;

        return [
            'id'                => $event->id,
            'calendar_id'       => $event->calendar_id,
            'uid'               => $event->uid,
            'title'             => $data['title'],
            'title_input'       => $event->title_input,
            'location'          => $data['location'],
            'description'       => $data['description'],
            'all_day'           => $data['all_day'],
            // Where a timed event lives; a whole-series copy is written in it (EventService::create).
            'timezone'          => $master->DTSTART->hasTime() && VTimezone::isNamedZone($ruleZone->getName()) ? $ruleZone->getName() : null,
            'start'             => $start,
            'end'               => $end,
            'recurrence'        => $recurrence,
            'recurrence_custom' => $rrule !== null && $recurrence === null,
            'is_recurring'      => (bool) $event->is_recurring,
            'field_values'      => (object) ($event->field_values ?? []),
            'reminder_minutes'  => $event->reminder_minutes,
            'author'            => $author ? ['id' => $author->id, 'name' => $author->getFullName()] : null,
            'conversation'      => $conversation && $user->can('view', $conversation)
                ? ['id' => $conversation->id, 'number' => $conversation->number, 'url' => $conversation->url()]
                : null,
            'editable'          => $user->can('edit', $event->calendar) && !$event->read_only,
            // False for a series whose rule the provider can't write: it changes one occurrence at a time.
            'series_writable'   => $event->calendar->canWriteSeries($master),
            // The people we invited and their answers (InviteSender); only an event from a conversation can have them.
            'attendees'         => array_values((array) $event->attendees),
            'can_invite'        => InviteSender::canInvite($event),
        ];
    }

    private static function connectionBroken(Calendar $calendar): bool
    {
        if (!$calendar->usesConnection()) {
            return false;
        }
        $connection = $calendar->calendarConnection;

        return !$connection || $connection->status !== CalendarConnection::STATUS_OK;
    }

    private static function time(string $utc, bool $allDay, string $timezone): string
    {
        if ($allDay) {
            return substr($utc, 0, 10);
        }

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format(DATE_ATOM);
    }

    private static function formValue($value, bool $allDay, string $timezone): string
    {
        return $allDay ? (string) $value : $value->setTimezone(new DateTimeZone($timezone))->format(DATE_ATOM);
    }
}
