<?php

namespace Modules\LJPcCalendarModule\Providers;

use Config;
use Eventy;
use Helper;
use Illuminate\Support\ServiceProvider;
use Module;
use Modules\LJPcCalendarModule\Console\SyncICS;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarItem;
use Modules\LJPcCalendarModule\External\ICal\ICal;
use View;

define( 'LJPC_CALENDARS_MODULE', 'ljpccalendarmodule' );

class LJPcCalendarModuleServiceProvider extends ServiceProvider {
	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Boot the application events.
	 *
	 * @return void
	 */
	public function boot() {
		$this->registerConfig();
		$this->registerViews();
		$this->registerCommands();
		$this->loadMigrationsFrom( __DIR__ . '/../Database/Migrations' );
		$this->hooks();
	}

	/**
	 * Register config.
	 *
	 * @return void
	 */
	protected function registerConfig() {
		$this->publishes( [
			__DIR__ . '/../Config/config.php' => config_path( 'ljpccalendarmodule.php' ),
		], 'config' );
		$this->mergeConfigFrom(
			__DIR__ . '/../Config/config.php', 'ljpccalendarmodule'
		);
	}

	/**
	 * Register views.
	 *
	 * @return void
	 */
	public function registerViews() {
		$viewPath = resource_path( 'views/modules/ljpccalendarmodule' );

		$sourcePath = __DIR__ . '/../Resources/views';

		$this->publishes( [
			$sourcePath => $viewPath,
		], 'views' );

		$this->loadViewsFrom( array_merge( array_map( function ( $path ) {
			return $path . '/modules/ljpccalendarmodule';
		}, Config::get( 'view.paths' ) ), [ $sourcePath ] ), 'calendar' );
	}

	/**
	 * Artisan commands
	 */
	public function registerCommands() {
		$this->commands( [
			SyncICS::class,
		] );
	}

	/**
	 * Module hooks.
	 */
	public function hooks() {
		// Add item to the mailbox menu
		Eventy::addAction( 'menu.append', function ( $mailbox ) {
			echo View::make( 'calendar::partials/menu', [] )->render();
		} );

		Eventy::addFilter( 'menu.selected', function ( $menu ) {
			if ( auth()->user() && auth()->user()->isAdmin() ) {
				$menu['calendar'] = [
					'ljpccalendarmodule.index',
				];
			}

			return $menu;
		} );

		Eventy::addFilter( 'dashboard.after', function () {
			$allCalendars = [];

			/** @var Calendar[] $calendars */
			$calendars = Calendar::all();
			foreach ( $calendars as $calendar ) {
				if ( ! $calendar->isVisible() ) {
					continue;
				}
				$allCalendars[] = $calendar;
			}

			return View::make( 'calendar::partials/dash_card', [ 'calendars' => json_encode( $allCalendars ) ] )->render();
		} );

		//Fetch ICS every minutes
		Eventy::addFilter( 'schedule', function ( $schedule ) {
			$schedule->command( 'calendar:sync-ics' )->everyMinute();

			return $schedule;
		} );

		Eventy::addAction( 'conversation.action_buttons', function ( $conversation, $mailbox ) {
			$allCalendars = [];
			foreach ( Calendar::all() as $calendar ) {
				/* @var Calendar $calendar */
				if ( $calendar->isVisible() && ! $calendar->isExternal() ) {
					$allCalendars[] = $calendar;
				}
			}
			echo View::make( 'calendar::partials/conversation_button', [ 'calendars' => $allCalendars, 'conversation' => $conversation ] )->render();
		}, 10, 2 );

		Eventy::addFilter( 'thread.action_text', function ( $did_this, $thread, $conversation_number, $escape ) {
			if ( $thread->action_type !== CalendarItem::ACTION_TYPE_ADD_TO_CALENDAR ) {
				return $did_this;
			}
			$meta = $thread->getMetas();

			$calendar     = Calendar::find( (int) $meta['calendar_id'] );
			$calendarItem = CalendarItem::find( (int) $meta['calendar_item_id'] );
			$person       = $thread->getActionPerson( $conversation_number );

			if ( $calendar === null ) {
				$did_this = __( 'Added to a calendar, but the calendar has been deleted by now.' );
			} elseif ( $calendarItem === null ) {
				$did_this = __( 'Added to calendar ":calendar" by :person, but deleted by now.', [ 'calendar' => $calendar->name, 'person' => $person ] );
			} else {
				$did_this = __( 'Added to calendar ":calendar" by :person at :start.',
					[ 'calendar' => $calendar->name, 'person' => $person, 'start' => date( __( 'd-m-Y H:i' ), strtotime( $calendarItem->start ) ) ] );
			}

			return $did_this;
		}, 20, 4 );

		$this->registerSettings();
	}

