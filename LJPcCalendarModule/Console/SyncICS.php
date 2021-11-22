<?php

namespace Modules\LJPcCalendarModule\Console;

use Exception;
use Illuminate\Console\Command;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarItem;
use Modules\LJPcCalendarModule\Events\CalendarUpdatedEvent;
use Modules\LJPcCalendarModule\External\ICal\ICal;
use stdClass;
use Throwable;

class SyncICS extends Command {
	/**
	 * The signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'calendar:sync-ics';
	/**
	 * The description of the console command.
	 *
	 * @var string
	 */
	protected $description = 'Syncs the calendar items in ICS files';

	/**
	 * Execute the console command.
	 *
	 * @return void
	 */
	public function handle() {
		$this->info( "Starting ICS sync..." );

		$calendars = Calendar::all();
		foreach ( $calendars as $calendar ) {
			/* @var Calendar $calendar */
			if ( ! $calendar->isExternal() ) {
				continue;
			}

			try {
				$url = $calendar->url;

				$ics    = new ICal( $url );
				$events = $ics->events();
				if ( count( $events ) === 0 ) {
					throw new Exception( 'No events in ICal, skipping...' );
				}

				$hashEventList = [];

				foreach ( $events as $event ) {
					$start = $ics->iCalDateToDateTime( $event->dtstart_array[3] );
					$end   = $ics->iCalDateToDateTime( $event->dtend_array[3] );

					$calendarItem               = new stdClass();
					$calendarItem->calendar_id  = $calendar->id;
					$calendarItem->author_id    = 0;
					$calendarItem->is_all_day   = $start->format( 'H:i' ) === '00:00' && $end->format( 'H:i' ) === '00:00';
					$calendarItem->is_private   = false;
					$calendarItem->is_read_only = true;
					$calendarItem->title        = $event->summary;
					$calendarItem->body         = $event->description ?? '';
					$calendarItem->location     = $event->location;
					$calendarItem->state        = __( 'Busy' );
					$calendarItem->start        = $start;
					$calendarItem->end          = $end;

					$hashEventList[] = $calendarItem;
				}

				$dataHash = md5( serialize( $hashEventList ) );
				if ( $dataHash === $calendar->synchronization_token ) {
					throw new Exception( 'Calendar has not changed since last sync' );
				}
				$calendar->synchronization_token = $dataHash;
				$calendar->save();

				//Removing all entries to be sure that all deleted items are also deleted in the database
				CalendarItem::where( 'calendar_id', $calendar->id )->delete();

				foreach ( $events as $event ) {
					$start = $ics->iCalDateToDateTime( $event->dtstart_array[3] );
					$end   = $ics->iCalDateToDateTime( $event->dtend_array[3] );

					$calendarItem               = new CalendarItem();
					$calendarItem->calendar_id  = $calendar->id;
					$calendarItem->author_id    = 0;
					$calendarItem->is_all_day   = $start->format( 'H:i' ) === '00:00' && $end->format( 'H:i' ) === '00:00';
					$calendarItem->is_private   = false;
					$calendarItem->is_read_only = true;
					$calendarItem->title        = $event->summary;
					$calendarItem->body         = $event->description ?? '';
					$calendarItem->location     = $event->location;
					$calendarItem->state        = __( 'Busy' );
					$calendarItem->start        = $start;
					$calendarItem->end          = $end;
					$calendarItem->save();
				}
				event( new CalendarUpdatedEvent() );
			} catch ( Throwable $e ) {
				$this->error( $e->getMessage() );
			}
		}

		$this->info( "Finished" );
	}
}
