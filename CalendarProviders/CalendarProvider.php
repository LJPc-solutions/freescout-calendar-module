<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * One kind of calendar: local, ICS feed, CalDAV, Google or Microsoft. Failures are thrown as
 * Exceptions\ProviderException subclasses, so the agent API and the sync engine can answer them the same way.
 */
interface CalendarProvider
{
    /** Whether events can be created, changed and deleted through this provider at all. */
    public function canWrite(): bool;

    /** Whether this provider can write the series rule of this master (Microsoft: maps onto patternedRecurrence). */
    public function writableRecurrence(VEvent $master): bool;

    /**
     * The calendars of a connected account. $owner is a Microsoft shared mailbox address, null for the account itself.
     *
     * @return RemoteCalendar[]
     */
    public function listCalendars(CalendarConnection $connection, ?string $owner = null): array;

    /** One pull round. $state is calendars.sync_state without the engine's own keys (those start with "_"). */
    public function pull(Calendar $calendar, array $state, SyncBudget $budget): ChangeSet;

    public function create(Calendar $calendar, VCalendar $vcal): RemoteRef;

    /** $occurrenceKey is set for an occurrence-scope write (an override added or changed, or an EXDATE added); null for the series. */
    public function update(Calendar $calendar, CalendarEvent $event, VCalendar $vcal, ?string $occurrenceKey = null): RemoteRef;

    /** Deletes the whole event. A 404 or 410 from the provider counts as success. */
    public function delete(Calendar $calendar, CalendarEvent $event): void;
}