	private function registerSettings() {
		// Add item to settings sections.
		Eventy::addFilter( 'settings.sections', function ( $sections ) {
			$sections['calendar'] = [ 'title' => __( 'Calendar' ), 'icon' => 'calendar', 'order' => 200 ];

			return $sections;
		}, 15 );

		// Section settings
		Eventy::addFilter( 'settings.section_settings', function ( $settings, $section ) {
			if ( $section !== 'calendar' ) {
				return $settings;
			}

			$settings['calendar_list'] = base64_decode( config( 'ljpccalendarmodule.calendar_list' ) );

			return $settings;
		}, 20, 2 );

		// Section parameters.
		Eventy::addFilter( 'settings.section_params', function ( $params, $section ) {
			if ( $section !== 'calendar' ) {
				return $params;
			}

			$params['settings'] = [
				'calendar_list' => [
					'env' => 'CALENDAR_LIST',
				],
			];

			return $params;
		}, 20, 2 );

		// Settings view name
		Eventy::addFilter( 'settings.view', function ( $view, $section ) {
			if ( $section !== 'calendar' ) {
				return $view;
			}

			return 'calendar::settings';
		}, 20, 2 );

		Eventy::addFilter( 'settings.before_save', function ( $request, $section, $settings ) {
			if ( $section !== 'calendar' ) {
				return $request;
			}

			$calendarList = str_replace( "\r", '', $request->settings['calendar_list'] );
			$allCalendars = explode( "\n", $calendarList );
			$allCalendars = array_values( array_unique( $allCalendars ) );

			$new_settings = [
				'calendar_list' => base64_encode( implode( "\n", $allCalendars ) ),
			];

			foreach ( $allCalendars as $calendar ) {
				$url = filter_var( $calendar, FILTER_SANITIZE_URL );

				if ( filter_var( $url, FILTER_VALIDATE_URL ) !== false ) {
					$ics  = new ICal( $url );
					$name = $ics->calendarName();
					if ( Calendar::where( 'name', $name )->where( 'url', '<>', $url )->count() > 0 ) {
						$name = '[ICS] ' . $name;
					}
					if ( Calendar::where( 'name', $name )->count() === 0 ) {
						$insertCalendar       = new Calendar();
						$insertCalendar->name = $name;
						$insertCalendar->url  = $url;
						$insertCalendar->save();
					}
				} elseif ( Calendar::where( 'name', $calendar )->count() === 0 ) {
					$insertCalendar       = new Calendar();
					$insertCalendar->name = $calendar;
					$insertCalendar->save();
				}
			}

			$request->merge( [ 'settings' => array_merge( $request->settings ?? [], $new_settings ) ] );

			return $request;
		}, 20, 3 );
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register() {
		$this->registerTranslations();
	}

	/**
	 * Register translations.
	 *
	 * @return void
	 */
	public function registerTranslations() {
		$this->loadJsonTranslationsFrom( __DIR__ . '/../Resources/lang' );
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides() {
		return [];
	}
}
