<?php

namespace Modules\LJPcCalendarModule\Http\Controllers;

use App\Attachment;
use App\Conversation;
use App\Misc\Helper;
use App\Thread;
use App\User;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use ICal\Event;
use ICal\ICal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarItem;
use Modules\LJPcCalendarModule\Http\Helpers\CalDAV;
use Modules\LJPcCalendarModule\Http\Helpers\DateTimeRange;
use Modules\LJPcCalendarModule\Jobs\UpdateExternalCalendarJob;
use Modules\Teams\Providers\TeamsServiceProvider as Teams;

class LJPcCalendarModuleAPIController extends Controller {
		/**
		 * Get users for the settings
		 *
		 * @return JsonResponse A JSON response containing an array of user objects
		 */
		public function getUsers(): JsonResponse {
				$response = [
						'results' => [],
				];

				// Get all teams
				$allTeams = [];
				if ( class_exists( Teams::class ) ) {
						$allTeams = Teams::getTeams( true );
				}

				// Get all users that are active
				$allUsers = User::where( 'status', User::STATUS_ACTIVE )
				                ->remember( Helper::cacheTime() )
				                ->get();

				// Add team members to the response array
				/** @var Team $team */
				foreach ( $allTeams as $team ) {
						$response['results'][] = [
								'id'   => (string) $team->id,
								'text' => 'Team: ' . $team->getFirstName(),
						];
				}

				// Add users to the response array
				/** @var User $user */
				foreach ( $allUsers as $user ) {
						$response['results'][] = [
								'id'   => (string) $user->id,
								'text' => $user->getFullName(),
						];
				}

				return response()->json( $response );
		}

		/**
		 * Get calendars for the settings
		 *
		 * @return JsonResponse A JSON response containing an array of calendar objects
		 */
		public function getCalendars(): JsonResponse {
				$calendars = Calendar::all();

				return response()->json( $calendars );
		}

		/**
		 * Update a calendar
		 *
		 * @param int $id The ID of the calendar to update
		 * @param Request $request The request object
		 *
		 * @return JsonResponse A JSON response containing the updated calendar object
		 */
		public function updateCalendar( int $id, Request $request ) {
				$calendar = Calendar::find( $id );

				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				$calendar->name  = $request->input( 'name' );
				$calendar->color = $request->input( 'color' );

				$customFields            = $request->input( 'custom_fields', [] );
				$calendar->custom_fields = $this->validateCustomFields( $customFields );

				if ( $calendar->type === 'ics' ) {
						$calendar->custom_fields = array_merge( $calendar->custom_fields, [
								'url'     => $request->input( 'url' ),
								'refresh' => $request->input( 'refresh' ),
						] );
				} else if ( $calendar->type === 'caldav' ) {
						$calendar->custom_fields = array_merge( $calendar->custom_fields, [
								'url'      => $request->input( 'url' ),
								'username' => $request->input( 'username' ),
								'password' => $request->input( 'password' ),
								'refresh'  => $request->input( 'refresh' ),
						] );
				}

				$permissions = [];
				foreach ( $request->input( 'permissions' ) as $id => $permission ) {
						$permissions[ $id ] = [
								'showInDashboard' => $permission['showInDashboard'],
								'showInCalendar'  => $permission['showInCalendar'],
								'createItems'     => $permission['createItems'] ?? false,
								'editItems'       => $permission['editItems'] ?? false,
						];
				}
				$calendar->permissions = $permissions;

				$calendar->save();

				return response()->json( $calendar );
		}

		/**
		 * Validate and sanitize custom fields
		 *
		 * @param array $customFields The custom fields to validate
		 *
		 * @return array The validated and sanitized custom fields
		 */
		private function validateCustomFields( array $customFields ): array {
				$validatedFields = [];

				foreach ( $customFields['fields'] as $field ) {
						$validatedField = [
								'id'       => $field['id'],
								'name'     => strip_tags( $field['name'] ),
								'type'     => in_array( $field['type'], [ 'text', 'number', 'dropdown', 'boolean', 'multiselect', 'date', 'email', 'source' ] ) ? $field['type'] : 'text',
								'required' => (bool) $field['required'],
						];

						if ( in_array( $field['type'], [ 'dropdown', 'multiselect' ] ) ) {
								if ( is_array( $field['options'] ) ) {
										$validatedField['options'] = array_map( 'trim', array_map( 'strip_tags', $field['options'] ) );
								} else {
										$validatedField['options'] = array_map( 'trim', array_map( 'strip_tags', explode( ',', $field['options'] ) ) );
								}
						}

						$validatedFields[] = $validatedField;
				}

				return [ 'fields' => $validatedFields ];
		}

