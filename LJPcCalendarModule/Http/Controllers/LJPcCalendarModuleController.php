<?php

namespace Modules\LJPcCalendarModule\Http\Controllers;

use App\Conversation;
use App\Thread;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
use Kigkonsult\Icalcreator\Vcalendar;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarItem;
use Modules\LJPcCalendarModule\Events\CalendarUpdatedEvent;

class LJPcCalendarModuleController extends Controller {
		/**
		 * Display a listing of the resource.
		 *
		 * @return Factory|Application|View
		 */
		public function index() {
				$allCalendars = [];

				/** @var Calendar[] $calendars */
				$calendars = Calendar::all();
				foreach ( $calendars as $calendar ) {
						if ( ! $calendar->isVisible() ) {
								continue;
						}
						$allCalendars[] = $calendar;
				}

				return view( 'calendar::index', [
						'calendars' => json_encode( $allCalendars ),
				] );
		}

		public function getItems() {
				if ( ! isset( $_GET['start'], $_GET['end'] ) ) {
						return Response::json( [ 'error' => 'Invalid parameters' ], 400 );
				}
				$start = new DateTimeImmutable( date( DATE_ATOM, $_GET['start'] ) );
				$end   = new DateTimeImmutable( date( DATE_ATOM, $_GET['end'] ) );

				$allCalendarItems = [];

				/** @var Calendar[] $calendars */
				$calendars = Calendar::all();
				foreach ( $calendars as $calendar ) {
						if ( ! $calendar->isVisible() ) {
								continue;
						}

						/** @var CalendarItem[] $items */
						$items =
								CalendarItem::where( 'calendar_id', $calendar->id )->where( function ( $q ) use ( $start, $end ) {
										$q->whereBetween( 'start', [ $start, $end ] )->orWhereBetween( 'end', [ $start, $end ] );
								} )->get();
						foreach ( $items as $item ) {
								$allCalendarItems[] = $item;
						}
				}

				return Response::json( $allCalendarItems, 200 );
		}

		public function export( $id ) {
				require __DIR__ . '/../../External/ICalCreator/boot.php';

				$calendarObj = Calendar::find( $id );
				if ( ! ( $calendarObj instanceof Calendar ) ) {
						return response( 'Invalid calendar', 404 )
								->header( 'Content-Type', 'text/plain' );
				}

				if ( ! isset( $_GET['key'] ) || $_GET['key'] !== md5( $calendarObj->id . $calendarObj->created_at ) ) {
						return response( 'Forbidden', 403 )
								->header( 'Content-Type', 'text/plain' );
				}

				$tz = Config::get( 'app.timezone' );

				$calendar = new Vcalendar( [
						Vcalendar::UNIQUE_ID => 'LJPC-FREESCOUT-' . $calendarObj->id,
				] );
				$calendar->setMethod( "PUBLISH" );
				$calendar->setXprop( "x-wr-calname", $calendarObj->name );
				$calendar->setXprop( "X-WR-CALDESC", 'Calendar created by LJPc Calendar Module in FreeScout' );
				$calendar->setXprop( "X-WR-TIMEZONE", $tz );

				$calendarItems = CalendarItem::where( 'calendar_id', $calendarObj->id )->get();

				foreach ( $calendarItems as $calendarItem ) {
						$vevent = $calendar->newVevent();

						$vevent->setDtstart( new DateTime( $calendarItem->start, new DateTimezone( $tz ) ) );
						$vevent->setDtend( new DateTime( $calendarItem->end, new DateTimezone( $tz ) ) );
						if ( ! empty( $calendarItem->location ) ) {
								$vevent->setLocation( $calendarItem->location );
						}
						$vevent->setSummary( $calendarItem->title );
						$vevent->setDescription( $calendarItem->body );
				}

				$calendarString = $calendar
						->vtimezonePopulate()
						->createCalendar();

				return response( $calendarString, 200 )
						->header( 'Content-Type', 'text/Calendar' );
		}

