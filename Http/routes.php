<?php

Route::group( [ 'middleware' => 'web', 'prefix' => Helper::getSubdirectory(), 'namespace' => 'Modules\LJPcCalendarModule\Http\Controllers' ], function () {
		/**
		 * Frontend calendar
		 */
		Route::get( '/calendar', [
				'uses'       => 'LJPcCalendarModuleCalendarController@index',
				'middleware' => [ 'auth', 'roles' ],
				'roles'      => [ 'user', 'admin' ],
		] )->name( 'ljpccalendarmodule.index' );

		Route::get( '/calendar/{id}/ics', [
				'uses' => 'LJPcCalendarModuleCalendarController@getAsICS',
		] )->name( 'ljpccalendarmodule.ics' );

		/**
		 * Settings
		 */
		$middleWare = [ 'auth', 'roles' ];
		$roles      = [ 'admin' ];
		Route::get( '/calendar/api/users', [
				'uses'       => 'LJPcCalendarModuleAPIController@getUsers',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.users' );
		Route::get( '/calendar/api/calendars', [
				'uses'       => 'LJPcCalendarModuleAPIController@getCalendars',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.calendars.all' );
		Route::post( '/calendar/api/calendars', [
				'uses'       => 'LJPcCalendarModuleAPIController@addCalendar',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.calendar.new' );
		Route::put( '/calendar/api/calendars/{id}', [
				'uses'       => 'LJPcCalendarModuleAPIController@updateCalendar',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.calendar.update' );
		Route::delete( '/calendar/api/calendars/{id}', [
				'uses'       => 'LJPcCalendarModuleAPIController@deleteCalendar',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.calendar.delete' );

		/**
		 * Frontend calendar API calls
		 */
		$roles = [ 'user', 'admin' ];
		Route::get( '/calendar/api/calendars/authorized', [
				'uses'       => 'LJPcCalendarModuleAPIController@getAuthorizedCalendars',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.calendar.authorized' );
		Route::get( '/calendar/api/events', [
				'uses'       => 'LJPcCalendarModuleAPIController@getEvents',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.events' );
		Route::put( '/calendar/api/events', [
				'uses'       => 'LJPcCalendarModuleAPIController@updateEvent',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.event.update' );
		Route::post( '/calendar/api/events', [
				'uses'       => 'LJPcCalendarModuleAPIController@createEvent',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.event.create' );
		Route::post( '/calendar/api/events/attachment', [
				'uses'       => 'LJPcCalendarModuleAPIController@createEventFromAttachment',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.event.create_from_attachment' );
		Route::post( '/calendar/api/events/{conversation}', [
				'uses'       => 'LJPcCalendarModuleAPIController@createEventFromConversation',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.event.create_from_conversation' );
		Route::delete( '/calendar/api/events', [
				'uses'       => 'LJPcCalendarModuleAPIController@deleteEvent',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.event.delete' );
		Route::get( '/calendar/api/calendars/{id}', [
				'uses'       => 'LJPcCalendarModuleAPIController@getCalendar',
				'middleware' => $middleWare,
				'roles'      => $roles,
				'laroute'    => true,
		] )->name( 'ljpccalendarmodule.api.calendar.get' );

} );
