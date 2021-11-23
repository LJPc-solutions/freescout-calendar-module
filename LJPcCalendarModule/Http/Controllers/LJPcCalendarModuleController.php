<?php

namespace Modules\LJPcCalendarModule\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
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
		$start = date( DATE_ATOM, $_GET['start'] );
		$end   = date( DATE_ATOM, $_GET['end'] );

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

	public function ajax() {
		$data = Input::all();

		if ( ! isset( $data['action'] ) ) {
			return Response::json( [ 'error' => true ], 400 );
		}

		if ( $data['action'] === 'create' ) {
			$this->createItem( $data['calendar'], $data['schedule'] );
		} elseif ( $data['action'] === 'update' ) {
			$this->updateItem( $data['schedule']['id'], $data['changes'] );
		} elseif ( $data['action'] === 'delete' ) {
			$this->deleteItem( $data['schedule']['id'] );
		}
		event( new CalendarUpdatedEvent() );

		return Response::json( [ 'status' => 'success' ], 200 );
	}

	private function createItem( array $calendar, array $schedule ) {
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

		$calendarItem->save();
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
