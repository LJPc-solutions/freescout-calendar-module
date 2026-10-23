<?php

namespace Modules\LJPcCalendarModule\Services;

/**
 * A whole-series change would reset the series' edited and deleted occurrences, and the agent has not confirmed it
 * (drop_exceptions). Nothing has been saved when this is thrown; the API answers 409 so the agent can confirm.
 */
class DropsExceptions extends \RuntimeException
{
}
