<?php

namespace Modules\LJPcCalendarModule\Http\Helpers;

use DateTime;
use DateTimeImmutable;

class ICS {
		const DT_FORMAT = "Ymd\THis\Z";
		const DATE_FORMAT = "Ymd";

		protected $properties = [];
		private $available_properties = [
				'description',
				'dtend',
				'dtstart',
				'location',
				'summary',
				'url',
				'uid',
				'allDay',
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
						if ( $k !== 'allDay' ) { // Skip 'allDay' property
								$props[ strtoupper( $k . ( $k === 'url' ? ';VALUE=URI' : '' ) ) ] = $v;
						}
				}

				// Set some default values
				$props['DTSTAMP'] = $this->format_timestamp( 'now' );

				$allDay = $this->properties['allDay'] ?? false;

				if ( $allDay ) {
						// For all-day events
						$props['DTSTART;VALUE=DATE'] = $this->format_date( $props['DTSTART'] );
						$props['DTEND;VALUE=DATE']   = $this->format_date( $props['DTEND'] );
				} else {
						// For regular events
						$props['DTSTART;TZID=UTC'] = $props['DTSTART'];
						$props['DTEND;TZID=UTC']   = $props['DTEND'];
				}

				unset( $props['DTSTART'], $props['DTEND'] );

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
						case 'allDay':
								$val = (bool) $val;
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

		private function format_date( $timestamp ) {
				if ( $timestamp instanceof DateTime || $timestamp instanceof DateTimeImmutable ) {
						return $timestamp->format( self::DATE_FORMAT );
				}
				$dt = new DateTime( $timestamp );

				return $dt->format( self::DATE_FORMAT );
		}

		private function escape_string( $str ) {
				if ( $str === null ) {
						return '';
				}

				return preg_replace( '/([\,;])/', '\\\$1', $str );
		}
}
