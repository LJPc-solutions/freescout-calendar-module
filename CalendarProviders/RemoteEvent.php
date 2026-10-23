<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

/** One pulled event: the VCALENDAR of one UID, or a partial update of a stored one. */
final class RemoteEvent
{
    public string $remoteId;

    public string $ics;

    public ?string $href;

    public ?string $etag;

    public bool $readOnly;

    public bool $partial;

    /** @var string[] occurrence keys (EventIcs::recurrenceKey format) the provider cancelled */
    public array $cancelledKeys;

    /**
     * $ics holds one UID. When $partial, its components are merged into the stored VCALENDAR (master and overrides
     * replaced by RECURRENCE-ID) and $cancelledKeys become EXDATEs.
     */
    public function __construct(string $remoteId, string $ics, ?string $href = null, ?string $etag = null, bool $readOnly = false, bool $partial = false, array $cancelledKeys = [])
    {
        $this->remoteId = $remoteId;
        $this->ics = $ics;
        $this->href = $href;
        $this->etag = $etag;
        $this->readOnly = $readOnly;
        $this->partial = $partial;
        $this->cancelledKeys = $cancelledKeys;
    }
}
