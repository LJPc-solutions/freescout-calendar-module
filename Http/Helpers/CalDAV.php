<?php

namespace Modules\LJPcCalendarModule\Http\Helpers;

use Dallgoot\Yaml\Yaml;
use Sabre\DAV\Client;

class CalDAV {
		private $client;

		public function __construct( $baseUri, $userName, $password ) {
				$settings = [
						'baseUri'  => $baseUri,
						'userName' => $userName,
						'password' => $password,
				];

				$this->client = new Client( $settings );
		}

		public function getEvents( $calendarUrl ) {
				$response = $this->client->propFind( $calendarUrl, [
						'{DAV:}displayname',
						'{urn:ietf:params:xml:ns:caldav}calendar-description',
						'{urn:ietf:params:xml:ns:caldav}calendar-data',
				], 2 );

				$events = [];
				foreach ( $response as $eventData ) {
						if ( isset( $eventData['{urn:ietf:params:xml:ns:caldav}calendar-data'] ) ) {
								$events[] = $eventData['{urn:ietf:params:xml:ns:caldav}calendar-data'];
						}
				}

				return $events;
		}

		/**
		 * @param $calendarUrl
		 * @param $uid
		 * @param $summary
		 * @param $description
		 * @param $start
		 * @param $end
		 * @param $allDay
		 * @param $location
		 *
		 * @return array{body: string, statusCode: int, headers: array}
		 * @throws \Exception
		 */
		public function createEvent( $calendarUrl, $uid, $summary, $description, $start, $end, $allDay, $location ): array {
				if ( ! is_string( $description ) ) {
						$description = Yaml::dump( $description );
				}
				$ics = new ICS( [
						'uid'         => $uid,
						'location'    => str_replace( "\n", '\n', $location ?? '' ),
						'description' => str_replace( "\n", '\n', $description ?? '' ),
						'dtstart'     => $start,
						'dtend'       => $end,
						'summary'     => str_replace( "\n", '\n', $summary ?? '' ),
						'allDay'      => $allDay,
				] );

				return $this->client->request( 'PUT', $calendarUrl . $uid . '.ics', $ics->to_string(), [
						'Content-Type'  => 'text/calendar; charset=utf-8',
						'If-None-Match' => '*',
				] );
		}

		public function createEventFromICS( $calendarUrl, $uid, $icsContent ) {
				return $this->client->request( 'PUT', $calendarUrl . $uid . '.ics', $icsContent, [
						'Content-Type'  => 'text/calendar; charset=utf-8',
						'If-None-Match' => '*',
				] );
		}

		public function updateEvent( $calendarUrl, $uid, $summary, $description, $start, $end, $location ) {
				if ( ! is_string( $description ) ) {
						$description = Yaml::dump( $description );
				}
				$ics = new ICS( [
						'uid'         => $uid,
						'location'    => str_replace( "\n", '\n', $location ?? '' ),
						'description' => str_replace( "\n", '\n', $description ?? '' ),
						'dtstart'     => $start,
						'dtend'       => $end,
						'summary'     => str_replace( "\n", '\n', $summary ?? '' ),
				] );

				return $this->client->request( 'PUT', $calendarUrl . $uid . '.ics', $ics->to_string(), [
						'Content-Type' => 'text/calendar; charset=utf-8',
				] );
		}

		public function deleteEvent( $calendarUrl, $uid ) {
				return $this->client->request( 'DELETE', $calendarUrl . $uid . '.ics' );
		}

		/**
		 * Get a single event by UID using CalDAV REPORT query
		 * This is much more efficient than fetching all events
		 * 
		 * @param string $calendarUrl The calendar URL
		 * @param string $uid The event UID to fetch
		 * @return array|null The event data or null if not found
		 */
		public function getEventByUid( $calendarUrl, $uid ) {
				$xmlBody = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
				           '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">' . "\n" .
				           '  <D:prop>' . "\n" .
				           '    <D:getetag/>' . "\n" .
				           '    <C:calendar-data/>' . "\n" .
				           '  </D:prop>' . "\n" .
				           '  <C:filter>' . "\n" .
				           '    <C:comp-filter name="VCALENDAR">' . "\n" .
				           '      <C:comp-filter name="VEVENT">' . "\n" .
				           '        <C:prop-filter name="UID">' . "\n" .
				           '          <C:text-match>' . htmlspecialchars( $uid, ENT_XML1, 'UTF-8' ) . '</C:text-match>' . "\n" .
				           '        </C:prop-filter>' . "\n" .
				           '      </C:comp-filter>' . "\n" .
				           '    </C:comp-filter>' . "\n" .
				           '  </C:filter>' . "\n" .
				           '</C:calendar-query>';

				try {
						$response = $this->client->request( 'REPORT', $calendarUrl, $xmlBody, [
								'Content-Type' => 'application/xml; charset=utf-8',
								'Depth' => '1',
						] );

						// Check if we got a successful response
						if ( isset( $response['statusCode'] ) && $response['statusCode'] >= 200 && $response['statusCode'] < 300 ) {
								// Parse the response body if available
								if ( isset( $response['body'] ) && ! empty( $response['body'] ) ) {
										// The response should contain the calendar data
										// We need to extract it from the XML response
										$matches = [];
										if ( preg_match( '/<cal:calendar-data[^>]*>(.*?)<\/cal:calendar-data>/s', $response['body'], $matches ) ||
										     preg_match( '/<C:calendar-data[^>]*>(.*?)<\/C:calendar-data>/s', $response['body'], $matches ) ||
										     preg_match( '/<calendar-data[^>]*>(.*?)<\/calendar-data>/s', $response['body'], $matches ) ) {
												return html_entity_decode( $matches[1], ENT_XML1, 'UTF-8' );
										}
								}
						}
				} catch ( \Exception $e ) {
						// Log the error but don't throw - we'll fall back to the old method
						\Log::info( 'CalDAV REPORT query failed, will fall back to full fetch', [
								'error' => $e->getMessage(),
								'calendar_url' => $calendarUrl,
								'uid' => $uid
						] );
				}

				return null;
		}

		/**
		 * Check if the CalDAV server supports REPORT queries
		 * 
		 * @param string $calendarUrl
		 * @return bool
		 */
		public function supportsReportQuery( $calendarUrl ) {
				try {
						$response = $this->client->options( $calendarUrl );
						
						if ( isset( $response['headers']['allow'] ) ) {
								$allow = is_array( $response['headers']['allow'] ) 
										? implode( ',', $response['headers']['allow'] ) 
										: $response['headers']['allow'];
								return stripos( $allow, 'REPORT' ) !== false;
						}
						
						// Also check DAV header for calendar-access
						if ( isset( $response['headers']['dav'] ) ) {
								$dav = is_array( $response['headers']['dav'] ) 
										? implode( ',', $response['headers']['dav'] ) 
										: $response['headers']['dav'];
								return stripos( $dav, 'calendar-access' ) !== false;
						}
				} catch ( \Exception $e ) {
						\Log::debug( 'Failed to check CalDAV server capabilities', [
								'error' => $e->getMessage(),
								'calendar_url' => $calendarUrl
						] );
				}
				
				return false;
		}

}
