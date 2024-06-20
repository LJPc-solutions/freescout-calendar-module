<?php

namespace Modules\LJPcCalendarModule\Http\Helpers;

use DateTime;
use DateTimeImmutable;

/**
 * This is free and unencumbered software released into the public domain.
 *
 * Anyone is free to copy, modify, publish, use, compile, sell, or
 * distribute this software, either in source code form or as a compiled
 * binary, for any purpose, commercial or non-commercial, and by any
 * means.
 *
 * In jurisdictions that recognize copyright laws, the author or authors
 * of this software dedicate any and all copyright interest in the
 * software to the public domain. We make this dedication for the benefit
 * of the public at large and to the detriment of our heirs and
 * successors. We intend this dedication to be an overt act of
 * relinquishment in perpetuity of all present and future rights to this
 * software under copyright law.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR
 * OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
 * ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 *
 * For more information, please refer to <http://unlicense.org>
 *
 * ICS.php
 * =============================================================================
 * Use this class to create an .ics file.
 *
 *
 * Usage
 * -----------------------------------------------------------------------------
 * Basic usage - generate ics file contents (see below for available properties):
 *   $ics = new ICS($props);
 *   $ics_file_contents = $ics->to_string();
 *
 * Setting properties after instantiation
 *   $ics = new ICS();
 *   $ics->set('summary', 'My awesome event');
 *
 * You can also set multiple properties at the same time by using an array:
 *   $ics->set(array(
 *     'dtstart' => 'now + 30 minutes',
 *     'dtend' => 'now + 1 hour'
 *   ));
 *
 * Available properties
 * -----------------------------------------------------------------------------
 * description
 *   String description of the event.
 * dtend
 *   A date/time stamp designating the end of the event. You can use either a
 *   DateTime object or a PHP datetime format string (e.g. "now + 1 hour").
 * dtstart
 *   A date/time stamp designating the start of the event. You can use either a
 *   DateTime object or a PHP datetime format string (e.g. "now + 1 hour").
 * location
 *   String address or description of the location of the event.
 * summary
 *   String short summary of the event - usually used as the title.
 * url
 *   A url to attach to the event. Make sure to add the protocol (http://
 *   or https://).
 */
class ICS {
    const DT_FORMAT = "Ymd\THis\Z";

    protected $properties = [];
    private $available_properties = [
        'description',
        'dtend',
        'dtstart',
        'location',
        'summary',
        'url',
        'uid',
    ];

    public function __construct( $props ) {
        $this->set( $props );
    }

    public function set( $key, $val = false ) {
        if ( is_array( $key ) ) {
            foreach ( $key as $k => $v ) {
                $this->set( $k, $v );
            }
        } else {
            if ( in_array( $key, $this->available_properties ) ) {
                $this->properties[ $key ] = $this->sanitize_val( $val, $key );
            }
        }
    }

    public function to_string() {
        $rows = $this->build_props();

        return implode( "\r\n", $rows );
    }

    private function build_props() {
        // Build ICS properties - add header
        $ics_props = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//hacksw/handcal//NONSGML v1.0//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
        ];

        // Build ICS properties - add header
        $props = [];
        foreach ( $this->properties as $k => $v ) {
            $props[ strtoupper( $k . ( $k === 'url' ? ';VALUE=URI' : '' ) ) ] = $v;
        }

        // Set some default values
        $props['DTSTAMP'] = $this->format_timestamp( 'now' );

        //convert dtstart to DTSTART;TZID=UTC:[date]
        $dtstart                       = $props['DTSTART'];
        $props['DTSTART;TZID=UTC'] = $dtstart;
        unset( $props['DTSTART'] );

        //convert dtend to DTEND;TZID=UTC:[date]
        $dtend                       = $props['DTEND'];
        $props['DTEND;TZID=UTC'] = $dtend;
        unset( $props['DTEND'] );

        // Append properties
        foreach ( $props as $k => $v ) {
            $ics_props[] = "$k:$v";
        }

        // Build ICS properties - add footer
        $ics_props[] = 'END:VEVENT';
        $ics_props[] = 'END:VCALENDAR';

        return $ics_props;
    }

    private function sanitize_val( $val, $key = false ) {
        switch ( $key ) {
            case 'dtend':
            case 'dtstamp':
            case 'dtstart':
                $val = $this->format_timestamp( $val );
                break;
            default:
                $val = $this->escape_string( $val );
        }

        return $val;
    }

    private function format_timestamp( $timestamp ) {
        if ( $timestamp instanceof DateTime || $timestamp instanceof DateTimeImmutable ) {
            return $timestamp->format( self::DT_FORMAT );
        }
        $dt = new DateTime( $timestamp );

        return $dt->format( self::DT_FORMAT );
    }

    private function escape_string( $str ) {
        if ( $str === null ) {
            return '';
        }

        return preg_replace( '/([\,;])/', '\\\$1', $str );
    }
}
