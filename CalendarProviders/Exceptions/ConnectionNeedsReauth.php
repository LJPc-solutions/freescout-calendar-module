<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Exceptions;

/** The connection's credentials no longer work; an administrator has to connect the account again. */
class ConnectionNeedsReauth extends ProviderException
{
    public function __construct(?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct(__("This calendar's connection needs to be re-authorized by an administrator."), $httpStatus, $previous);
    }

    public function status(): int
    {
        return 502;
    }

    public function code(): string
    {
        return 'connection_needs_reauth';
    }
}
