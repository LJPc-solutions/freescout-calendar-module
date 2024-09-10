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

}
