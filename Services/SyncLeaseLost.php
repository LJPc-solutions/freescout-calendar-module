<?php

namespace Modules\LJPcCalendarModule\Services;

/** A sync run outlived its lease and another run took the calendar over; the run stops without recording a result. */
final class SyncLeaseLost extends \RuntimeException
{
}
