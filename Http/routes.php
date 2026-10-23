<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\LJPcCalendarModule\Http\Controllers'], function () {
    $agent = ['middleware' => ['auth', 'roles'], 'roles' => ['user', 'admin']];

    $agentApi = ['middleware' => ['auth', 'roles', \Modules\LJPcCalendarModule\Http\Middleware\EnsureCalendarMigrated::class], 'roles' => ['user', 'admin']];

    $admin = ['middleware' => ['auth', 'roles'], 'roles' => ['admin']];

    Route::get('/calendar', $agent + ['uses' => 'CalendarPageController@index'])->name('ljpccalendarmodule.index');

    Route::post('/calendar/settings/calendars', $admin + ['uses' => 'Settings\CalendarsController@store'])->name('ljpccalendarmodule.settings.calendars.store');
    Route::post('/calendar/settings/calendars/{id}', $admin + ['uses' => 'Settings\CalendarsController@update'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.calendars.update');
    Route::post('/calendar/settings/calendars/{id}/delete', $admin + ['uses' => 'Settings\CalendarsController@destroy'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.calendars.delete');
    Route::post('/calendar/settings/calendars/{id}/tokens', $admin + ['uses' => 'Settings\CalendarsController@regenerateToken'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.calendars.tokens');
    Route::post('/calendar/settings/calendars/{id}/sync', $admin + ['uses' => 'Settings\CalendarsController@sync'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.calendars.sync');
    Route::get('/calendar/settings/calendars/status', $admin + ['uses' => 'Settings\CalendarsController@status'])->name('ljpccalendarmodule.settings.calendars.status');
    Route::post('/calendar/settings/migration/items/{id}/skip', $admin + ['uses' => 'Settings\CalendarsController@skipItem'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.migration.skip');
    Route::post('/calendar/settings/apps/{provider}', $admin + ['uses' => 'Settings\ConnectionsController@saveApp'])->where('provider', 'google|microsoft')->name('ljpccalendarmodule.settings.apps');
    Route::post('/calendar/settings/connections/caldav', $admin + ['uses' => 'Settings\ConnectionsController@caldav'])->name('ljpccalendarmodule.settings.connections.caldav');
    Route::post('/calendar/settings/connections/{id}/calendars', $admin + ['uses' => 'Settings\ConnectionsController@addCalendars'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.connections.calendars');
    Route::post('/calendar/settings/connections/{id}/delete', $admin + ['uses' => 'Settings\ConnectionsController@destroy'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.connections.delete');
    Route::get('/calendar/oauth/{provider}/start', $admin + ['uses' => 'OAuthController@start'])->where('provider', 'google|microsoft')->name('ljpccalendarmodule.oauth.start');
    Route::get('/calendar/oauth/{provider}/callback', $admin + ['uses' => 'OAuthController@callback'])->where('provider', 'google|microsoft')->name('ljpccalendarmodule.oauth.callback');

    Route::get('/calendar/api/calendars', $agentApi + ['uses' => 'Api\CalendarsController@index'])->name('ljpccalendarmodule.api.calendars');
    Route::get('/calendar/api/events', $agentApi + ['uses' => 'Api\EventsController@index'])->name('ljpccalendarmodule.api.events');
    Route::post('/calendar/api/events', $agentApi + ['uses' => 'Api\EventsController@store'])->name('ljpccalendarmodule.api.events.store');
    Route::get('/calendar/api/events/{ref}', $agentApi + ['uses' => 'Api\EventsController@show'])->name('ljpccalendarmodule.api.events.show');
    Route::patch('/calendar/api/events/{id}', $agentApi + ['uses' => 'Api\EventsController@update'])->where('id', '[0-9]+')->name('ljpccalendarmodule.api.events.update');
    Route::delete('/calendar/api/events/{id}', $agentApi + ['uses' => 'Api\EventsController@destroy'])->where('id', '[0-9]+')->name('ljpccalendarmodule.api.events.destroy');
    Route::post('/calendar/api/sync', $agentApi + ['uses' => 'Api\SyncController@store'])->name('ljpccalendarmodule.api.sync');
    Route::post('/calendar/api/invites/{id}/apply', $agentApi + ['uses' => 'Api\InvitesController@apply'])->where('id', '[0-9]+')->name('ljpccalendarmodule.api.invites.apply');
    Route::post('/calendar/api/invites/{id}/answer', $agentApi + ['uses' => 'Api\InvitesController@answer'])->where('id', '[0-9]+')->name('ljpccalendarmodule.api.invites.answer');
    Route::post('/calendar/api/conversations/{id}/events', $agentApi + ['uses' => 'Api\ConversationEventsController@store'])->where('id', '[0-9]+')->name('ljpccalendarmodule.api.conversations.events.store');
});

Route::group(['middleware' => ['throttle:60,1'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\LJPcCalendarModule\Http\Controllers'], function () {
    Route::get('/calendar/{id}/export/{token}.ics', 'ExportController@show')
        ->where(['id' => '[0-9]+', 'token' => '[a-f0-9]{40}'])
        ->name('ljpccalendarmodule.export');
});
