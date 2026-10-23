<?php

namespace Modules\LJPcCalendarModule\Tests\Support;

use GuzzleHttp\Psr7\Response;

/** The CalDAV answers the CalDAV tests queue on FakeHttp: fixture files, and multistatus answers built per test. */
final class DavResponses
{
    public static function fixture(string $name, int $status = 207): Response
    {
        return new Response($status, ['Content-Type' => 'application/xml; charset=utf-8'], file_get_contents(__DIR__ . '/../fixtures/caldav/' . $name));
    }

    /**
     * A 207 multistatus. Each entry is href => properties (getetag, getcontenttype, getctag, sync-token,
     * calendar-data, and resourcetype "collection" or "calendar"), or href => a status code for a status-only response.
     */
    public static function multistatus(array $entries, ?string $syncToken = null): Response
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">';
        foreach ($entries as $href => $props) {
            $xml .= '<d:response><d:href>' . self::escape($href) . '</d:href>';
            if (is_int($props)) {
                $xml .= '<d:status>HTTP/1.1 ' . $props . ' Status</d:status></d:response>';
                continue;
            }
            $xml .= '<d:propstat><d:prop>';
            foreach ($props as $name => $value) {
                if ($name === 'resourcetype') {
                    $xml .= '<d:resourcetype>' . ['collection' => '<d:collection/>', 'calendar' => '<d:collection/><c:calendar/>'][$value] . '</d:resourcetype>';
                } elseif ($name === 'calendar-data') {
                    $xml .= '<c:calendar-data>' . self::escape($value) . '</c:calendar-data>';
                } elseif ($name === 'getctag') {
                    $xml .= '<cs:getctag>' . self::escape($value) . '</cs:getctag>';
                } else {
                    $xml .= '<d:' . $name . '>' . self::escape($value) . '</d:' . $name . '>';
                }
            }
            $xml .= '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }
        if ($syncToken !== null) {
            $xml .= '<d:sync-token>' . self::escape($syncToken) . '</d:sync-token>';
        }

        return new Response(207, ['Content-Type' => 'application/xml; charset=utf-8'], $xml . '</d:multistatus>');
    }

    /** One event as a CalDAV server stores it. */
    public static function event(string $uid, string $summary, string $start = '20261020T100000'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\nBEGIN:VEVENT\r\nUID:" . $uid
            . "\r\nDTSTAMP:20261001T080000Z\r\nDTSTART;TZID=Europe/Amsterdam:" . $start . "\r\nDURATION:PT30M\r\nSUMMARY:" . $summary
            . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1, 'UTF-8');
    }
}
