<?php

namespace Modules\LJPcCalendarModule\Entities;

use CalDAVClient\Facade\CalDavClient;
use CalDAVClient\Facade\Requests\CalDAVRequestFactory;
use Dallgoot\Yaml\Loader;
use Dallgoot\Yaml\Yaml;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use ICal\Event;
use ICal\ICal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use JsonSerializable;
use Log;
use Modules\LJPcCalendarModule\Http\Helpers\CalDAV;
use Modules\LJPcCalendarModule\Http\Helpers\DateTimeRange;

/**
 * Class Calendar
 *
 * @package Modules\LJPcCalendarModule\Entities
 * @property int $id
 * @property string $name
 * @property bool $enabled
 * @property string $color
 * @property string $type
 * @property array $permissions
 * @property array $custom_fields
 * @property string|null $title_template
 */
class Calendar extends Model implements JsonSerializable {
		protected $table = 'calendars';
		protected $fillable = [
				'name',
				'enabled',
				'color',
				'type',
				'permissions',
				'custom_fields',
				'title_template',
				'sync_version',
				'last_full_sync',
				'force_legacy_mode',
		];

		protected $casts = [
				'enabled'       => 'boolean',
				'permissions'   => 'array',
				'custom_fields' => 'array',
				'force_legacy_mode' => 'boolean',
				'last_full_sync' => 'datetime',
		];

		/**
		 * @var array Cache for parsed ICS data during the current request
		 */
		protected static $icsCache = [];

		public function jsonSerialize(): array {
				return [
						'id'                 => $this->id,
						'name'               => $this->name,
						'enabled'            => $this->enabled,
						'color'              => $this->color,
						'type'               => $this->type,
						'permissions'        => $this->permissions,
						'custom_fields'      => $this->custom_fields,
						'title_template'     => $this->title_template,
						'ics_url'            => url( '/calendar/' . $this->id . '/ics?token=' . md5( $this->id . getenv( 'APP_KEY' ) ) ),
						'obfuscated_ics_url' => url( '/calendar/' . $this->id . '/ics?token=' . md5( $this->id . 'obfuscated' . getenv( 'APP_KEY' ) ) ),
				];
		}

		public function permissionsForCurrentUser(): ?array {
				if ( $this->permissions === null ) {
						return null;
				}
				$permissions = $this->permissions;
				$user        = auth()->user();

				if ( isset( $permissions[ (string) $user->id ] ) ) {
						return $permissions[ (string) $user->id ];
				}

				return null;
		}

		public function getTemporaryFile(): ?string {
				if ( $this->type !== 'ics' && $this->type !== 'caldav' ) {
						return null;
				}

				if ( ! is_dir( storage_path( 'app/ljpccalendarmodule' ) ) ) {
						mkdir( storage_path( 'app/ljpccalendarmodule' ) );
				}

				return storage_path( 'app/ljpccalendarmodule/' . $this->id . '.ics' );
		}

		public function getExternalContent( bool $force = false ): ?string {
				$file = $this->getTemporaryFile();

				if ( $file === null ) {
						return null;
				}

				if ( ! $force && file_exists( $file ) ) {
						return file_get_contents( $file );
				}

				$url = $this->custom_fields['url'] ?? null;
				if ( $url === null ) {
						return null;
				}

				$data = '';
				if ( $this->type === 'ics' ) {
						try {
								$data = file_get_contents( $url );
						} catch ( Exception $e ) {
								return null;
						}
				} else if ( $this->type === 'caldav' ) {
						try {
								$fullUrl      = $this->custom_fields['url'];
								$baseUrl      = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
								$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );

								$caldavClient = new CalDAV( $baseUrl, $this->custom_fields['username'], $this->custom_fields['password'] );
								$data         = implode( '', $caldavClient->getEvents( $remainingUrl ) );
						} catch ( Exception $e ) {
								Log::error( $e->getMessage(), [ 'exception' => $e ] );

								return null;
						}
				}
				file_put_contents( $file, $data );

