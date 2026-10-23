@php $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class; @endphp
@if ($missing)
    <div class="alert alert-warning">{{ __('This calendar no longer exists.') }}</div>
@endif
{{-- Laid out like FreeScout's own Manage > Mailboxes: a bordered "new" button and a card per item. --}}
<p><a href="{{ $page::url('new') }}" class="btn btn-bordered">{{ __('New calendar') }}</a></p>
@if ($calendars->isEmpty())
    <p class="text-help">{{ __('There are no calendars yet. Create one, then choose who can use it.') }}</p>
@else
    <div class="card-list ljpc-calendar-cards">
        @php $starting = $page::syncStartIds(); @endphp
        @foreach ($calendars as $item)
            @php
                $failed = $item->export_token !== null && $item->enabled && !$item->isLocal() && trim((string) $item->last_error) !== '';
                $connection = $item->calendarConnection;
                $connected = !$item->usesConnection() || ($connection && $connection->status === \Modules\LJPcCalendarModule\Entities\CalendarConnection::STATUS_OK);
                $syncable = !$item->isLocal() && $item->enabled && $item->export_token !== null && $connected;
                $syncUrl = route('ljpccalendarmodule.settings.calendars.sync', ['id' => $item->id]);
            @endphp
            {{-- A div, not a link: the status and the sync button live on it too. The name's link covers the card. --}}
            <div class="card no-img hover-shade ljpc-calendar-card {{ $item->enabled ? 'card-active' : 'card-inactive' }}">
                <h4><span class="ljpc-swatch" style="background-color: {{ $page::color($item->color) }}" aria-hidden="true"></span> <a href="{{ $page::url($item->id) }}" class="ljpc-card-link">{{ $item->name }}</a></h4>
                <p class="text-truncate">{{ __($page::PROVIDERS[$item->provider] ?? $item->provider) }}</p>
                <p class="ljpc-card-status" data-ljpc-sync-status="{{ $item->id }}"{!! $page::isSyncing($item) ? ' data-ljpc-syncing' : '' !!}{!! $syncable && in_array($item->id, $starting, true) ? ' data-ljpc-sync-start="' . e($syncUrl) . '"' : '' !!}>
                    @include('calendar::settings.card_status', ['item' => $item])
                </p>
                @if ($failed)
                    {{-- Administrators only see this page; agents never get the error's text. --}}
                    <p class="ljpc-sync-error text-danger" title="{{ __('Last error: :error', ['error' => $item->last_error]) }}">{{ __('Last error: :error', ['error' => $item->last_error]) }}</p>
                @endif
                @if ($syncable)
                    <form method="POST" action="{{ $syncUrl }}" class="ljpc-card-sync" data-ljpc-sync-form>
                        {{ csrf_field() }}
                        <input type="hidden" name="back" value="list">
                        <button type="submit" class="btn btn-default btn-xs"><i class="glyphicon glyphicon-refresh" aria-hidden="true"></i> {{ __('Sync now') }}</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
    <!-- /calendars -->
@endif
