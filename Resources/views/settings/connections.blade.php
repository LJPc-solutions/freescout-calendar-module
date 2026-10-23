@php
    $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class;
    $none = new \Illuminate\Support\MessageBag();
    // The CalDAV form that was posted and failed: "" for a new connection, the connection's id for a reauthorization.
    $caldavErrors = $errors->caldav;
    $caldavPosted = $caldavErrors->any() ? $page::oldString('connection', '') : null;
@endphp
@if ($missing)
    <div class="alert alert-warning">{{ __('This connection no longer exists.') }}</div>
@endif
<h3 class="ljpc-title">{{ __('Connected accounts') }}</h3>
<p class="text-help">{{ __('FreeScout syncs the calendars you add from these accounts. Add them with "Choose calendars" here, or with "New calendar" on the Calendars tab.') }}</p>
@if ($connections->isEmpty())
    <p class="text-help">{{ __('No accounts are connected yet. Connect one below.') }}</p>
@else
    <table class="table ljpc-connections">
        <thead>
            <tr>
                <th>{{ __('Provider') }}</th>
                <th>{{ __('Account') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Calendars') }}</th>
                <th><span class="sr-only">{{ __('Actions') }}</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($connections as $connection)
                <tr id="ljpc-connection-{{ $connection->id }}">
                    <td>{{ __($page::PROVIDERS[$connection->provider] ?? $connection->provider) }}</td>
                    <td>
                        {{ (string) $connection->account }}
                        @if ($connection->provider === \Modules\LJPcCalendarModule\Entities\Calendar::PROVIDER_CALDAV && $page::host($connection->server_url) !== '')
                            <span class="text-help">{{ __('on :host', ['host' => $page::host($connection->server_url)]) }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($connection->status === \Modules\LJPcCalendarModule\Entities\CalendarConnection::STATUS_OK)
                            {{ $page::connectionStatus($connection->status) }}
                        @else
                            <span class="text-danger">{{ $page::connectionStatus($connection->status) }}</span>
                            @if (trim((string) $connection->last_error) !== '')
                                <span class="help-block">{{ $connection->last_error }}</span>
                            @endif
                        @endif
                    </td>
                    <td class="ljpc-count">{{ $connection->calendars_count }}</td>
                    <td class="ljpc-actions">
                        <a href="{{ $page::connectionsUrl($connection->id) }}" class="btn btn-default btn-xs">{{ __('Choose calendars') }}</a>
                        @if ($connection->provider !== \Modules\LJPcCalendarModule\Entities\Calendar::PROVIDER_CALDAV)
                            <a href="{{ $page::reauthorizeUrl($connection) }}" class="btn btn-default btn-xs">{{ __('Reauthorize') }}</a>
                        @endif
                        <form method="POST" action="{{ route('ljpccalendarmodule.settings.connections.delete', ['id' => $connection->id]) }}" data-ljpc-confirm="{{ $page::deleteQuestion($connection) }}" class="ljpc-inline">
                            {{ csrf_field() }}
                            <button type="submit" class="btn btn-link btn-xs ljpc-delete">{{ __('Delete') }}</button>
                        </form>
                        @if ($connection->provider === \Modules\LJPcCalendarModule\Entities\Calendar::PROVIDER_CALDAV)
                            {{-- Open after its own failed post, and while the server refuses the saved login. --}}
                            @php $mine = $caldavPosted === (string) $connection->id; @endphp
                            <details class="ljpc-action"{{ $mine || $connection->status !== \Modules\LJPcCalendarModule\Entities\CalendarConnection::STATUS_OK ? ' open' : '' }}>
                                <summary class="btn btn-default btn-xs">{{ __('Reauthorize') }}</summary>
                                <form method="POST" action="{{ route('ljpccalendarmodule.settings.connections.caldav') }}" class="ljpc-action-form">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="connection" value="{{ $connection->id }}">
                                    <div class="form-group{{ $mine && $caldavErrors->has('username') ? ' has-error' : '' }}">
                                        <label for="ljpc-reauth-username-{{ $connection->id }}">{{ __('User name') }}</label>
                                        <input id="ljpc-reauth-username-{{ $connection->id }}" type="text" class="form-control" name="username" value="{{ $mine ? $page::oldString('username') : (string) $connection->account }}" maxlength="191" required autocomplete="off">
                                        @include('partials/field_error', ['field' => 'username', 'errors' => $mine ? $caldavErrors : $none])
                                    </div>
                                    <div class="form-group{{ $mine && $caldavErrors->has('password') ? ' has-error' : '' }}">
                                        <label for="ljpc-reauth-password-{{ $connection->id }}">{{ __('Password') }}</label>
                                        <input id="ljpc-reauth-password-{{ $connection->id }}" type="password" class="form-control" name="password" value="" maxlength="500" autocomplete="new-password">
                                        <p class="help-block">{{ __('Leave it empty to keep the saved password.') }}</p>
                                        @include('partials/field_error', ['field' => 'password', 'errors' => $mine ? $caldavErrors : $none])
                                    </div>
                                    @if ($mine && $caldavErrors->has('connect'))
                                        <div class="alert alert-danger">{{ $caldavErrors->first('connect') }}</div>
                                    @endif
                                    <button type="submit" class="btn btn-primary btn-sm">{{ __('Connect again') }}</button>
                                </form>
                            </details>
                        @elseif ($connection->provider === \Modules\LJPcCalendarModule\Entities\Calendar::PROVIDER_MICROSOFT)
                            <details class="ljpc-action">
                                <summary class="btn btn-default btn-xs">{{ __('Add a shared mailbox') }}</summary>
                                {{-- Opens the pick screen with the calendars of the mailbox this account has access to. --}}
                                <form method="GET" action="{{ $page::url() }}" class="ljpc-action-form" data-ljpc-shared-mailbox="{{ $connection->id }}">
                                    <input type="hidden" name="tab" value="connections">
                                    <input type="hidden" name="connection" value="{{ $connection->id }}">
                                    <input type="hidden" name="pick" value="1">
                                    <div class="form-group">
                                        <label for="ljpc-owner-{{ $connection->id }}">{{ __('Email address of the shared mailbox') }}</label>
                                        <input id="ljpc-owner-{{ $connection->id }}" type="email" class="form-control" name="owner" maxlength="191" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm">{{ __('Choose its calendars') }}</button>
                                </form>
                            </details>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<h3 class="ljpc-title">{{ __('Connect an account') }}</h3>
<div class="ljpc-connect">
    @include('calendar::settings.apps')

    <div class="panel panel-default ljpc-provider">
        <div class="panel-heading">
            <strong>{{ __('CalDAV') }}</strong>
            <a href="{{ $page::wikiUrl('CalDAV') }}" target="_blank" rel="noopener noreferrer" class="ljpc-wiki">{{ __('How to connect a CalDAV server') }}</a>
        </div>
        <div class="panel-body">
            <p class="text-help">{{ __('Nextcloud, iCloud, Fastmail and every other server that offers CalDAV. FreeScout finds the account\'s calendars from the server address.') }}</p>
            @php $newErrors = $caldavPosted === '' ? $caldavErrors : $none; @endphp
            <form id="ljpc-caldav" class="form-horizontal" method="POST" action="{{ route('ljpccalendarmodule.settings.connections.caldav') }}">
                {{ csrf_field() }}
                <div class="form-group{{ $newErrors->has('server_url') ? ' has-error' : '' }}">
                    <label for="ljpc-caldav-url" class="col-sm-3 control-label">{{ __('Server address') }}</label>
                    <div class="col-sm-8">
                        <input id="ljpc-caldav-url" type="url" class="form-control" name="server_url" value="{{ $caldavPosted === '' ? $page::oldString('server_url') : '' }}" maxlength="2000" placeholder="https://" required>
                        @include('partials/field_error', ['field' => 'server_url', 'errors' => $newErrors])
                    </div>
                </div>
                <div class="form-group{{ $newErrors->has('username') ? ' has-error' : '' }}">
                    <label for="ljpc-caldav-username" class="col-sm-3 control-label">{{ __('User name') }}</label>
                    <div class="col-sm-8">
                        <input id="ljpc-caldav-username" type="text" class="form-control input-sized" name="username" value="{{ $caldavPosted === '' ? $page::oldString('username') : '' }}" maxlength="191" required autocomplete="off">
                        @include('partials/field_error', ['field' => 'username', 'errors' => $newErrors])
                    </div>
                </div>
                <div class="form-group{{ $newErrors->has('password') ? ' has-error' : '' }}">
                    <label for="ljpc-caldav-password" class="col-sm-3 control-label">{{ __('Password') }}</label>
                    <div class="col-sm-8">
                        <input id="ljpc-caldav-password" type="password" class="form-control input-sized" name="password" value="" maxlength="500" required autocomplete="new-password">
                        <p class="help-block">{{ __('Use an app password when the server offers one.') }}</p>
                        @include('partials/field_error', ['field' => 'password', 'errors' => $newErrors])
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-8 col-sm-offset-3">
                        <button type="submit" class="btn btn-primary">{{ __('Connect') }}</button>
                        @if ($newErrors->has('connect'))
                            <div class="alert alert-danger margin-top-10">{{ $newErrors->first('connect') }}</div>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
