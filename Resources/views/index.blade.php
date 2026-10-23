@extends('layouts.app')

@section('title', __('Calendar'))
{{-- The calendar fills the window below the navbar; core's footer would add a second page scrollbar. --}}
@section('no_footer', '1')

{{-- FreeScout's sidebar column, as on a mailbox page; the calendar app fills it with the calendars. --}}
@section('sidebar')
    <div id="ljpc-calendar-sidebar" class="ljpc-sidebar"></div>
@endsection

@section('stylesheets')
    {!! \Modules\LJPcCalendarModule\Http\Assets::style('calendar') !!}
@endsection

@section('content')
    <div id="ljpc-calendar-app" class="ljpc-calendar"></div>
    <script type="application/json" id="ljpc-calendar-config">{!! \Modules\LJPcCalendarModule\Http\FrontendConfig::json($config) !!}</script>
@endsection

@section('javascripts')
    {!! \Modules\LJPcCalendarModule\Http\Assets::script('calendar') !!}
@endsection