		/**
		 * Create a new calendar
		 *
		 * @param Request $request The request object
		 *
		 * @return JsonResponse A JSON response containing the created calendar object
		 */
		public function addCalendar( Request $request ) {
				$calendar = new Calendar();

				$calendar->name  = $request->input( 'name' );
				$calendar->color = $request->input( 'color' );
				$calendar->type  = $request->input( 'type' );

				$customFields            = $request->input( 'custom_fields', [] );
				$calendar->custom_fields = $this->validateCustomFields( $customFields );

				if ( $calendar->type === 'ics' ) {
						$calendar->custom_fields = [
								'url'     => $request->input( 'url' ),
								'refresh' => $request->input( 'refresh' ),
						];
				} else if ( $calendar->type === 'caldav' ) {
						$calendar->custom_fields = [
								'url'      => $request->input( 'url' ),
								'username' => $request->input( 'username' ),
								'password' => $request->input( 'password' ),
								'refresh'  => $request->input( 'refresh' ),
						];
				}

				$permissions = [];
				foreach ( $request->input( 'permissions' ) as $id => $permission ) {
						$permissions[ $id ] = [
								'showInDashboard' => $permission['showInDashboard'],
								'showInCalendar'  => $permission['showInCalendar'],
								'createItems'     => $permission['createItems'] ?? false,
								'editItems'       => $permission['editItems'] ?? false,
						];
				}
				$calendar->permissions = $permissions;

				$calendar->save();

				return response()->json( $calendar );
		}

		/**
		 * Delete a calendar
		 *
		 * @param int $id The ID of the calendar to delete
		 *
		 * @return JsonResponse A JSON response indicating the deletion result
		 * @throws Exception
		 */
		public function deleteCalendar( int $id ): JsonResponse {
				$calendar = Calendar::find( $id );

				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				if ( $calendar->type === 'ics' || $calendar->type === 'caldav' ) {
						//remove temporary ics file
						$filename = $calendar->getTemporaryFile();
						if ( file_exists( $filename ) ) {
								unlink( $filename );
						}
				} else {
						//remove all calendar items for this calendar
						CalendarItem::where( 'calendar_id', $calendar->id )->delete();
				}

				$calendar->delete();

				return response()->json( [ 'ok' => true, 'message' => 'Calendar deleted successfully' ] );
		}


