<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

use Modules\LJPcCalendarModule\Entities\Calendar;

/**
 * A provider whose full listing can leave out an event that still exists (Microsoft Graph pages by offset, so a deletion
 * during a listing shifts another event out of it). Before the engine's sweep deletes the events a completed listing
 * left out, it asks such a provider which of them are really gone, and deletes only those.
 */
interface ConfirmsDeletions
{
    /**
     * The remote ids of $remoteIds the provider no longer has. It checks within the budget; ids it found or did not get
     * to are left out of the answer, so the engine keeps them as seen in this listing, and the next listing that leaves
     * them out asks again.
     *
     * @param string[] $remoteIds
     * @return string[]
     */
    public function confirmGone(Calendar $calendar, array $remoteIds, SyncBudget $budget): array;
}
