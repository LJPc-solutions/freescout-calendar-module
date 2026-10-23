<?php

namespace Modules\LJPcCalendarModule\Services;

/**
 * The calendar's provider can't write this series' repeat rule (Microsoft maps only some rules onto Graph), so the
 * series can only be changed one occurrence at a time. Thrown before anything reaches the provider; the API answers 403.
 */
class SeriesNotWritable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('This series can only be changed one occurrence at a time.'));
    }
}
