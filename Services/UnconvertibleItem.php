<?php

namespace Modules\LJPcCalendarModule\Services;

/** A v2 event that can never be converted (for example unusable times); retrying would not help, so it is listed in the report instead. */
class UnconvertibleItem extends \RuntimeException
{
}