		public function ajax() {
				$data = Input::all();

				if ( ! isset( $data['action'] ) ) {
						return Response::json( [ 'error' => true ], 400 );
				}

				if ( $data['action'] === 'create' ) {
						if ( isset( $data['conversation_id'] ) ) {
								$this->createItem( $data['calendar'], $data['schedule'], (int) $data['conversation_id'] );
						} else {
								$this->createItem( $data['calendar'], $data['schedule'] );
						}
				} else if ( $data['action'] === 'update' ) {
						$this->updateItem( $data['schedule']['id'], $data['changes'] );
				} else if ( $data['action'] === 'delete' ) {
						$this->deleteItem( $data['schedule']['id'] );
				}
				event( new CalendarUpdatedEvent() );

				return Response::json( [ 'status' => 'success' ], 200 );
		}

		private function createItem( array $calendar, array $schedule, int $conversationId = null ) {
				$calendarItem              = new CalendarItem();
				$calendarItem->calendar_id = $calendar['id'];
				$calendarItem->author_id   = Auth::user()->id;
				$calendarItem->is_all_day  = ! ( $schedule['isAllDay'] === 'false' );
				$calendarItem->is_private  = ! ( $schedule['isPrivate'] === 'false' );

				$calendarItem->title    = $schedule['title'];
				$calendarItem->state    = $schedule['state'];
				$calendarItem->location = $schedule['location'];
				$calendarItem->start    = date( DATE_ATOM, $schedule['start'] );
				$calendarItem->end      = date( DATE_ATOM, $schedule['end'] );

				$calendarItem->body =
						__( 'empty' ) . '<br />' . __( 'Created by' ) . ': <img class="avatar" src="' . Auth::user()->getPhotoUrl() . '" alt="' . Auth::user()
						                                                                                                                              ->getFullName() . '">'
						. Auth::user()->getFullName();

				$calendarItem->save();

				if ( $conversationId !== null ) {
						$conversation = Conversation::find( $conversationId );
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
												'calendar_item_id' => $calendarItem->id,
												'calendar_id'      => $calendar['id'],
										],
								] );

								$calendarItem->body = __( 'empty' ) . '<br />' . __( 'Source' ) . ': <a href="' . $conversation->url() . '">#' . $conversation->number . ' '
								                      . $conversation->subject . '</a>' . '<br />' . __( 'Created by' ) . ': ' . Auth::user()->getFullName();
								$calendarItem->save();
						}
				}
		}

		private function updateItem( string $scheduleId, array $changes ) {
				/** @var CalendarItem $calendarItem */
				$calendarItem = CalendarItem::find( $scheduleId );

				$calendarItem->calendar_id = $changes['calendarId'] ?? $calendarItem->calendar_id;
				$calendarItem->author_id   = Auth::user()->id;
				if ( isset( $changes['isAllDay'] ) ) {
						$calendarItem->is_all_day = ! ( $changes['isAllDay'] === 'false' );
				}
				if ( isset( $changes['isPrivate'] ) ) {
						$calendarItem->is_private = ! ( $changes['isPrivate'] === 'false' );
				}

				$calendarItem->title    = $changes['title'] ?? $calendarItem->title;
				$calendarItem->state    = $changes['state'] ?? $calendarItem->state;
				$calendarItem->location = $changes['location'] ?? $calendarItem->location;
				$calendarItem->start    = isset( $changes['start'] ) ? date( DATE_ATOM, $changes['start'] ) : $calendarItem->start;
				$calendarItem->end      = isset( $changes['end'] ) ? date( DATE_ATOM, $changes['end'] ) : $calendarItem->end;

				$calendarItem->save();
		}

		private function deleteItem( string $scheduleId ) {
				/** @var CalendarItem $calendarItem */
				$calendarItem = CalendarItem::find( $scheduleId );
				$calendarItem->delete();
		}
}
