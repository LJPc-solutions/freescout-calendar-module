@extends('layouts.app')

@section('title', __('Calendar'))
@section('content_class', 'content-full')

@section('content')
    <div class="ljpc_calendar_module">
        <div id="lnb">
            <div class="lnb-new-schedule">
                <button class="btn btn-default btn-block lnb-new-schedule-btn" data-toggle="modal" id="btn-new-schedule"
                        type="button">
                    {{ __('New item') }}
                </button>
            </div>
            <div class="lnb-calendars" id="lnb-calendars">
                <div>
                    <div class="lnb-calendars-item">
                        <label>
                            <input checked class="tui-full-calendar-checkbox-square" type="checkbox" value="all">
                            <span></span>
                            <strong>{{ __('View all') }}</strong>
                        </label>
                    </div>
                </div>
                <div class="lnb-calendars-d1" id="calendarList">
                </div>
            </div>
            <div class="lnb-footer">
                <a href="https://ljpc.solutions" target="_blank"><img
                            src="https://resources.ljpc.network/solutions/color.svg"></a>
            </div>
        </div>
        <div id="right">
            <div id="menu">
            <span class="dropdown">
                <button aria-expanded="true" aria-haspopup="true" class="btn btn-default btn-sm dropdown-toggle"
                        data-toggle="dropdown"
                        id="dropdownMenu-calendarType" type="button">
                    <i class="calendar-icon ic_view_month" id="calendarTypeIcon" style="margin-right: 4px;"></i>
                    <span id="calendarTypeName">{{ __('Dropdown') }}</span>&nbsp;
                    <i class="calendar-icon tui-full-calendar-dropdown-arrow"></i>
                </button>
                <ul aria-labelledby="dropdownMenu-calendarType" class="dropdown-menu" role="menu">
                    <li role="presentation">
                        <a class="dropdown-menu-title" data-action="toggle-daily" role="menuitem">
                            <i class="calendar-icon ic_view_day"></i>{{ __('Day') }}
                        </a>
                    </li>
                    <li role="presentation">
                        <a class="dropdown-menu-title" data-action="toggle-weekly" role="menuitem">
                            <i class="calendar-icon ic_view_week"></i>{{__('Week') }}
                        </a>
                    </li>
                    <li role="presentation">
                        <a class="dropdown-menu-title" data-action="toggle-monthly" role="menuitem">
                            <i class="calendar-icon ic_view_month"></i>{{__('Month') }}
                        </a>
                    </li>
                    <li class="dropdown-divider" role="presentation"></li>
                    <li role="presentation">
                        <a data-action="toggle-workweek" role="menuitem">
                            <input checked class="tui-full-calendar-checkbox-square" type="checkbox"
                                   value="toggle-workweek">
                            <span class="checkbox-title"></span>{{ __('Show weekends') }}
                        </a>
                    </li>
                </ul>
            </span>
                <span id="menu-navi">
                <button class="btn btn-default btn-sm move-today" data-action="move-today"
                        type="button">{{ __('Today') }}</button>
                <button class="btn btn-default btn-sm move-day" data-action="move-prev" type="button">
                    <i class="calendar-icon ic-arrow-line-left" data-action="move-prev"></i>
                </button>
                <button class="btn btn-default btn-sm move-day" data-action="move-next" type="button">
                    <i class="calendar-icon ic-arrow-line-right" data-action="move-next"></i>
                </button>
            </span>
                <span class="render-range" id="renderRange"></span>
            </div>
            <div id="calendar"></div>
        </div>
    </div>
@endsection

@section('stylesheets')
    @parent
    <link href="{{ asset(Module::getPublicPath(LJPC_CALENDARS_MODULE).'/css/tui-time-picker.min.css') }}"
          rel="stylesheet" type="text/css">
    <link href="{{ asset(Module::getPublicPath(LJPC_CALENDARS_MODULE).'/css/tui-date-picker.min.css') }}"
          rel="stylesheet" type="text/css">
    <link href="{{ asset(Module::getPublicPath(LJPC_CALENDARS_MODULE).'/css/tui-calendar.min.css') }}" rel="stylesheet"
          type="text/css">
    <link href="{{ asset(Module::getPublicPath(LJPC_CALENDARS_MODULE).'/css/default.css') }}" rel="stylesheet"
          type="text/css">
    <link href="{{ asset(Module::getPublicPath(LJPC_CALENDARS_MODULE).'/css/icons.css') }}" rel="stylesheet"
          type="text/css">
@endsection

@section('body_bottom')
    @parent
    @include('calendar::partials/scripts')
@endsection
