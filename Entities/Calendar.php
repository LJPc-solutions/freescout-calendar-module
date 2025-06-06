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
		];

		protected $casts = [
				'enabled'       => 'boolean',
				'permissions'   => 'array',
				'custom_fields' => 'array',
		];

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


}
