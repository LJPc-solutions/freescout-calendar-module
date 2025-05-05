<?php

namespace Modules\LJPcCalendarModule\Providers;

use App\Attachment;
use Carbon\Carbon;
use Config;
use DateTimeImmutable;
use DateTimeZone;
use Eventy;
use Illuminate\Support\ServiceProvider;
use Module;
use Modules\LJPcCalendarModule\Console\UpdateExternalCalendars;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarItem;
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
				require_once __DIR__ . '/../vendor/autoload.php';

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
						UpdateExternalCalendars::class,
				] );
		}

		/**
		 * Module hooks.
		 */
		public function hooks() {
				Eventy::addFilter( 'javascripts', function ( $javascripts ) {
						$javascripts[] = Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/js/laroute.js';
						$javascripts[] = Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/js/general.js';

						return $javascripts;
				} );

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

				Eventy::addFilter( 'dashboard.before', function () {
						$defaultTimezone = config( 'app.timezone' );

						/** @var Calendar[] $calendars */
						$calendars   = Calendar::all();
						$events      = [];
						$eventSorter = [];
						foreach ( $calendars as $calendar ) {
								if ( ! $calendar->enabled ) {
										continue;
								}
								$permissions = $calendar->permissionsForCurrentUser();
								if ( $permissions === null || ! ( $permissions['showInDashboard'] ?? false ) ) {
										continue;
								}
								$calendarEvents = $calendar->events( new DateTimeImmutable(), new DateTimeImmutable( '+1 week' ) );
								foreach ( $calendarEvents as $event ) {
										//discard if end is in the past
										if ( new DateTimeImmutable( $event['end'] ) < new DateTimeImmutable() ) {
												continue;
										}

										$start         = new Carbon( $event['start'] );
										$events[]      = [
												'title'    => $event['title'],
												'start'    => [
														'time' => $start->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( 'H:i' ),
														'diff' => $start->setTimezone( new DateTimeZone( $defaultTimezone ) )->diffForHumans(),
														'full' => $start->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( __( 'Y-m-d H:i' ) ),
												],
												'calendar' => [
														'name'  => $calendar['name'],
														'id'    => $calendar['id'],
														'color' => $calendar['color'],
												],
										];
										$eventSorter[] = ( new DateTimeImmutable( $event['start'] ) )->getTimestamp();
								}
						}
						array_multisort( $eventSorter, $events );

						//get first 10 events
						$events = array_slice( $events, 0, 3 );

						if ( count( $events ) === 0 ) {
								return '';
						}

						return View::make( 'calendar::partials/dash_card', [ 'events' => $events ] )->render();
				} );

				Eventy::addFilter( 'schedule', function ( $schedule ) {
						$schedule->command( UpdateExternalCalendars::class )->everyMinute();

						return $schedule;
				} );

				Eventy::addAction( 'conversation.action_buttons', function ( $conversation, $mailbox ) {
						$allCalendars = [];
						foreach ( Calendar::all() as $calendar ) {
								/* @var Calendar $calendar */
								if ( $calendar->type !== 'caldav' && $calendar->type !== 'normal' ) {
										continue;
								}

								if ( ! $calendar->enabled ) {
										continue;
								}

								$permissions = $calendar->permissionsForCurrentUser();
								if ( $permissions !== null && $permissions['createItems'] ) {
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

						$calendar = Calendar::find( (int) $meta['calendar_id'] );
						if ( $calendar === null ) {
								return __( 'Added to a calendar, but the calendar has been deleted by now.' );
						}
						if ( ! isset( $meta['start'] ) ) {
								return __( 'Added to calendar ":calendar".', [
										'calendar' => $calendar->name,
								] );
						}
						$start = $meta['start']; //= date_atom in utc
						if ( $calendar->type === 'normal' ) {
								$calendarItem = CalendarItem::find( (int) $meta['calendar_item_id'] );
								if ( $calendarItem === null ) {
										return __( 'Added to calendar ":calendar", but the event has been deleted by now.', [
												'calendar' => $calendar->name,
										] );
								}
								$start = new DateTimeImmutable( $calendarItem->start, new DateTimeZone( 'UTC' ) );
						} else {
								$start = new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) );
						}

						$person = $thread->getActionPerson( $conversation_number );

						$defaultTimezone = config( 'app.timezone' );

						return __( 'Added to calendar ":calendar" by :person. Starts at :start.', [
								'calendar' => $calendar->name,
								'person'   => $person,
								'start'    => $start->setTimezone( new DateTimeZone( $defaultTimezone ) )->format( __( 'd-m-Y H:i' ) ),
						] );
				}, 20, 4 );

				Eventy::addAction( 'thread.attachments_list_append', function ( $thread, $conversation, $mailbox ) {
						$attachments = $thread->attachments;
						/** @var Attachment $attachment */
						foreach ( $attachments as $attachment ) {
								if ( str_ends_with( $attachment->file_name, '.ics' ) ) {
										echo View::make( 'calendar::partials/ics_attachment', [ 'attachment' => $attachment ] )->render();
								}
						}
				}, 10, 3 );

				$this->registerSettings();
		}

		private function registerSettings() {
				Eventy::addFilter( 'settings.section_settings', function ( $settings, $section ) {
						if ( $section !== 'calendar' ) {
								return $settings;
						}

						$settings['js']  = asset( Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/settings/settings.js' );
						$settings['css'] = asset( Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/settings/settings.css' );

						return $settings;
				}, 20, 2 );

				// Add item to settings sections.
				Eventy::addFilter( 'settings.sections', function ( $sections ) {
						$sections['calendar'] = [ 'title' => __( 'Calendar' ), 'icon' => 'calendar', 'order' => 200 ];

						return $sections;
				}, 15 );

				// Settings view name
				Eventy::addFilter( 'settings.view', function ( $view, $section ) {
						if ( $section !== 'calendar' ) {
								return $view;
						}

						return 'calendar::settings';
				}, 20, 2 );
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