				return $data;
		}


		/**
		 * @return CalendarItem[]
		 * @throws Exception
		 */
		public function events( DateTimeImmutable $start, DateTimeImmutable $end ): array {
				if ( $this->type === 'normal' ) {
						return json_decode( CalendarItem::where( 'calendar_id', $this->id )->whereBetween( 'start', [ $start, $end ] )->orderBy( 'start' )->get()->toJson(), true );
				} else if ( $this->type === 'ics' ) {
						$daysBeforeToday = $start->diff( new DateTimeImmutable() )->days;
						$daysAfterToday  = $end->diff( new DateTimeImmutable() )->days;

						return $this->getICSAsCalendarItems( $daysBeforeToday, $daysAfterToday );
				} else if ( $this->type === 'caldav' ) {
						$daysBeforeToday = $start->diff( new DateTimeImmutable() )->days;
						$daysAfterToday  = $end->diff( new DateTimeImmutable() )->days;

						return $this->getICSAsCalendarItems( $daysBeforeToday, $daysAfterToday, false );
				}

				return [];
		}

		/**
		 * @return CalendarItem[]
		 * @throws Exception
		 */
		private function getICSAsCalendarItems( int $daysBeforeToday, int $daysAfterToday, bool $readOnly = true ): array {
				$file = $this->getTemporaryFile();
				if ( $file === null ) {
						return [];
				}

				$ical = new ICal( $file, [
						'filterDaysBefore' => max( $daysBeforeToday, 0 ) + 2,
						'filterDaysAfter'  => max( $daysAfterToday, 0 ) + 2,
				] );

				/** @var Event[] $events */
				$events = $ical->events();

				$retArr = [];

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

						$modifiedEnd = $end;
						if ( DateTimeRange::isAllDay( $start, $end ) ) {
								$modifiedEnd = $end->modify( '-1 second' );
						}

						$body         = $event->description;
						$customFields = [];
						try {
								if ( $body !== null ) {
										$parsedResult = Yaml::parse( $body, Loader::IGNORE_COMMENTS | Loader::IGNORE_DIRECTIVES | Loader::NO_OBJECT_FOR_DATE );
										// Handle both array and object results
										if ( is_object( $parsedResult ) && method_exists( $parsedResult, 'jsonSerialize' ) ) {
												$parsedYaml = $parsedResult->jsonSerialize();
										} else {
												$parsedYaml = $parsedResult;
										}
										if ( is_array( $parsedYaml ) && array_has( $parsedYaml, 'body' ) ) {
												$body = $parsedYaml['body'];
												if ( isset( $parsedYaml['custom_fields'] ) ) {
														$customFields = $parsedYaml['custom_fields'];
												}
										}
								}
						} catch ( Exception $e ) {
						}

						$newCalendarItem                = new CalendarItem();
						$newCalendarItem->uid           = $event->uid;
						$newCalendarItem->calendar_id   = $this->id;
						$newCalendarItem->title         = $event->summary;
						$newCalendarItem->location      = $event->location;
						$newCalendarItem->body          = $body;
						$newCalendarItem->state         = $event->status;
						$newCalendarItem->start         = $start;
						$newCalendarItem->end           = $modifiedEnd;
						$newCalendarItem->is_all_day    = DateTimeRange::isAllDay( $start, $end );
						$newCalendarItem->is_private    = false;
						$newCalendarItem->is_read_only  = $readOnly;
						$newCalendarItem->custom_fields = $customFields;

						$retArr[] = json_decode( $newCalendarItem->toJson(), true );
				}

				return $retArr;
		}

		/**
		 * Find a single event by ID without date constraints - optimized for large calendars
		 *
		 * @param string $eventId The event ID to search for
		 * @return array|null The calendar item data or null if not found
		 * @throws Exception
		 */
		public function findEventById( string $eventId ): ?array {
				// Check if we should use legacy mode
				$forceLegacy = config('ljpccalendarmodule.performance.force_legacy_mode', false) || $this->force_legacy_mode;
				$enableEventIndex = config('ljpccalendarmodule.performance.enable_event_index', false);
				$enableCalDAVReports = config('ljpccalendarmodule.performance.enable_caldav_reports', false);
				
				if ( $this->type === 'normal' ) {
						// This case is already handled efficiently in the controller
						return null;
				}

				// Step 1: Try database index if enabled and not in legacy mode
				if ( !$forceLegacy && $enableEventIndex ) {
						try {
								$startTime = microtime(true);
								
								$indexedEvent = CalendarEventIndex::where('calendar_id', $this->id)
										->where('event_uid', $eventId)
										->first();
								
								if ( $indexedEvent ) {
										// Check if the data is fresh based on refresh interval
										$refreshString = $this->custom_fields['refresh'] ?? '1 hour';
										$refreshInterval = $this->getRefreshIntervalInSeconds($refreshString);
										if ( $indexedEvent->isFresh($refreshInterval) ) {
												$indexedEvent->recordAccess();
												
												// Log performance metrics
												if ( config('ljpccalendarmodule.performance.enable_metrics', false) ) {
														Log::info('Calendar event found in index', [
																'calendar_id' => $this->id,
																'event_uid' => $eventId,
																'lookup_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
																'cache_hit' => true
														]);
												}
												
												return $indexedEvent->toCalendarItemArray();
										}
								}
						} catch ( \Exception $e ) {
								Log::error('Error accessing event index', [
										'calendar_id' => $this->id,
										'event_uid' => $eventId,
										'error' => $e->getMessage()
								]);
								// Continue to other methods
						}
				}

				// Step 2: For CalDAV, try REPORT query if enabled
				if ( !$forceLegacy && $this->type === 'caldav' && $enableCalDAVReports ) {
						try {
								$eventData = $this->fetchCalDAVEventByUid( $eventId );
								if ( $eventData !== null ) {
										// Update index if enabled
										if ( $enableEventIndex ) {
												try {
														CalendarEventIndex::updateFromEventData( $this->id, $eventData, $this->sync_version );
												} catch ( \Exception $e ) {
														Log::warning('Failed to update event index after CalDAV fetch', [
																'calendar_id' => $this->id,
																'event_uid' => $eventId,
																'error' => $e->getMessage()
														]);
												}
										}
										
										return $eventData;
								}
						} catch ( \Exception $e ) {
								Log::warning('CalDAV REPORT query failed, falling back to full fetch', [
										'calendar_id' => $this->id,
										'event_uid' => $eventId,
										'error' => $e->getMessage()
								]);
						}
				}

				if ( $this->type === 'ics' || $this->type === 'caldav' ) {
						// Try to get the event from cache first
						$eventCacheKey = 'calendar_event_' . $this->id . '_' . md5( $eventId );
						$cachedEvent = Cache::get( $eventCacheKey );
						
						if ( $cachedEvent !== null ) {
								// Validate that the cached event is still valid by checking if it's in the future or recent past
								if ( isset( $cachedEvent['start'] ) ) {
										$eventStart = new DateTimeImmutable( $cachedEvent['start'] );
										$oneMonthAgo = new DateTimeImmutable( '-1 month' );
										
										// If event is older than 1 month, invalidate cache
										if ( $eventStart < $oneMonthAgo ) {
												Cache::forget( $eventCacheKey );
										} else {
												return $cachedEvent;
										}
								}
						}
						
						$file = $this->getTemporaryFile();
						if ( $file === null ) {
								return null;
						}

						try {
								// Set a reasonable time limit for processing large calendars
								$originalTimeLimit = ini_get( 'max_execution_time' );
								set_time_limit( 60 ); // Increased to 60 seconds for very large calendars
								
								// Check file modification time for cache invalidation
								$fileModTime = filemtime( $file );
								$indexCacheKey = 'calendar_index_' . $this->id . '_' . $fileModTime;
								
								// Try to get event index from cache
								$eventIndex = Cache::get( $indexCacheKey );
								
								if ( $eventIndex !== null && isset( $eventIndex[ $eventId ] ) ) {
										// We have an index, try to parse just the specific event
										// This is a future optimization - for now, we'll still parse all events
								}
								
								// Check file size to warn about large calendars
								$fileSize = filesize( $file );
								if ( $fileSize > 10 * 1024 * 1024 ) { // 10MB
										Log::warning( 'Large calendar file detected: ' . $this->id . ' (' . round( $fileSize / 1024 / 1024, 2 ) . 'MB)' );
								}
								
								// For very large files, use a different strategy
								if ( $fileSize > 50 * 1024 * 1024 ) { // 50MB
										Log::warning( 'Very large calendar file detected, using optimized search: ' . $this->id );
										// For extremely large files, we could implement a streaming parser
										// For now, we'll still use the standard approach but with longer timeout
										set_time_limit( 120 );
								}
								
								// Parse the ICS file
								$ical = new ICal( $file, [
										'filterDaysBefore' => null,  // No date filtering
										'filterDaysAfter'  => null,  // No date filtering
								] );

								/** @var Event[] $events */
								$events = $ical->events();
								
								// Build event index for future cache
								$newEventIndex = [];
								$processedCount = 0;
								$maxEvents = 100000; // Increased safety limit
								
								foreach ( $events as $index => $event ) {
										$processedCount++;
										if ( $processedCount > $maxEvents ) {
												Log::error( 'Event limit exceeded while searching for event in calendar ' . $this->id );
												break;
										}
										
										// Build index entry
										if ( isset( $event->uid ) ) {
												$newEventIndex[ (string) $event->uid ] = $index;
										}
										
										// Check if this is the event we're looking for
										if ( $event->uid === $eventId || 
										     ( isset( $event->uid ) && (string) $event->uid === (string) $eventId ) ) {
												
												// Found the event, convert it to CalendarItem format
												$start = DateTimeImmutable::createFromMutable( 
														$ical->iCalDateToDateTime( $event->dtstart_array[3] )->setTimezone( new DateTimeZone( 'UTC' ) ) 
												);
												
												if ( ! empty( $event->dtend ) ) {
														$end = DateTimeImmutable::createFromMutable( 
																$ical->iCalDateToDateTime( $event->dtend_array[3] )->setTimezone( new DateTimeZone( 'UTC' ) ) 
														);
												} else {
														if ( ! empty( $event->duration ) ) {
																$end = $start->add( new DateInterval( $event->duration ) );
														} else {
																$end = $start->add( new DateInterval( 'PT1H' ) );
														}
												}

												$modifiedEnd = $end;
												if ( DateTimeRange::isAllDay( $start, $end ) ) {
														$modifiedEnd = $end->modify( '-1 second' );
												}

												$body         = $event->description;
												$customFields = [];
												
												try {
														if ( $body !== null ) {
																$parsedResult = Yaml::parse( $body, Loader::IGNORE_COMMENTS | Loader::IGNORE_DIRECTIVES | Loader::NO_OBJECT_FOR_DATE );
																// Handle both array and object results
																if ( is_object( $parsedResult ) && method_exists( $parsedResult, 'jsonSerialize' ) ) {
																		$parsedYaml = $parsedResult->jsonSerialize();
																} else {
																		$parsedYaml = $parsedResult;
																}
																if ( is_array( $parsedYaml ) && array_has( $parsedYaml, 'body' ) ) {
																		$body = $parsedYaml['body'];
																		if ( isset( $parsedYaml['custom_fields'] ) ) {
																				$customFields = $parsedYaml['custom_fields'];
																		}
																}
														}
												} catch ( Exception $e ) {
														// Ignore parsing errors
												}

												$newCalendarItem                = new CalendarItem();
												$newCalendarItem->uid           = $event->uid;
												$newCalendarItem->calendar_id   = $this->id;
												$newCalendarItem->title         = $event->summary;
												$newCalendarItem->location      = $event->location;
												$newCalendarItem->body          = $body;
												$newCalendarItem->state         = $event->status;
												$newCalendarItem->start         = $start;
												$newCalendarItem->end           = $modifiedEnd;
												$newCalendarItem->is_all_day    = DateTimeRange::isAllDay( $start, $end );
												$newCalendarItem->is_private    = false;
												$newCalendarItem->is_read_only  = $this->type === 'ics';
												$newCalendarItem->custom_fields = $customFields;

												$eventData = json_decode( $newCalendarItem->toJson(), true );
												
												// Cache the found event for 24 hours
												Cache::put( $eventCacheKey, $eventData, 60 * 60 * 24 );
												
												// Cache the event index for 1 hour (or until file changes)
												if ( count( $newEventIndex ) < 10000 ) { // Only cache index for reasonably sized calendars
														Cache::put( $indexCacheKey, $newEventIndex, 60 * 60 );
												}
												
												// Restore original time limit
												set_time_limit( $originalTimeLimit );
												
												return $eventData;
										}
								}
								
								// Cache the event index even if event wasn't found (for 1 hour)
								if ( count( $newEventIndex ) < 10000 ) { // Only cache index for reasonably sized calendars
										Cache::put( $indexCacheKey, $newEventIndex, 60 * 60 );
								}
								
								// Restore original time limit
								set_time_limit( $originalTimeLimit );
								
						} catch ( Exception $e ) {
								Log::error( 'Error finding event by ID in calendar ' . $this->id . ': ' . $e->getMessage() );
								// Attempt to restore time limit even on error
								if ( isset( $originalTimeLimit ) ) {
										set_time_limit( $originalTimeLimit );
								}
						}
				}

				return null;
		}

		/**
		 * Fetch a single CalDAV event by UID using REPORT query
		 * 
		 * @param string $eventId The event UID
		 * @return array|null The event data or null if not found
		 */
		protected function fetchCalDAVEventByUid( string $eventId ): ?array {
				$fullUrl = $this->custom_fields['url'];
				$baseUrl = substr( $fullUrl, 0, strpos( $fullUrl, '/', 8 ) );
				$remainingUrl = substr( $fullUrl, strpos( $fullUrl, '/', 8 ) );
				
				$caldavClient = new CalDAV( $baseUrl, $this->custom_fields['username'], $this->custom_fields['password'] );
				
				// Check if server supports REPORT queries
				if ( !$caldavClient->supportsReportQuery( $remainingUrl ) ) {
						Log::info('CalDAV server does not support REPORT queries', [
								'calendar_id' => $this->id,
								'url' => $fullUrl
						]);
						return null;
				}
				
				// Try to fetch the specific event
				$icsData = $caldavClient->getEventByUid( $remainingUrl, $eventId );
				
				if ( $icsData === null ) {
						return null;
				}
				
				// Parse the ICS data
				try {
						$ical = new ICal();
						$ical->initString( $icsData );
						
						$events = $ical->events();
						if ( empty( $events ) ) {
								return null;
						}
						
						// Process the first (and should be only) event
						$event = $events[0];
						
						$start = DateTimeImmutable::createFromMutable( 
								$ical->iCalDateToDateTime( $event->dtstart_array[3] )->setTimezone( new DateTimeZone( 'UTC' ) ) 
						);
						
						if ( ! empty( $event->dtend ) ) {
								$end = DateTimeImmutable::createFromMutable( 
										$ical->iCalDateToDateTime( $event->dtend_array[3] )->setTimezone( new DateTimeZone( 'UTC' ) ) 
								);
						} else {
								if ( ! empty( $event->duration ) ) {
										$end = $start->add( new DateInterval( $event->duration ) );
								} else {
										$end = $start->add( new DateInterval( 'PT1H' ) );
								}
						}

						$modifiedEnd = $end;
						if ( DateTimeRange::isAllDay( $start, $end ) ) {
								$modifiedEnd = $end->modify( '-1 second' );
						}

						$body = $event->description;
						$customFields = [];
						
						// Parse YAML from description if present
						try {
								if ( $body !== null ) {
										$parsedResult = Yaml::parse( $body, Loader::IGNORE_COMMENTS | Loader::IGNORE_DIRECTIVES | Loader::NO_OBJECT_FOR_DATE );
										// Handle both array and object results
										if ( is_object( $parsedResult ) && method_exists( $parsedResult, 'jsonSerialize' ) ) {
												$parsedYaml = $parsedResult->jsonSerialize();
										} else {
												$parsedYaml = $parsedResult;
										}
										if ( is_array( $parsedYaml ) && array_has( $parsedYaml, 'body' ) ) {
												$body = $parsedYaml['body'];
												if ( isset( $parsedYaml['custom_fields'] ) ) {
														$customFields = $parsedYaml['custom_fields'];
												}
										}
								}
						} catch ( Exception $e ) {
								// Ignore parsing errors
						}

						// Create CalendarItem-compatible array
						return [
								'id' => $event->uid,
								'uid' => $event->uid,
								'calendar_id' => $this->id,
								'title' => $event->summary,
								'location' => $event->location,
								'body' => $body,
								'state' => $event->status,
								'start' => $start->format( 'Y-m-d H:i:s' ),
								'end' => $modifiedEnd->format( 'Y-m-d H:i:s' ),
								'is_all_day' => DateTimeRange::isAllDay( $start, $end ),
								'is_private' => false,
								'is_read_only' => false, // CalDAV events can be edited
								'custom_fields' => $customFields,
						];
						
				} catch ( \Exception $e ) {
						Log::error('Failed to parse CalDAV event data', [
								'calendar_id' => $this->id,
								'event_uid' => $eventId,
								'error' => $e->getMessage()
						]);
						return null;
				}
		}

		/**
		 * Convert refresh interval string to seconds
		 * 
		 * @param string $refreshString
		 * @return int
		 */
		protected function getRefreshIntervalInSeconds( string $refreshString ): int {
				$intervals = [
						'1 minute'   => 60,
						'5 minutes'  => 300,
						'15 minutes' => 900,
						'30 minutes' => 1800,
						'1 hour'     => 3600,
						'2 hours'    => 7200,
						'6 hours'    => 21600,
						'12 hours'   => 43200,
						'daily'      => 86400,
				];
				
				return $intervals[$refreshString] ?? 3600; // Default to 1 hour
		}


}
