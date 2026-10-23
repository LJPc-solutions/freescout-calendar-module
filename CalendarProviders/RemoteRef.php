<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

/** What a provider returns for a write: where the event lives now and its new version. */
final class RemoteRef
{
    /** Empty for a local calendar. */
    public string $remoteId;

    public ?string $href;

    public ?string $etag;

    public ?string $uid;

    /** $uid is set when the provider assigned its own iCalendar UID (Microsoft). */
    public function __construct(string $remoteId, ?string $href = null, ?string $etag = null, ?string $uid = null)
    {
        $this->remoteId = $remoteId;
        $this->href = $href;
        $this->etag = $etag;
        $this->uid = $uid;
    }
}
