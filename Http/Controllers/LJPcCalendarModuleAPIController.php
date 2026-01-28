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
use Log;
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

				$calendar->name           = $request->input( 'name' );
				$calendar->color          = $request->input( 'color' );
				$calendar->title_template = $request->input( 'title_template' );

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

						if ( $validatedField['type'] === 'source' ) {
								$validatedField['required'] = false;
						}

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

				$calendar->name           = strip_tags($request->input('name') ?? 'Default Calendar');
				$calendar->color          = $request->input( 'color' );
				$calendar->type           = $request->input( 'type' );
				$calendar->title_template = $request->input( 'title_template' );

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
		 * This method handles both date range queries and specific event ID lookups.
		 * For event ID lookups on external calendars, it uses an optimized approach
		 * that avoids loading unnecessary date ranges, improving performance for large calendars.
		 *
		 * @param Request $request The request containing either date range (start/end) or eventId
		 *
		 * @return JsonResponse Array of events matching the criteria
		 * @throws Exception
		 */
		public function getEvents( Request $request ) {
				// Special case: If looking for a specific event by ID
				$eventId = $request->input( 'eventId' );
				if ( $eventId ) {
						try {
								// Check if tables exist first
								if ( ! \Schema::hasTable( 'calendars' ) || ! \Schema::hasTable( 'calendar_items' ) ) {
										return response()->json( [] );
								}

								$events    = [];
								$calendars = Calendar::all();

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

										// For normal calendars, try to find the specific event
										if ( $calendar->type === 'normal' ) {
												$event = CalendarItem::where( 'uid', $eventId )
												                     ->orWhere( 'id', $eventId )
												                     ->where( 'calendar_id', $calendar->id )
												                     ->first();

												if ( $event ) {
														// Found the event, return it
														$eventData = json_decode( $event->toJson(), true );

														// Generate mapping for used custom fields
														if ( isset( $eventData['custom_fields'] ) && is_array( $eventData['custom_fields'] ) ) {
																$eventData['custom_fields_mapping'] = $this->generateCustomFieldMapping(
																		$calendar->custom_fields,
																		$eventData['custom_fields']
																);
														}

														// Set default timezone
														$defaultTimezone    = config( 'app.timezone' );
														$eventData['start'] = ( new DateTimeImmutable( $eventData['start'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
														$eventData['end']   = ( new DateTimeImmutable( $eventData['end'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );

														if ( empty( $eventData['id'] ) && ! empty( $eventData['uid'] ) ) {
																$eventData['id'] = $eventData['uid'];
														}
														if ( empty( $eventData['uid'] ) && ! empty( $eventData['id'] ) ) {
																$eventData['uid'] = $eventData['id'];
														}
														if ( empty( $eventData['title'] ) ) {
																$eventData['title'] = '';
														}

														$events[] = $eventData;
														break;
												}
										}

										// For external calendars, use optimized single event lookup
										if ( $calendar->type === 'ics' || $calendar->type === 'caldav' ) {
												try {
														// Use the new optimized method for finding a single event
														$event = $calendar->findEventById( $eventId );
														
														if ( $event ) {
																// Found the event, prepare it for return
																if ( isset( $event['custom_fields'] ) && is_array( $event['custom_fields'] ) ) {
																		$event['custom_fields_mapping'] = $this->generateCustomFieldMapping(
																				$calendar->custom_fields,
																				$event['custom_fields']
																		);
																}
																$events[] = $event;
																break;
														}
												} catch ( \Exception $e ) {
														// Log and continue - we don't want a single calendar to break the entire request
														\Log::error( 'Error fetching event from external calendar: ' . $e->getMessage() );
												}
										}
								}

								// Restore timezones
								$defaultTimezone = config( 'app.timezone' );
								foreach ( $events as &$event ) {
										$event['start'] = ( new DateTimeImmutable( $event['start'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
										$event['end']   = ( new DateTimeImmutable( $event['end'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
								}
								unset( $event );

								return response()->json( $events );
						} catch ( \Exception $e ) {
								// Log the error but return a valid response
								\Log::error( 'Error in getEvents by ID: ' . $e->getMessage() );

								return response()->json( [] );
						}
				}

				try {
						// Regular date range query
						$request->validate( [
								'start' => 'required|date',
								'end'   => 'required|date',
						] );

						// Check if tables exist first
						if ( ! \Schema::hasTable( 'calendars' ) || ! \Schema::hasTable( 'calendar_items' ) ) {
								return response()->json( [] );
						}

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

								try {
										$calendarEvents = $calendar->events( $start, $end );
										foreach ( $calendarEvents as $event ) {
												// Generate mapping for used custom fields
												if ( isset( $event['custom_fields'] ) && is_array( $event['custom_fields'] ) ) {
														$event['custom_fields_mapping'] = $this->generateCustomFieldMapping(
																$calendar->custom_fields,
																$event['custom_fields']
														);
												}
												$events[] = $event;
										}
								} catch ( \Exception $e ) {
										// Log and continue - we don't want a single calendar to break the entire request
										\Log::error( 'Error fetching events from calendar ' . $calendar->id . ': ' . $e->getMessage() );
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
								if ( empty( $event['title'] ) ) {
										$event['title'] = '';
								}

								$event['start'] = ( new DateTimeImmutable( $event['start'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
								$event['end']   = ( new DateTimeImmutable( $event['end'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
						}
						unset( $event );

						return response()->json( $events );
				} catch ( \Exception $e ) {
						// Log the error but return a valid response
						\Log::error( 'Error in getEvents date range: ' . $e->getMessage() );

						return response()->json( [] );
				}
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
								'uid'          => 'required',
								'title'        => 'required',
								'start'        => 'required|date',
								'end'          => 'required|date|after:start',
								'location'     => 'nullable',
								'body'         => 'nullable',
								'calendarId'   => 'required',
								'customFields' => 'nullable',
						] );
				} catch ( Exception $e ) {
						return response()->json( [ 'error' => $e->getMessage() ], 400 );
				}

				$calendar = Calendar::find( $validatedData['calendarId'] );
				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				$customFieldsData      = $validatedData['customFields'] ?? [];
				$customFields          = $calendar->custom_fields['fields'] ?? [];
				$processedCustomFields = [];

				foreach ( $customFields as $field ) {
						$fieldId = 'custom_field_' . $field['id'];
						if ( isset( $customFieldsData[ $fieldId ] ) ) {
								$processedCustomFields[ $fieldId ] = $customFieldsData[ $fieldId ];
						} else if ( $field['required'] ) {
								return response()->json( [ 'error' => 'Required custom field missing: ' . $field['name'] ], 400 );
						}
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
						if ( ! is_array( $calendarItem->custom_fields ) ) {
								$calendarItem->custom_fields = [];
						}
						$calendarItem->custom_fields = array_merge( $calendarItem->custom_fields, $processedCustomFields );

						$calendarItem->save();
				} else if ( $calendar->type === 'caldav' ) {
						$fullUrl      = $calendar->custom_fields['url'];
						$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
						$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
						$caldavClient = new CalDAV( $baseUrl, $calendar->custom_fields['username'], $calendar->custom_fields['password'] );

						$start = ( new DateTimeImmutable( $validatedData['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
						$end   = ( new DateTimeImmutable( $validatedData['end'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );

						if ( isset( $customFieldsData['author_id'] ) ) {
								$processedCustomFields['author_id'] = $customFieldsData['author_id'];
						}
						if ( isset( $customFieldsData['conversation_id'] ) ) {
								$processedCustomFields['conversation_id'] = $customFieldsData['conversation_id'];
						}

						if ( count( $processedCustomFields ) > 0 ) {
								if ( ! is_array( $validatedData['body'] ) ) {
										$validatedData['body'] = [ 'body' => $validatedData['body'] ];
								}

								if ( ! isset( $validatedData['body']['custom_fields'] ) || ! is_array( $validatedData['body']['custom_fields'] ) ) {
										$validatedData['body']['custom_fields'] = [];
								}

								$validatedData['body']['custom_fields'] = array_merge(
										$validatedData['body']['custom_fields'],
										$processedCustomFields
								);

								// Add mapping for used custom fields
								$validatedData['body']['custom_fields_mapping'] = $this->generateCustomFieldMapping(
										$calendar->custom_fields,
										$processedCustomFields
								);
						}

						$response = $caldavClient->updateEvent(
								$remainingUrl,
								$validatedData['uid'],
								$validatedData['title'],
								$validatedData['body'],
								$start,
								$end,
								$validatedData['location']
						);

						if ( $response['statusCode'] < 200 || $response['statusCode'] > 300 ) {
								Log::error( 'Error updating event', $response );

								return response()->json( [ 'error' => 'Error updating event' ], 500 );
						}

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
						if ( $response['statusCode'] < 200 || $response['statusCode'] > 300 ) {
								Log::error( 'Error deleting event', $response );

								return response()->json( [ 'error' => 'Error deleting event' ], 500 );
						}
						$calendar->getExternalContent( true );
				}

				return response()->json( [ 'ok' => true, 'message' => 'Event deleted successfully' ] );
		}

		/**
		 * Helper function to generate custom field mapping
		 *
		 * @param array $customFields Array of all possible custom fields
		 * @param array $usedCustomFields Array of used custom field values
		 *
		 * @return array Mapping of used custom field IDs to their names
		 */
		private function generateCustomFieldMapping( array $customFields, array $usedCustomFields ): array {
				$mapping = [];
				$fields  = $customFields['fields'] ?? [];

				foreach ( $fields as $field ) {
						$fieldId = 'custom_field_' . $field['id'];
						// Only include fields that are actually used in the item
						if ( isset( $usedCustomFields[ $fieldId ] ) ) {
								$mapping[ $fieldId ] = $field['name'];
						}
				}

				return $mapping;
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
								'title'        => 'required',
								'start'        => 'required|date',
								'end'          => 'required|date',
								'location'     => 'nullable',
								'body'         => 'nullable',
								'calendarId'   => 'required',
								'customFields' => 'nullable',
						] );
				} catch ( Exception $e ) {
						return response()->json( [ 'error' => $e->getMessage() ], 400 );
				}

				$calendar = Calendar::find( $validatedData['calendarId'] );
				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				$customFieldsData      = $validatedData['customFields'] ?? [];
				$customFields          = $calendar->custom_fields['fields'] ?? [];
				$processedCustomFields = [
						'author_id' => auth()->id(),
				];

				foreach ( $customFields as $field ) {
						$fieldId = 'custom_field_' . $field['id'];
						if ( isset( $customFieldsData[ $fieldId ] ) ) {
								$processedCustomFields[ $fieldId ] = $customFieldsData[ $fieldId ];
						} else if ( $field['required'] ) {
								return response()->json( [ 'error' => 'Required custom field missing: ' . $field['name'] ], 400 );
						}
				}

				$start = ( new DateTimeImmutable( $validatedData['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
				$end   = ( new DateTimeImmutable( $validatedData['end'] ) )->setTimezone( new DateTimeZone( 'UTC' ) );
				if ( $start->getTimestamp() === $end->getTimestamp() ) {
						$end = $start->add( new DateInterval( 'P1D' ) )->sub( new DateInterval( 'PT1S' ) );
				}

				$isAllDay = DateTimeRange::isAllDay( $start, $end );

				if ( $calendar->type === 'normal' ) {
$calendarItem = new CalendarItem();

$calendarItem->calendar_id = $validatedData['calendarId'] ?? null;
$calendarItem->author_id   = auth()->id() ?? 1; // default to current user or 1
$calendarItem->title       = $validatedData['title'] ?? 'Untitled Event';
$calendarItem->start       = $start ?? Carbon\Carbon::now();
$calendarItem->end         = $end ?? Carbon\Carbon::now()->addHour();
$calendarItem->is_all_day  = $isAllDay ?? false;
$calendarItem->is_private  = $validatedData['is_private'] ?? false;
$calendarItem->state       = $validatedData['state'] ?? 'active';

$calendarItem->location      = $validatedData['location'] ?? '';
$calendarItem->body          = $validatedData['body'] ?? '';
$calendarItem->custom_fields = $processedCustomFields ?? [];

$calendarItem->save();
				} else if ( $calendar->type === 'caldav' ) {
						$fullUrl      = $calendar->custom_fields['url'];
						$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
						$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
						$caldavClient = new CalDAV( $baseUrl, $calendar->custom_fields['username'], $calendar->custom_fields['password'] );

						$uid = $this->GUID();

						if ( ! is_array( $validatedData['body'] ) ) {
								$validatedData['body'] = [ 'body' => empty( $validatedData['body'] ) ? '-' : $validatedData['body'] ];
						}

						$validatedData['body']['custom_fields'] = $processedCustomFields;

						// Add mapping for used custom fields
						$validatedData['body']['custom_fields_mapping'] = $this->generateCustomFieldMapping(
								$calendar->custom_fields,
								$processedCustomFields
						);

						$response = $caldavClient->createEvent(
								$remainingUrl,
								$uid,
								$validatedData['title'],
								$validatedData['body'],
								$start,
								$end,
								$isAllDay,
								$validatedData['location']
						);

						if ( $response['statusCode'] < 200 || $response['statusCode'] > 300 ) {
								Log::error( 'Error creating event', $response );

								return response()->json( [ 'error' => 'Error creating event' ], 500 );
						}

						$calendar->getExternalContent( true );
				}

				return response()->json( [ 'ok' => true, 'message' => 'Event created successfully' ] );
		}

		private function processTemplate(
    string $template,
    ?Conversation $conversation,
    Calendar $calendar,
    array $customFields = []
): string {
    if (!$conversation) {
        return $template;
    }

    $result = str_replace('{{title}}', $conversation->subject, $template);

    // Get the field name mapping from calendar's custom fields configuration
    $fieldMapping = [];

    if (!empty($calendar->custom_fields['fields'])) {
        foreach ($calendar->custom_fields['fields'] as $field) {
            $fieldMapping['custom_field_' . $field['id']] = $field['name'];
        }
    }

    // Process custom fields using the mapping
    foreach ($customFields as $fieldId => $value) {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }

        if (isset($fieldMapping[$fieldId])) {
            $fieldName = $fieldMapping[$fieldId];
            $result = str_replace('{{' . $fieldName . '}}', $value ?? '', $result);
        }
    }

    return $result;
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
								'title'        => 'required|nullable',
								'start'        => 'required|date',
								'end'          => 'required|date|after:start',
								'location'     => 'nullable',
								'body'         => 'nullable',
								'calendarId'   => 'required',
								'customFields' => 'nullable',
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

				$customFieldsData      = $validatedData['customFields'] ?? [];
				$customFields          = $calendar->custom_fields['fields'] ?? [];
				$processedCustomFields = [];

				foreach ( $customFields as $field ) {
						$fieldId = 'custom_field_' . $field['id'];
						if ( isset( $customFieldsData[ $fieldId ] ) ) {
								$processedCustomFields[ $fieldId ] = $customFieldsData[ $fieldId ];
						} else if ( $field['required'] ) {
								return response()->json( [ 'error' => 'Required custom field missing: ' . $field['name'] ], 400 );
						}
				}

				if ( empty( $calendar->title_template ) ) {
						$calendar->title_template = $request->input( 'title' );
				}

				$uid = null;
				if ( $calendar->type === 'normal' ) {
						$calendarItem = new CalendarItem();

$calendarItem->calendar_id = $validatedData['calendarId'] ?? null;
$calendarItem->author_id   = auth()->id() ?? 1;
$calendarItem->title       = $this->processTemplate(
    $calendar->title_template,
    isset($conversation) && $conversation instanceof App\Conversation ? $conversation : null,
    $calendar,
    $processedCustomFields ?? []
);
$calendarItem->start      = $start ?? Carbon\Carbon::now();
$calendarItem->end        = $end ?? Carbon\Carbon::now()->addHour();
$calendarItem->is_all_day = $isAllDay ?? false;
$calendarItem->is_private = false;
$calendarItem->state      = 'active';
$calendarItem->location   = $validatedData['location'] ?? '';
$calendarItem->body       = $validatedData['body'] ?? '';

// Merge all custom fields safely
$customFields = $calendarItem->custom_fields;
if (!is_array($customFields)) {
    $customFields = [];
}

$mergedCustomFields = array_merge(
    $customFields,
    [
        'conversation_id' => isset($conversation) ? ($conversation->id ?? $conversation) : null,
        'author_id'       => auth()->id() ?? 1,
    ],
    $processedCustomFields ?? []
);

$calendarItem->custom_fields = $mergedCustomFields;

$calendarItem->save();
						$uid = $calendarItem->id;
				} else if ( $calendar->type === 'caldav' ) {
						$fullUrl      = $calendar->custom_fields['url'];
						$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
						$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
						$caldavClient = new CalDAV( $baseUrl, $calendar->custom_fields['username'], $calendar->custom_fields['password'] );

						$uid = $this->GUID();

						if ( ! is_array( $validatedData['body'] ) ) {
								$validatedData['body'] = [ 'body' => empty( $validatedData['body'] ) ? '-' : $validatedData['body'] ];
						}

						// Merge all custom fields
						$mergedCustomFields = array_merge( [
								'conversation_id' => $conversation,
								'author_id'       => auth()->user()->id,
						], $processedCustomFields );

						$validatedData['body']['custom_fields'] = $mergedCustomFields;

						// Add mapping for used custom fields
						$validatedData['body']['custom_fields_mapping'] = $this->generateCustomFieldMapping(
								$calendar->custom_fields,
								$mergedCustomFields
						);

						$response = $caldavClient->createEvent(
								$remainingUrl,
								$uid,
								$this->processTemplate( $calendar->title_template, Conversation::find( $conversation ), $calendar, $processedCustomFields ),
								$validatedData['body'],
								$start,
								$end,
								$isAllDay,
								$validatedData['location']
						);

						if ( $response['statusCode'] < 200 || $response['statusCode'] > 300 ) {
								Log::error( 'Error creating event', $response );

								return response()->json( [ 'error' => 'Error creating event' ], 500 );
						}

						$calendar->getExternalContent( true );
				}

				if ( $uid !== null ) {
						$conversation = Conversation::find( $conversation );
						if ( $conversation !== null ) {
								$action_type        = CalendarItem::ACTION_TYPE_ADD_TO_CALENDAR;
								$created_by_user_id = auth()->user()->id;
								
								// Store the event UID for better permalink support
								$meta = [
										'calendar_item_id' => $uid,
										'calendar_id'      => $calendar->id,
										'calendar_type'    => $calendar->type,
										'start'            => ( new DateTimeImmutable( $validatedData['start'] ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
								];
								
								// For external calendars, also store the event UID
								if ( $calendar->type === 'caldav' || $calendar->type === 'ics' ) {
										$meta['event_uid'] = $uid;
								}
								
								Thread::create( $conversation, Thread::TYPE_LINEITEM, '', [
										'user_id'            => $conversation->user_id,
										'created_by_user_id' => $created_by_user_id,
										'action_type'        => $action_type,
										'source_via'         => Thread::PERSON_USER,
										'source_type'        => Thread::SOURCE_TYPE_WEB,
										'meta'               => $meta,
								] );
						}
				}

				return response()->json( [ 'status' => 'success', 'ok' => true, 'message' => 'Event created successfully' ] );
		}

		/**
		 * Get a calendar
		 *
		 * @param int $id
		 *
		 * @return JsonResponse
		 */
		public function getCalendar( $id ) {
				$calendar = Calendar::find( $id );
				if ( ! $calendar ) {
						return response()->json( [ 'error' => 'Calendar not found' ], 404 );
				}

				return response()->json( $calendar );
		}

		/**
		 * Get a single event by ID - optimized endpoint for permalinks
		 *
		 * @param string $eventId The event ID to retrieve
		 *
		 * @return JsonResponse
		 */
		public function getEventById( string $eventId ): JsonResponse {
				try {
						// Check if tables exist first
						if ( ! \Schema::hasTable( 'calendars' ) || ! \Schema::hasTable( 'calendar_items' ) ) {
								return response()->json( [ 'error' => 'Calendar module not properly initialized' ], 500 );
						}

						$calendars = Calendar::all();
						
						foreach ( $calendars as $calendar ) {
								if ( $calendar->enabled === false ) {
										continue;
								}
								
								$permissions = $calendar->permissionsForCurrentUser();
								if ( $permissions === null || ! $permissions['showInCalendar'] ) {
										continue;
								}

								// For normal calendars, try to find the specific event by ID only
								if ( $calendar->type === 'normal' ) {
										$event = CalendarItem::where( 'calendar_id', $calendar->id )
										                     ->where( 'id', $eventId )
										                     ->first();

										if ( $event ) {
												$eventData = json_decode( $event->toJson(), true );
												
												// Ensure calendar ID is present
												$eventData['calendarId'] = $calendar->id;

												// Generate mapping for used custom fields
												if ( isset( $eventData['custom_fields'] ) && is_array( $eventData['custom_fields'] ) ) {
														$eventData['custom_fields_mapping'] = $this->generateCustomFieldMapping(
																$calendar->custom_fields,
																$eventData['custom_fields']
														);
												}

												// Set default timezone
												$defaultTimezone    = config( 'app.timezone' );
												$eventData['start'] = ( new DateTimeImmutable( $eventData['start'], new DateTimeZone( 'UTC' ) ) )
														->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
												$eventData['end']   = ( new DateTimeImmutable( $eventData['end'], new DateTimeZone( 'UTC' ) ) )
														->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );

												// Ensure IDs are set
												if ( empty( $eventData['id'] ) && ! empty( $eventData['uid'] ) ) {
														$eventData['id'] = $eventData['uid'];
												}
												if ( empty( $eventData['uid'] ) && ! empty( $eventData['id'] ) ) {
														$eventData['uid'] = $eventData['id'];
												}
												if ( empty( $eventData['title'] ) ) {
														$eventData['title'] = '';
												}

												return response()->json( $eventData );
										}
								}

								// For external calendars, use optimized single event lookup
								if ( $calendar->type === 'ics' || $calendar->type === 'caldav' ) {
										try {
												$event = $calendar->findEventById( $eventId );
												
												if ( $event ) {
														// Add calendar ID to the event data
														$event['calendarId'] = $calendar->id;
														$event['calendar_id'] = $calendar->id;
														
														// Generate mapping for used custom fields
														if ( isset( $event['custom_fields'] ) && is_array( $event['custom_fields'] ) ) {
																$event['custom_fields_mapping'] = $this->generateCustomFieldMapping(
																		$calendar->custom_fields,
																		$event['custom_fields']
																);
														}
														
														// Ensure timezone conversion is done
														$defaultTimezone = config( 'app.timezone' );
														$event['start'] = ( new DateTimeImmutable( $event['start'], new DateTimeZone( 'UTC' ) ) )
																->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
														$event['end']   = ( new DateTimeImmutable( $event['end'], new DateTimeZone( 'UTC' ) ) )
																->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'Y-m-d H:i:s' );
														
														return response()->json( $event );
												}
										} catch ( \Exception $e ) {
												// Log and continue checking other calendars
												\Log::error( 'Error fetching event from external calendar: ' . $e->getMessage() );
										}
								}
						}

						// Event not found in any calendar
						return response()->json( [ 'error' => 'Event not found' ], 404 );
						
				} catch ( \Exception $e ) {
						\Log::error( 'Error in getEventById: ' . $e->getMessage() );
						return response()->json( [ 'error' => 'Failed to retrieve event' ], 500 );
				}
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
								$calendarItem = new CalendarItem();

$calendarItem->calendar_id = $validatedData['calendarId'] ?? null;
$calendarItem->author_id   = auth()->id() ?? 1;
$calendarItem->title       = $event->summary ?? 'Untitled Event';
$calendarItem->start       = $start ?? Carbon\Carbon::now();
$calendarItem->end         = $end ?? Carbon\Carbon::now()->addHour();
$calendarItem->is_all_day  = $isAllDay ?? false;
$calendarItem->location    = $event->location ?? '';
$calendarItem->body        = $event->description ?? '';

// Merge custom fields safely
$customFields = $calendarItem->custom_fields;
if (!is_array($customFields)) {
    $customFields = [];
}

$customFields['conversation_id'] = isset($conversation) ? ($conversation->id ?? $conversation) : null;
$customFields['author_id']       = auth()->id() ?? 1;

$calendarItem->custom_fields = $customFields;

$calendarItem->save();
								$uid = $calendarItem->id;
						} else if ( $calendar->type === 'caldav' ) {
								$fullUrl      = $calendar->custom_fields['url'];
								$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
								$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
								$caldavClient = new CalDAV( $baseUrl, $calendar->custom_fields['username'], $calendar->custom_fields['password'] );

								$uid = $event->uid ?? $this->GUID();

								$description = [
										'body'          => empty( $event->description ) ? '-' : $event->description,
										'custom_fields' => [
												'conversation_id' => $conversation->id,
												'author_id'       => auth()->user()->id,
										],
								];

								$response = $caldavClient->createEvent( $remainingUrl, $uid, $event->summary, $description, $start, $end, $isAllDay, $event->location );
								if ( $response['statusCode'] < 200 || $response['statusCode'] > 300 ) {
										Log::error( 'Error creating event', $response );

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
