<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Exceptions;

/** The provider could not be reached, or answered with an error. */
class ProviderUnavailable extends ProviderException
{
    /** $message is a specific translated message; without one the agent gets the generic text. */
    public function __construct(?string $message = null, ?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message ?? __('The calendar provider could not be reached. Try again later.'), $httpStatus, $previous);
    }

    public function status(): int
    {
        return 502;
    }

    public function code(): string
    {
        return 'provider_unavailable';
    }
}
