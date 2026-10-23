<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/** FreeScout's own calendars: the database is the calendar, so every write goes through EventService and nowhere else. */
class LocalProvider implements CalendarProvider
{
    public function canWrite(): bool
    {
        return true;
    }

    public function writableRecurrence(VEvent $master): bool
    {
        return true;
    }

    public function listCalendars(CalendarConnection $connection, ?string $owner = null): array
    {
        return [];
    }

    public function pull(Calendar $calendar, array $state, SyncBudget $budget): ChangeSet
    {
        return new ChangeSet([], [], $state, true, false);
    }

    public function create(Calendar $calendar, VCalendar $vcal): RemoteRef
    {
        return new RemoteRef('');
    }

    public function update(Calendar $calendar, CalendarEvent $event, VCalendar $vcal, ?string $occurrenceKey = null): RemoteRef
    {
        return new RemoteRef('');
    }

    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
    }
}
