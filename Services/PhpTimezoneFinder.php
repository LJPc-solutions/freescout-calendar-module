<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeZone;
use Sabre\VObject\TimezoneGuesser\TimezoneFinder;

/**
 * Lets sabre/vobject read every zone name PHP still knows, also the ones tzdata renamed (Europe/Kiev, Asia/Rangoon,
 * America/Godthab). Sabre's own lists miss those, and it would read such a TZID in PHP's default zone. The service
 * provider registers it at boot.
 */
final class PhpTimezoneFinder implements TimezoneFinder
{
    private ?array $names = null;

    public function find(string $tzid, bool $failIfUncertain = false): ?DateTimeZone
    {
        $this->names = $this->names ?? array_flip(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));
        // Zone names have a slash; the system's tzdata also lists files that are no zone ("tzdata.zi", "localtime").
        if (!isset($this->names[$tzid]) || strpos($tzid, '/') === false) {
            return null;
        }
        try {
            return new DateTimeZone($tzid);
        } catch (\Exception $e) {
            return null; // "not found" is this method's answer; sabre then asks its next finder
        }
    }
}
