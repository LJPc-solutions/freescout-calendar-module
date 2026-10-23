{{-- A calendar's sync status, on its card in the list and on its edit page. While it syncs, the settings script asks
     settings/calendars/status for this partial again until it is done. --}}
@php
    $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class;
    $failed = $item->export_token !== null && $item->enabled && !$item->isLocal() && trim((string) $item->last_error) !== '';
    $connection = $item->calendarConnection;
    // Not synced while its account can't be used, also when this calendar has no error of its own.
    $disconnected = $item->usesConnection() && (!$connection || $connection->status !== \Modules\LJPcCalendarModule\Entities\CalendarConnection::STATUS_OK);
@endphp
@if ($item->export_token === null)
    <span class="text-warning">{{ __('Not upgraded yet') }}</span>
@elseif (!$item->enabled)
    {{ __('Disabled') }}
@elseif ($item->isLocal())
    {{ __('Enabled') }}
@elseif ($disconnected)
    <span class="text-danger">{{ $connection ? $page::connectionStatus($connection->status) : __('No account') }}</span>
    @if ($connection)
        <a href="{{ $page::reauthorizeUrl($connection) }}">{{ __('Reauthorize') }}</a>
    @endif
@elseif ($failed)
    <span class="text-danger">{{ __('Sync failed') }}</span>
@elseif ($page::isSyncing($item))
    <span class="ljpc-syncing"><i class="glyphicon glyphicon-refresh ljpc-spin" aria-hidden="true"></i> {{ __('Syncing…') }}</span>
@else
    {{ __('OK, synced :time', ['time' => $page::time($item->last_synced_at)]) }}
@endif
