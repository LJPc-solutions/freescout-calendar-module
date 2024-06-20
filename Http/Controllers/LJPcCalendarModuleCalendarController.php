<?php

namespace Modules\LJPcCalendarModule\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarItem;
use Spatie\IcalendarGenerator\Components\Event;

class LJPcCalendarModuleCalendarController extends Controller {
		public function index() {
				$calendars = Calendar::all();

				$authorizedCalendars = [];
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

						$authorizedCalendars[] = [
								'id'              => $calendar->id,
								'name'            => $calendar->name,
								'backgroundColor' => $calendar->color,
								'type'            => $calendar->type,
								'permissions'     => $permissions,
						];
				}

				return view( 'calendar::index', [
						'calendars' => $authorizedCalendars,
				] );
		}

		public function getAsICS( int $id, Request $request ) {
				$requiredTokenNormal     = md5( $id . getenv( 'APP_KEY' ) );
				$requiredTokenObfuscated = md5( $id . 'obfuscated' . getenv( 'APP_KEY' ) );
				if ( ! $request->has( 'token' ) || ( $requiredTokenNormal !== $request->get( 'token' ) && $requiredTokenObfuscated !== $request->get( 'token' ) ) ) {
						abort( 403 );
				}

				$isObfuscated = $requiredTokenObfuscated === $request->get( 'token' );

				/** @var Calendar|null $calendar */
				$calendar = Calendar::find( $id );
				if ( $calendar === null ) {
						abort( 404 );
				}

				if ( $calendar->type === 'ics' || $calendar->type === 'caldav' ) {
						$data = $calendar->getExternalContent();
				}

				if ( $calendar->type === 'normal' ) {
						$events = json_decode( CalendarItem::where( 'calendar_id', $calendar->id )->get()->toJson(), true );
						$ics    = \Spatie\IcalendarGenerator\Components\Calendar::create( $calendar->name );

						foreach ( $events as $event ) {
								if ( $isObfuscated ) {
										$ics->event( Event::create()
										                  ->name( 'Unavailable' )
										                  ->description( '' )
										                  ->startsAt( new DateTimeImmutable( $event['start'] ) )
										                  ->endsAt( new DateTimeImmutable( $event['end'] ) )
										                  ->address( '' ) );
								} else {
										$ics->event( Event::create()
										                  ->name( $event['title'] ?? '' )
										                  ->description( $event['body'] ?? '' )
										                  ->startsAt( new DateTimeImmutable( $event['start'] ) )
										                  ->endsAt( new DateTimeImmutable( $event['end'] ) )
										                  ->address( $event['location'] ?? '' ) );
								}
						}

						$data = $ics->get();

				}

				return response( $data )
						->header( 'Content-Type', 'text/calendar' )
						->header( 'Content-Disposition', 'attachment; filename="' . $calendar->name . '.ics"' );

		}

}
