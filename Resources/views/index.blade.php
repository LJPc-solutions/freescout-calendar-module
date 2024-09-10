@extends('layouts.app')

@section('title', __('Calendar'))
@section('content_class', 'content-full')

@section('content')
    <div class="ljpc_calendar_module row">
        <div class="col-md-2 hidden-xs">
            <h3>{{__('My calendars')}}</h3>
            <div id="calendar-picker">

            </div>
        </div>
        <div class="col-md-10 calendar-wrapper">
            <nav class="navbar">
                <button type="button" class="btn btn-default rounded-pill" id="today-button">{{__('Today')}}</button>

                <button type="button" class="btn btn-default btn-round" id="previous-button">
                    <span class="glyphicon glyphicon-chevron-left" aria-hidden="true"></span>
                </button>
                <button type="button" class="btn btn-default btn-round" id="next-button">
                    <span class="glyphicon glyphicon-chevron-right" aria-hidden="true"></span>
                </button>

                <!-- Add view selector buttons -->
                <div class="btn-group view-selector" role="group" aria-label="Calendar View" style="margin-left: 10px;">
                    <button type="button" class="btn btn-default" id="day-view-button" style="z-index:1 !important;">{{__('Day')}}</button>
                    <button type="button" class="btn btn-default" id="week-view-button" style="z-index:1 !important;">{{__('Week')}}</button>
                    <button type="button" class="btn btn-default" id="month-view-button" style="z-index:1 !important;">{{__('Month')}}</button>
                </div>

                <div id="current-date"></div>
            </nav>
            <main id="calendar"></main>
            <div id="event-modal" class="event-modal">
                <div class="event-modal-content">
                    <span class="event-modal-close">&times;</span>
                    <h2>{{__('Create Event')}}</h2>
                    <form>
                        <div class="form-group">
                            <label for="event-title">{{__('Title')}}</label>
                            <input type="text" id="event-title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="event-start">{{__('Start')}}</label>
                            <input type="datetime-local" id="event-start" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="event-end">{{__('End')}}</label>
                            <input type="datetime-local" id="event-end" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="event-location">{{__('Location')}}</label>
                            <textarea id="event-location" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="event-body">{{__('Body')}}</label>
                            <textarea id="event-body" rows="5" class="form-control"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="event-calendar">{{__('Calendar')}}</label>
                            <select id="event-calendar" class="form-control">
                                <option value="">{{__('Select a calendar')}}</option>
                                <!-- Options will be dynamically populated -->
                            </select>
                        </div>
                        <div id="event-custom-fields">
                            <!-- Custom fields will be dynamically inserted here -->
                        </div>
                        <button type="submit" id="save-button" class="btn btn-primary">{{__('Save')}}</button>
                    </form>
                </div>
            </div>

            <div id="event-details-modal" class="event-modal">
                <div class="event-modal-content">
                    <span class="event-modal-close">&times;</span>
                    <h2>{{__('Event Details')}}</h2>
                    <form>
                        <div class="form-group">
                            <label for="event-details-title">{{__('Title')}}</label>
                            <input type="text" id="event-details-title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="event-details-start">{{__('Start')}}</label>
                            <input type="datetime-local" id="event-details-start" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="event-details-end">{{__('End')}}</label>
                            <input type="datetime-local" id="event-details-end" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="event-details-location">{{__('Location')}}</label>
                            <textarea rows="3" id="event-details-location" class="form-control"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="event-details-body">{{__('Body')}}</label>
                            <textarea id="event-details-body" rows="5" class="form-control"></textarea>
                        </div>
                        <div class="form-group event-details-created-by-form-group">
                            <label for="event-details-calendar">{{__('Created by')}}</label>
                            <div id="event-details-created-by"></div>
                        </div>
                        <div class="form-group">
                            <label for="event-details-calendar">{{__('Calendar')}}</label>
                            <div id="event-details-calendar">
                            </div>
                        </div>
                        <div id="event-details-custom-fields">
                            <!-- Custom fields will be dynamically inserted here -->
                        </div>
                        <input type="hidden" id="hidden-event-details-custom-fields">
                        <input type="hidden" id="hidden-event-details-calendar">
                        <input type="hidden" id="hidden-event-details-uid">
                        <button type="submit" id="update-button" class="btn btn-primary">{{__('Update')}}</button>
                        <button type="button" id="delete-button" class="btn btn-danger" disabled>{{__('Delete')}}</button>

                    </form>
                </div>
            </div>

        </div>
    </div>
@endsection

@section('stylesheets')
    @parent
    <link rel="stylesheet" href="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/css/toastui-calendar.min.css' }}">
    <link rel="stylesheet" href="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/css/default.css' }}">
@endsection

@section('body_bottom')
    @parent
    @include('calendar::partials/translations')
    @include('calendar::partials/scripts')
@endsection
