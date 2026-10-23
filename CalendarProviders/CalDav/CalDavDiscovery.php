<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\CalDav;

use DateTimeZone;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteCalendar;
use Sabre\Uri;
use Sabre\Uri\InvalidUriException;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

/**
 * Finds the calendars of a CalDAV account (RFC 4791 section 7.1, RFC 6764): /.well-known/caldav or the address
 * itself, then the current user's principal, its calendar homes, and the calendar collections in them that can hold
 * events. An address that is a calendar collection itself, where nothing else is found, is that one calendar.
 */
class CalDavDiscovery
{
    private const CALENDAR = '{' . CalDavClient::NS_CALDAV . '}calendar';

    private const CALENDAR_PROPS = [
        '{DAV:}resourcetype',
        '{DAV:}displayname',
        '{' . CalDavClient::NS_APPLE . '}calendar-color',
        '{' . CalDavClient::NS_CALDAV . '}calendar-timezone',
        '{' . CalDavClient::NS_CALDAV . '}supported-calendar-component-set',
        '{DAV:}current-user-privilege-set',
    ];

    private const WRITE_PRIVILEGES = ['{DAV:}write', '{DAV:}write-content', '{DAV:}all'];

    /** @return RemoteCalendar[] with absolute collection addresses */
    public function discover(CalDavClient $client, string $serverUrl): array
    {
        $serverUrl = trim($serverUrl);
        $principal = $this->principalFromWellKnown($client, $serverUrl);
        if ($principal === null) {
            $own = self::first($client->propfind($serverUrl, 0, array_merge(['{DAV:}current-user-principal'], self::CALENDAR_PROPS)));
            if ($own !== null && self::isCalendar($own['props'])) {
                return [self::calendar($own['href'], $own['props'])];
            }
            $principal = $own !== null ? (CalDavClient::hrefs($own['props']['{DAV:}current-user-principal'] ?? null, $own['href'])[0] ?? null) : null;
        }
        if ($principal === null) {
            throw new ProviderUnavailable(__('No CalDAV calendars were found at this address.'));
        }

        $home = self::first($client->propfind($principal, 0, ['{' . CalDavClient::NS_CALDAV . '}calendar-home-set']));
        $homes = $home !== null ? CalDavClient::hrefs($home['props']['{' . CalDavClient::NS_CALDAV . '}calendar-home-set'] ?? null, $home['href']) : [];
        $calendars = [];
        foreach ($homes as $url) {
            foreach ($client->propfind($url, 1, self::CALENDAR_PROPS) as $href => $response) {
                if ($response['status'] === 200 && self::isCalendar($response['props'])) {
                    $calendars[$href] = self::calendar($href, $response['props']);
                }
            }
        }
        if (!$calendars) {
            throw new ProviderUnavailable(__('No CalDAV calendars were found at this address.'));
        }

        return array_values($calendars);
    }

    /**
     * The principal named at /.well-known/caldav (RFC 6764 section 5), or null when the server has no such address.
     * A blocked redirect or a network failure is not a missing address, so those are thrown.
     */
    private function principalFromWellKnown(CalDavClient $client, string $serverUrl): ?string
    {
        try {
            $parts = Uri\parse($serverUrl);
        } catch (InvalidUriException $e) {
            return null;
        }
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null; // the request to the address itself reports what is wrong with it
        }
        $wellKnown = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/.well-known/caldav';
        try {
            $response = self::first($client->propfind($wellKnown, 0, ['{DAV:}current-user-principal']));
        } catch (CalDavRequestFailed $e) {
            return null;
        }

        return $response !== null ? (CalDavClient::hrefs($response['props']['{DAV:}current-user-principal'] ?? null, $response['href'])[0] ?? null) : null;
    }

    /** @return array{href: string, status: int, props: array}|null the first response that found its properties */
    private static function first(array $responses): ?array
    {
        foreach ($responses as $href => $response) {
            if ($response['status'] === 200) {
                return ['href' => $href] + $response;
            }
        }

        return null;
    }

    private static function isCalendar(array $props): bool
    {
        if (!in_array(self::CALENDAR, CalDavClient::names($props['{DAV:}resourcetype'] ?? null), true)) {
            return false;
        }
        // Without the property, a collection takes every component type (RFC 4791 section 5.2.3).
        $components = CalDavClient::components($props['{' . CalDavClient::NS_CALDAV . '}supported-calendar-component-set'] ?? null);

        return !$components || in_array('VEVENT', $components, true);
    }

    private static function calendar(string $href, array $props): RemoteCalendar
    {
        $name = CalDavClient::text($props['{DAV:}displayname'] ?? null)
            ?? rawurldecode(basename(rtrim((string) (Uri\parse($href)['path'] ?? ''), '/')));
        $privileges = $props['{DAV:}current-user-privilege-set'] ?? null;
        // A server that doesn't report privileges is taken at its word that the account may write; a refused write says so.
        $writable = !array_key_exists('{DAV:}current-user-privilege-set', $props)
            || (bool) array_intersect(self::WRITE_PRIVILEGES, CalDavClient::names($privileges));

        return new RemoteCalendar(
            $href,
            $name !== '' ? $name : $href,
            self::color(CalDavClient::text($props['{' . CalDavClient::NS_APPLE . '}calendar-color'] ?? null)),
            self::timezone(CalDavClient::text($props['{' . CalDavClient::NS_CALDAV . '}calendar-timezone'] ?? null)),
            $writable
        );
    }

    /** Apple writes #RRGGBB or #RRGGBBAA; anything else is no color. */
    private static function color(?string $color): ?string
    {
        return $color !== null && preg_match('/^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/i', $color, $match) ? '#' . strtolower($match[1]) : null;
    }

    /** The TZID of the VTIMEZONE in calendar-timezone, when PHP knows it. */
    private static function timezone(?string $vtimezone): ?string
    {
        if ($vtimezone === null) {
            return null;
        }
        try {
            $vcal = Reader::read($vtimezone, Reader::OPTION_FORGIVING);
        } catch (ParseException $e) {
            return null;
        }
        $tzid = isset($vcal->VTIMEZONE->TZID) ? trim((string) $vcal->VTIMEZONE->TZID) : '';
        try {
            return $tzid !== '' ? (new DateTimeZone($tzid))->getName() : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
