<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

use Sabre\VObject\Component\VEvent;

/** A provider that can say why it can't write a series' rule, so the agent learns more than "can't store this rule". */
interface ExplainsRecurrenceRefusal
{
    /** Why writableRecurrence() refuses this master's rule, translated; null for the general message. */
    public function recurrenceRefusal(VEvent $master): ?string;
}
