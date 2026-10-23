<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Exceptions;

/** A write's precondition failed: the event changed at the provider since FreeScout last read it. */
class ProviderConflict extends ProviderException
{
    public function __construct(?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct(__('This event was changed elsewhere. Reload and try again.'), $httpStatus, $previous);
    }

    public function status(): int
    {
        return 409;
    }

    public function code(): string
    {
        return 'changed_elsewhere';
    }
}
