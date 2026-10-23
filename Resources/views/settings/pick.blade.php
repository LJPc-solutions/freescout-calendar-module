@php
    $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class;
    $connection = $pick['connection'];
    $owner = $pick['owner'];
    $ticked = (array) old('calendars', []);
    $readOnly = collect($pick['calendars'])->contains(function (array $item) {
        return !$item['remote']->writable;
    });
@endphp
<p><a href="{{ $page::connectionsUrl() }}">{{ __('All connections') }}</a></p>
<h3 class="ljpc-title">{{ __('Choose calendars') }}</h3>
<p class="text-help">
    {{ __($page::PROVIDERS[$connection->provider] ?? $connection->provider) }}: {{ (string) $connection->account }}
    @if ($owner !== null)
        <span aria-hidden="true">&middot;</span>
        {{ __('Shared mailbox :address', ['address' => $owner]) }}
    @endif
</p>

@if ($pick['error'] !== null)
    <div class="alert alert-danger">{{ $pick['error'] }}
        @if ($connection->status !== \Modules\LJPcCalendarModule\Entities\CalendarConnection::STATUS_OK)
            <a href="{{ $page::reauthorizeUrl($connection) }}">{{ __('Reauthorize') }}</a>
        @endif
    </div>
    <p>
        <a href="{{ $page::connectionsUrl($connection->id, $owner) }}" class="btn btn-default">{{ __('Try again') }}</a>
        <a href="{{ $page::connectionsUrl() }}" class="btn btn-link">{{ __('Cancel') }}</a>
    </p>
@elseif (!$pick['calendars'])
    <p>{{ __('This account has no calendars with events that FreeScout can show.') }}</p>
    <p><a href="{{ $page::connectionsUrl() }}" class="btn btn-default">{{ __('Back to the connections') }}</a></p>
@else
    <form method="POST" action="{{ route('ljpccalendarmodule.settings.connections.calendars', ['id' => $connection->id]) }}" class="ljpc-pick">
        {{ csrf_field() }}
        @if ($owner !== null)
            <input type="hidden" name="owner" value="{{ $owner }}">
        @endif
        @foreach (array_merge($errors->get('owner'), $errors->get('calendars')) as $message)
            <div class="alert alert-danger">{{ $message }}</div>
        @endforeach
        <p>{{ __('Tick the calendars FreeScout shows. Each one becomes a calendar of its own, with its own settings and permissions.') }}</p>
        <ul class="list-unstyled ljpc-pick-list">
            @foreach ($pick['calendars'] as $item)
                @php
                    $remote = $item['remote'];
                    $calendar = $item['calendar'];
                @endphp
                <li class="checkbox">
                    <label>
                        <input type="checkbox" name="calendars[]" value="{{ $remote->remoteId }}"{{ $calendar ? ' checked disabled' : (in_array($remote->remoteId, $ticked, true) ? ' checked' : '') }}>
                        @php $color = $calendar ? $calendar->color : $remote->color; @endphp
                        @if ($page::isColor($color))
                            <span class="ljpc-swatch" style="background-color: {{ $page::color($color) }}" aria-hidden="true"></span>
                        @else
                            {{-- No color of its own: adding it picks one of the swatches. --}}
                            <span class="ljpc-swatch ljpc-swatch-none" aria-hidden="true"></span>
                        @endif
                        {{ $remote->name }}
                        @if ($remote->primary)
                            <span class="label label-default">{{ __('Primary') }}</span>
                        @endif
                        @if (!$remote->writable)
                            <span class="label label-warning">{{ __('Read only') }}</span>
                        @endif
                    </label>
                    @if ($calendar)
                        <span class="text-help">{{ __('Added as') }} <a href="{{ $page::url($calendar->id) }}">{{ $calendar->name }}</a></span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($readOnly)
            <p class="help-block">{{ __('Read only: the account may only view this calendar, so agents can see its events in FreeScout but not change them.') }}</p>
        @endif
        <button type="submit" class="btn btn-primary">{{ __('Add calendars') }}</button>
        <a href="{{ $page::connectionsUrl() }}" class="btn btn-link">{{ __('Cancel') }}</a>
    </form>
@endif
