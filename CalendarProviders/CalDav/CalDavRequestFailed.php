<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\CalDav;

use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;

/**
 * A CalDAV server answered a request with an error status. Besides the message and the status it keeps the DAV
 * preconditions the error body named (RFC 4918 section 16), such as "valid-sync-token", so callers can react to them.
 */
class CalDavRequestFailed extends ProviderUnavailable
{
    /** @var string[] */
    private array $preconditions;

    /** @param string[] $preconditions local names of the elements inside the body's DAV:error */
    public function __construct(?string $message, int $httpStatus, array $preconditions = [])
    {
        parent::__construct($message, $httpStatus);
        $this->preconditions = $preconditions;
    }

    /** @return string[] */
    public function preconditions(): array
    {
        return $this->preconditions;
    }
}
