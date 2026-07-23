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
				\Log::debug( 'CalDAV getEvents called' );

				$xmlBody = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
				           '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">' . "\n" .
				           '  <D:prop>' . "\n" .
				           '    <D:getetag/>' . "\n" .
				           '    <C:calendar-data/>' . "\n" .
				           '  </D:prop>' . "\n" .
				           '  <C:filter>' . "\n" .
				           '    <C:comp-filter name="VCALENDAR">' . "\n" .
				           '      <C:comp-filter name="VEVENT">' . "\n" .
				           '      </C:comp-filter>' . "\n" .
				           '    </C:comp-filter>' . "\n" .
				           '  </C:filter>' . "\n" .
				           '</C:calendar-query>';

				try {
						$response = $this->client->request( 'REPORT', $calendarUrl, $xmlBody, [
								'Content-Type' => 'application/xml; charset=utf-8',
								'Depth' => '1',
						] );
				} catch ( \Exception $e ) {
						\Log::debug( 'CalDAV REPORT query failed, returning empty events list', [
								'error' => $e->getMessage(),
						] );
						return [];
				}

				\Log::debug( 'CalDAV REPORT response', [
						'status'      => $response['statusCode'] ?? 'no status',
						'body_length' => strlen( $response['body'] ?? '' ),
				] );

				$events = [];
				if ( isset( $response['statusCode'] ) && $response['statusCode'] >= 200 && $response['statusCode'] < 300 ) {
						if ( isset( $response['body'] ) && ! empty( $response['body'] ) ) {
								$events = $this->extractCalendarDataFromMultistatus( $response['body'] );
								if ( count( $events ) === 0 ) {
										$hrefs  = $this->extractEventHrefsFromMultistatus( $response['body'], $calendarUrl );
										$events = $this->fetchEventsByHref( $hrefs );
								}
								\Log::debug( 'CalDAV parsed events count', [ 'count' => count( $events ) ] );
						}
				} else {
						\Log::debug( 'CalDAV REPORT failed', [ 'status' => $response['statusCode'] ?? 'unknown' ] );
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

		/**
		 * Extract calendar-data nodes from a CalDAV multistatus XML response.
		 * Uses XML parsing for namespace-agnostic extraction and regex as fallback.
		 *
		 * @param string $responseBody
		 * @return string[]
		 */
		private function extractCalendarDataFromMultistatus( $responseBody ) {
				$events = [];

				libxml_use_internal_errors( true );
				$document = new \DOMDocument();
				if ( $document->loadXML( $responseBody, LIBXML_NONET ) ) {
						$xpath = new \DOMXPath( $document );
						$nodes = $xpath->query( '//*[local-name()="calendar-data"]' );
						if ( $nodes !== false ) {
								foreach ( $nodes as $node ) {
										$data = trim( $node->nodeValue );
										if ( $data !== '' ) {
												$events[] = $data;
										}
								}
						}
				}
				libxml_clear_errors();

				if ( count( $events ) > 0 ) {
						return $events;
				}

				$matches = [];
				preg_match_all( '/<(?:[A-Za-z0-9_-]+:)?calendar-data[^>]*>(.*?)<\/(?:[A-Za-z0-9_-]+:)?calendar-data>/s', $responseBody, $matches );
				if ( isset( $matches[1] ) ) {
						foreach ( $matches[1] as $data ) {
								$decoded = html_entity_decode( trim( $data ), ENT_XML1, 'UTF-8' );
								if ( $decoded !== '' ) {
										$events[] = $decoded;
								}
						}
				}

				return $events;
		}

		/**
		 * Extract event hrefs from a CalDAV multistatus XML response.
		 *
		 * @param string $responseBody
		 * @param string $calendarUrl
		 * @return string[]
		 */
		private function extractEventHrefsFromMultistatus( $responseBody, $calendarUrl ) {
				$hrefs = [];

				libxml_use_internal_errors( true );
				$document = new \DOMDocument();
				if ( $document->loadXML( $responseBody, LIBXML_NONET ) ) {
						$xpath = new \DOMXPath( $document );
						$nodes = $xpath->query( '//*[local-name()="href"]' );
						if ( $nodes !== false ) {
								foreach ( $nodes as $node ) {
										$href = trim( $node->nodeValue );
										if ( $href === '' ) {
												continue;
										}
										if ( substr( $href, -1 ) === '/' ) {
												continue;
										}
										if ( stripos( $href, '.ics' ) === false ) {
												continue;
										}
										$hrefs[] = $href;
								}
						}
				}
				libxml_clear_errors();

				return array_values( array_unique( $hrefs ) );
		}

		/**
		 * Fetch event bodies by href list.
		 *
		 * @param string[] $hrefs
		 * @return string[]
		 */
		private function fetchEventsByHref( $hrefs ) {
				$events = [];
				foreach ( $hrefs as $href ) {
						try {
								$response = $this->client->request( 'GET', $href, null, [
										'Accept' => 'text/calendar, */*;q=0.1',
								] );
								if ( isset( $response['statusCode'] ) && $response['statusCode'] >= 200 && $response['statusCode'] < 300 ) {
										$body = trim( $response['body'] ?? '' );
										if ( $body !== '' ) {
												$events[] = $body;
										}
								}
						} catch ( \Exception $e ) {
								\Log::debug( 'CalDAV event GET failed', [ 'error' => $e->getMessage() ] );
						}
				}

				return $events;
		}

}
