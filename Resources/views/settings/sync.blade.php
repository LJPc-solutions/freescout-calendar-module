@php
    $connection = $calendar->calendarConnection;
    $connected = !$calendar->usesConnection() || ($connection && $connection->status === \Modules\LJPcCalendarModule\Entities\CalendarConnection::STATUS_OK);
    $interval = $posted ? $page::oldString('sync_interval') : (string) $calendar->sync_interval;
@endphp
<h4 class="ljpc-section" id="ljpc-sync">{{ __('Sync') }}</h4>
<div class="form-group">
    <div class="col-sm-2 control-label">{{ __('Source') }}</div>
    <div class="col-sm-6">
        <p class="form-control-static">
            {{ __($page::PROVIDERS[$calendar->provider] ?? $calendar->provider) }}
            @if ($connection)
                <span aria-hidden="true">&middot;</span>
                <a href="{{ $page::accountUrl($connection) }}">{{ $connection->account ?: $page::host($connection->server_url) }}</a>
            @endif
        </p>
        @if ($calendar->provider === \Modules\LJPcCalendarModule\Entities\Calendar::PROVIDER_ICS)
            <p class="help-block">{{ __('An ICS feed is read-only: its events can\'t be changed from FreeScout.') }}</p>
        @elseif ($calendar->remote_read_only)
            <p class="help-block">{{ __('The account may only read this calendar, so its events can\'t be changed from FreeScout.') }}</p>
        @endif
        @if (!$connected)
            <p class="text-warning">
                {{ __('The account of this calendar must be connected again before it can sync.') }}
                @if ($connection)
                    <a href="{{ $page::reauthorizeUrl($connection) }}">{{ __('Reauthorize') }}</a>
                @endif
            </p>
        @endif
    </div>
</div>
@if ($calendar->provider === \Modules\LJPcCalendarModule\Entities\Calendar::PROVIDER_ICS)
    <div class="form-group{{ $errors->has('url') ? ' has-error' : '' }}">
        <label for="ljpc-url" class="col-sm-2 control-label">{{ __('Calendar link') }}</label>
        <div class="col-sm-6">
            <input id="ljpc-url" type="url" class="form-control" name="url" value="{{ $page::oldString('url', $calendar->remote_id) }}" maxlength="2000" required>
            <p class="help-block">{{ __('Anyone with this link can read the calendar, so only administrators see it here. A new link is read in full at the next sync.') }}</p>
            @include('partials/field_error', ['field' => 'url'])
        </div>
    </div>
@elseif ($calendar->provider === \Modules\LJPcCalendarModule\Entities\Calendar::PROVIDER_CALDAV)
    <div class="form-group">
        <div class="col-sm-2 control-label">{{ __('Calendar address') }}</div>
        <div class="col-sm-6">
            <p class="form-control-static ljpc-address">{{ (string) $calendar->remote_id }}</p>
        </div>
    </div>
@endif
<div class="form-group{{ $errors->has('sync_interval') ? ' has-error' : '' }}">
    <label for="ljpc-interval" class="col-sm-2 control-label">{{ __('Check for changes') }}</label>
    <div class="col-sm-6">
        <select id="ljpc-interval" class="form-control input-sized" name="sync_interval">
            @foreach ($page::syncIntervals() as $minutes => $label)
                <option value="{{ $minutes }}"{{ $interval === (string) $minutes ? ' selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        @include('partials/field_error', ['field' => 'sync_interval'])
    </div>
</div>
@php $syncUrl = route('ljpccalendarmodule.settings.calendars.sync', ['id' => $calendar->id]); @endphp
<div class="form-group">
    <div class="col-sm-2 control-label">{{ __('Status') }}</div>
    <div class="col-sm-6">
        <p class="form-control-static" data-ljpc-sync-status="{{ $calendar->id }}"{!! $page::isSyncing($calendar) ? ' data-ljpc-syncing' : '' !!}{!! $calendar->enabled && $connected && in_array($calendar->id, $page::syncStartIds(), true) ? ' data-ljpc-sync-start="' . e($syncUrl) . '"' : '' !!}>
            @include('calendar::settings.card_status', ['item' => $calendar])
        </p>
        @if (trim((string) $calendar->last_error) !== '')
            <p class="text-danger">{{ __('Last error: :error', ['error' => $calendar->last_error]) }}</p>
        @endif
        <button type="submit" form="ljpc-sync-now" class="btn btn-default" data-ljpc-sync-button{{ $calendar->enabled && $connected ? '' : ' disabled' }}><i class="glyphicon glyphicon-refresh" aria-hidden="true"></i> {{ __('Sync now') }}</button>
        <p class="help-block">
            @if (!$calendar->enabled)
                {{ __('This calendar is off, so it doesn\'t sync.') }}
            @else
                {{ __('Syncs right away and tells you what changed. Save other changes on this page first.') }}
            @endif
        </p>
    </div>
</div>
