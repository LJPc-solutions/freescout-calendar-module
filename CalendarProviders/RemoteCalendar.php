<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

/** One calendar of a connected account, as offered on the pick screen. */
final class RemoteCalendar
{
    public string $remoteId;

    public string $name;

    public ?string $color;

    public ?string $timezone;

    public bool $writable;

    public bool $primary;

    /**
     * $remoteId is what calendars.remote_id stores: ICS URL, CalDAV collection URL, Google calendarId, Graph path
     * ("me/calendars/{id}" or "users/{address}/calendars/{id}").
     */
    public function __construct(string $remoteId, string $name, ?string $color, ?string $timezone, bool $writable, bool $primary = false)
    {
        $this->remoteId = $remoteId;
        $this->name = $name;
        $this->color = $color;
        $this->timezone = $timezone;
        $this->writable = $writable;
        $this->primary = $primary;
    }
}
