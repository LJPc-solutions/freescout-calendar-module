<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Google;

/**
 * Stops a Google round before its next read once the sync's budget is spent (GoogleProvider): the round ends at the
 * event it was about to read, and the next round lists the same page again. Never leaves the provider.
 */
final class RoundBudgetSpent extends \RuntimeException
{
}