		/**
		 * Get events
		 *
		 * @param Request $request
		 *
		 * @return JsonResponse
		 * @throws Exception
		 */
		public function getEvents( Request $request ) {
				$request->validate( [
						'start' => 'required|date',
						'end'   => 'required|date',
				] );

				$defaultTimezone = config( 'app.timezone' );

				$start = ( new DateTimeImmutable( $request->input( 'start' ), new DateTimeZone( $defaultTimezone ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
				$end   = ( new DateTimeImmutable( $request->input( 'end' ), new DateTimeZone( $defaultTimezone ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );

				$calendars = Calendar::all();
				$events    = [];

				foreach ( $calendars as $calendar ) {
						if ( $calendar->enabled === false ) {
								continue;
						}
						$permissions = $calendar->permissionsForCurrentUser();
						if ( $permissions === null ) {
								continue;
						}
						if ( $permissions['showInCalendar'] !== true ) {
								continue;
						}

						$calendarEvents = $calendar->events( $start, $end );
						foreach ( $calendarEvents as $event ) {
								$events[] = $event;
						}
				}

				/**
				 * Restore timezones
				 */
				foreach ( $events as &$event ) {
						if ( empty( $event['id'] ) && ! empty( $event['uid'] ) ) {
								$event['id'] = $event['uid'];
						}
						if ( empty( $event['uid'] ) && ! empty( $event['id'] ) ) {
								$event['uid'] = $event['id'];
						}

						$event['start'] = ( new DateTimeImmutable( $event['start'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
						$event['end']   = ( new DateTimeImmutable( $event['end'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
				}
				unset( $event );

				return response()->json( $events );
		}

		/**
		 * Update an event
		 *
		 * @param Request $request
		 *
		 * @return JsonResponse
		 * @throws Exception
		 */
		public function updateEvent( Request $request ) {
				try {
						$validatedData = $request->validate( [
								'uid'        => 'required',
								'title'      => 'required',
								'start'      => 'required|date',
								'end'        => 'required|date|after:start',
								'location'   => 'nullable',
								'body'       => 'nullable',
								'calendarId' => 'required',
						] );
				} catch ( Exception $e ) {
						return response()->json( [ 'error' => $e->getMessage() ], 400 );
				}

				$calendar = Calendar::find( $validatedData['calendarId'] );
				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				if ( $calendar->type === 'normal' ) {
						$calendarItem = CalendarItem::where( 'id', $validatedData['uid'] )->first();
						if ( ! $calendarItem ) {
								return response()->json( [ 'error' => 'Event not found' ], 404 );
						}

						$calendarItem->title    = $validatedData['title'];
						$calendarItem->start    = ( new DateTimeImmutable( $validatedData['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
						$calendarItem->end      = ( new DateTimeImmutable( $validatedData['end'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
						$calendarItem->location = $validatedData['location'];
						$calendarItem->body     = $validatedData['body'];

						$calendarItem->save();
				} else if ( $calendar->type === 'caldav' ) {
						$fullUrl      = $calendar->custom_fields['url'];
						$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
						$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
						$caldavClient = new CalDAV( $baseUrl, $calendar->custom_fields['username'], $calendar->custom_fields['password'] );

						$start = ( new DateTimeImmutable( $validatedData['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
						$end   = ( new DateTimeImmutable( $validatedData['end'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );

						$response = $caldavClient->updateEvent( $remainingUrl, $validatedData['uid'], $validatedData['title'], $validatedData['body'], $start, $end, $validatedData['location'] );
						$calendar->getExternalContent( true );
				}

				return response()->json( [ 'ok' => true, 'message' => 'Event updated successfully' ] );
		}

		/**
		 * Generate a GUID
		 * @return string
		 */
		private function GUID() {
				if ( function_exists( 'com_create_guid' ) === true ) {
						return trim( com_create_guid(), '{}' );
				}

				return sprintf( '%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 16384, 20479 ), mt_rand( 32768, 49151 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ) );
		}

		/**
		 * Delete an event
		 *
		 * @param Request $request
		 *
		 * @return JsonResponse
		 * @throws Exception
		 */
		public function deleteEvent( Request $request ) {
				$eventId    = $request->input( 'id' );
				$calendarId = $request->input( 'calendarId' );

				$calendar = Calendar::find( $calendarId );
				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				if ( $calendar->type === 'normal' ) {
						$calendarItem = CalendarItem::where( 'id', $eventId )->first();
						if ( ! $calendarItem ) {
								return response()->json( [ 'error' => 'Event not found' ], 404 );
						}
						$calendarItem->delete();
				} else if ( $calendar->type === 'caldav' ) {
						$fullUrl      = $calendar->custom_fields['url'];
						$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
						$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
						$caldavClient = new CalDAV( $baseUrl, $calendar->custom_fields['username'], $calendar->custom_fields['password'] );

						$response = $caldavClient->deleteEvent( $remainingUrl, $eventId );
						$calendar->getExternalContent( true );
				}

				return response()->json( [ 'ok' => true, 'message' => 'Event deleted successfully' ] );
		}

		/**
		 * Create an event
		 *
		 * @param Request $request
		 *
		 * @return JsonResponse
		 * @throws Exception
		 */
		public function createEvent( Request $request ) {
				try {
						$validatedData = $request->validate( [
								'title'      => 'required',
								'start'      => 'required|date',
								'end'        => 'required|date',
								'location'   => 'nullable',
								'body'       => 'nullable',
								'calendarId' => 'required',
						] );
				} catch ( Exception $e ) {
						return response()->json( [ 'error' => $e->getMessage() ], 400 );
				}

				$calendar = Calendar::find( $validatedData['calendarId'] );
				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				$start = ( new DateTimeImmutable( $validatedData['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
				$end   = ( new DateTimeImmutable( $validatedData['end'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
				if ( $start->getTimestamp() === $end->getTimestamp() ) {
						$end = $start->add( new DateInterval( 'P1D' ) )->sub( new DateInterval( 'PT1S' ) );
				}

				$isAllDay = DateTimeRange::isAllDay( $start, $end );

				if ( $calendar->type === 'normal' ) {
						$calendarItem              = new CalendarItem();
						$calendarItem->calendar_id = $validatedData['calendarId'];
						$calendarItem->title       = $validatedData['title'];
						$calendarItem->start       = $start;
						$calendarItem->end         = $end;
						$calendarItem->is_all_day  = $isAllDay;
						$calendarItem->location    = $validatedData['location'] ?? '';
						$calendarItem->body        = $validatedData['body'] ?? '';
						$calendarItem->save();
				} else if ( $calendar->type === 'caldav' ) {
						$fullUrl      = $calendar->custom_fields['url'];
						$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
						$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
						$caldavClient = new CalDAV( $baseUrl, $calendar->custom_fields['username'], $calendar->custom_fields['password'] );

						$uid = $this->GUID();

						$response = $caldavClient->createEvent( $remainingUrl, $uid, $validatedData['title'], $validatedData['body'], $start, $end, $isAllDay, $validatedData['location'] );
						if ( $response['statusCode'] < 200 || $response['statusCode'] > 300 ) {
								return response()->json( [ 'error' => 'Error creating event' ], 500 );
						}
						$calendar->getExternalContent( true );
				}

				return response()->json( [ 'ok' => true, 'message' => 'Event created successfully' ] );
		}

		/**
		 * Create an event from a conversation
		 *
		 * @param int $conversation
		 * @param Request $request
		 *
		 * @return JsonResponse
		 * @throws Exception
		 */
		public function createEventFromConversation( int $conversation, Request $request ) {
				try {
						$validatedData = $request->validate( [
								'title'      => 'required',
								'start'      => 'required|date',
								'end'        => 'required|date|after:start',
								'location'   => 'nullable',
								'body'       => 'nullable',
								'calendarId' => 'required',
						] );
				} catch ( Exception $e ) {
						return response()->json( [ 'error' => $e->getMessage() ], 400 );
				}

				$calendar = Calendar::find( $validatedData['calendarId'] );
				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				$start = ( new DateTimeImmutable( $validatedData['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
				$end   = ( new DateTimeImmutable( $validatedData['end'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
				if ( $start->getTimestamp() === $end->getTimestamp() ) {
						$end = $start->add( new DateInterval( 'P1D' ) )->sub( new DateInterval( 'PT1S' ) );
				}
				$isAllDay = DateTimeRange::isAllDay( $start, $end );

				$uid = null;
				if ( $calendar->type === 'normal' ) {
						$calendarItem              = new CalendarItem();
						$calendarItem->calendar_id = $validatedData['calendarId'];
						$calendarItem->title       = $validatedData['title'];
						$calendarItem->start       = $start;
						$calendarItem->end         = $end;
						$calendarItem->is_all_day  = $isAllDay;
						$calendarItem->location    = $validatedData['location'] ?? '';
						$calendarItem->body        = $validatedData['body'] ?? '';
						$customFields              = $calendarItem->custom_fields;
						if ( ! is_array( $customFields ) ) {
								$customFields = [];
						}
						$customFields['conversation_id'] = $conversation;
						$customFields['author_id']       = auth()->user()->id;
						$calendarItem->custom_fields     = $customFields;
						$calendarItem->save();

						$uid = $calendarItem->id;
				} else if ( $calendar->type === 'caldav' ) {
						$fullUrl      = $calendar->custom_fields['url'];
						$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
						$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
						$caldavClient = new CalDAV( $baseUrl, $calendar->custom_fields['username'], $calendar->custom_fields['password'] );

						$uid = $this->GUID();

						if ( ! is_array( $validatedData['body'] ) ) {
								$validatedData['body'] = [ 'body' => $validatedData['body'] ];
						}
						$validatedData['body']['custom_fields'] = [
								'conversation_id' => $conversation,
								'author_id'       => auth()->user()->id,
						];

						$caldavClient->createEvent( $remainingUrl, $uid, $validatedData['title'], $validatedData['body'], $start, $end, $isAllDay, $validatedData['location'] );
						$calendar->getExternalContent( true );
				}

				if ( $uid !== null ) {
						$conversation = Conversation::find( $conversation );
						if ( $conversation !== null ) {
								$action_type        = CalendarItem::ACTION_TYPE_ADD_TO_CALENDAR;
								$created_by_user_id = auth()->user()->id;
								Thread::create( $conversation, Thread::TYPE_LINEITEM, '', [
										'user_id'            => $conversation->user_id,
										'created_by_user_id' => $created_by_user_id,
										'action_type'        => $action_type,
										'source_via'         => Thread::PERSON_USER,
										'source_type'        => Thread::SOURCE_TYPE_WEB,
										'meta'               => [
												'calendar_item_id' => $uid,
												'calendar_id'      => $calendar->id,
												'start'            => ( new DateTimeImmutable( $validatedData['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
										],
								] );
						}
				}

				return response()->json( [ 'status' => 'success', 'ok' => true, 'message' => 'Event created successfully' ] );
		}

		/**
		 * Create an event from an attachment
		 *
		 * @param Request $request
		 *
		 * @return JsonResponse
		 * @throws Exception
		 */
		public function createEventFromAttachment( Request $request ) {
				try {
						$validatedData = $request->validate( [
								'attachmentId'   => 'required',
								'conversationId' => 'required',
								'calendarId'     => 'required',
						] );
				} catch ( Exception $e ) {
						return response()->json( [ 'error' => $e->getMessage() ], 400 );
				}

				$calendar = Calendar::find( $validatedData['calendarId'] );
				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				$conversation = Conversation::find( $validatedData['conversationId'] );
				if ( ! $conversation ) {
						return response()->json( [ 'error' => 'Conversation not found' ], 404 );
				}

				$attachment = Attachment::find( $validatedData['attachmentId'] );
				if ( ! $attachment ) {
						return response()->json( [ 'error' => 'Attachment not found' ], 404 );
				}
				$file = $attachment->getLocalFilePath();

				$ical = new ICal( $file );
				/** @var Event[] $events */
				$events = $ical->events();

				$refetchCalendarIds = [];

				//add conversation url to body
				$conversationUrl = route( 'conversations.view', [ 'id' => $conversation->id ] );

				foreach ( $events as $event ) {
						$start = DateTimeImmutable::createFromMutable( $ical->iCalDateToDateTime( $event->dtstart_array[3] )->setTimezone( new DateTimeZone( 'UTC' ) ) );
						if ( ! empty( $event->dtend ) ) {
								$end = DateTimeImmutable::createFromMutable( $ical->iCalDateToDateTime( $event->dtend_array[3] )->setTimezone( new DateTimeZone( 'UTC' ) ) );
						} else {
								if ( ! empty( $event->duration ) ) {
										$end = $start->add( new DateInterval( $event->duration ) );
								} else {
										$end = $start->add( new DateInterval( 'PT1H' ) );
								}
						}
						if ( $start->getTimestamp() === $end->getTimestamp() ) {
								$end = $start->add( new DateInterval( 'P1D' ) )->sub( new DateInterval( 'PT1S' ) );
						}
						$isAllDay = DateTimeRange::isAllDay( $start, $end );

						if ( $calendar->type === 'normal' ) {
								$calendarItem              = new CalendarItem();
								$calendarItem->calendar_id = $validatedData['calendarId'];
								$calendarItem->title       = $event->summary;
								$calendarItem->start       = $start;
								$calendarItem->end         = $end;
								$calendarItem->is_all_day  = $isAllDay;
								$calendarItem->location    = $event->location ?? '';
								if ( $conversationUrl !== null ) {
										$calendarItem->body = trim( ( $event->description ?? '' ) . PHP_EOL . PHP_EOL . 'Source: ' . $conversationUrl );
								} else {
										$calendarItem->body = $event->description ?? '';
								}
								$calendarItem->save();

								$uid = $calendarItem->id;
						} else if ( $calendar->type === 'caldav' ) {
								$fullUrl      = $calendar->custom_fields['url'];
								$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
								$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
								$caldavClient = new CalDAV( $baseUrl, $calendar->custom_fields['username'], $calendar->custom_fields['password'] );

								$uid = $event->uid ?? $this->GUID();

								$description = $event->description;
								if ( $conversationUrl !== null ) {
										$description .= PHP_EOL . PHP_EOL . __( 'Source:' ) . ' ' . $conversationUrl;
								}
								$response = $caldavClient->createEvent( $remainingUrl, $uid, $event->summary, $event->description, $start, $end, $isAllDay, $event->location );
								if ( $response['statusCode'] < 200 || $response['statusCode'] > 300 ) {
										return response()->json( [ 'error' => 'Error creating event', $response['body'], 'data' => [ $uid, $event->summary, $description, $start, $end, $event->location ] ], 500 );
								}
								$refetchCalendarIds[] = $calendar->id;
						}

						if ( $uid !== null ) {
								$action_type        = CalendarItem::ACTION_TYPE_ADD_TO_CALENDAR;
								$created_by_user_id = auth()->user()->id;
								Thread::create( $conversation, Thread::TYPE_LINEITEM, '', [
										'user_id'            => $conversation->user_id,
										'created_by_user_id' => $created_by_user_id,
										'action_type'        => $action_type,
										'source_via'         => Thread::PERSON_USER,
										'source_type'        => Thread::SOURCE_TYPE_WEB,
										'meta'               => [
												'calendar_item_id' => $uid,
												'calendar_id'      => $calendar->id,
												'start'            => $start->format( DATE_ATOM ),
										],
								] );
						}
				}

				$refetchCalendarIds = array_values( array_unique( $refetchCalendarIds ) );
				foreach ( $refetchCalendarIds as $refetchCalendarId ) {
						UpdateExternalCalendarJob::dispatch( $refetchCalendarId );
				}

				return response()->json( [ 'status' => 'success', 'ok' => true, 'message' => 'Event created successfully' ] );
		}

}
