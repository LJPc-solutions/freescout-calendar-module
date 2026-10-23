<div class="ljpc-settings" data-ljpc-sync-status-url="{{ route('ljpccalendarmodule.settings.calendars.status') }}" data-ljpc-syncing-text="{{ __('Syncing…') }}">
    @include('calendar::settings.report')
    @include('calendar::settings.health')
    @include('calendar::settings.tabs')
    @if ($mode === 'edit')
        @include('calendar::settings.edit')
    @elseif ($mode === 'new')
        @include('calendar::settings.new')
    @elseif ($mode === 'connections')
        @include('calendar::settings.connections')
    @elseif ($mode === 'pick')
        @include('calendar::settings.pick')
    @else
        @include('calendar::settings.list')
    @endif
</div>
{!! \Modules\LJPcCalendarModule\Http\Assets::style('settings') !!}
{!! \Modules\LJPcCalendarModule\Http\Assets::script('settings') !!}
