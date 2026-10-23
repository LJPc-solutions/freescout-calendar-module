@php
    $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class;
    // Only the form that was posted and failed shows what was typed and its errors; the hidden forms stay empty.
    $posted = $page::postedProvider();
    $none = new \Illuminate\Support\MessageBag();
    $localErrors = $posted === 'local' ? $errors : $none;
    $icsErrors = $posted === 'ics' ? $errors : $none;
    $interval = $posted === 'ics' ? $page::oldString('sync_interval') : '15';
@endphp
<p><a href="{{ $page::url() }}">{{ __('All calendars') }}</a></p>
<h3 class="ljpc-title">{{ __('New calendar') }}</h3>

{{-- Without JavaScript, "Continue" reloads the page with the chosen type; with it, the type switches the form at once. --}}
<form method="GET" action="{{ $page::url() }}" class="ljpc-type-chooser" data-ljpc-type-chooser>
    <input type="hidden" name="calendar" value="new">
    <fieldset>
        <legend class="ljpc-legend">{{ __('What kind of calendar?') }}</legend>
        <div class="ljpc-type-cards">
            @foreach ($page::newTypes() as $value => [$title, $text])
                <label class="ljpc-type-card">
                    <input type="radio" name="type" value="{{ $value }}" data-ljpc-type{{ $type === $value ? ' checked' : '' }}>
                    <span class="ljpc-type-title">{{ $title }}</span>
                    <span class="ljpc-type-text">{{ $text }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>
    <button type="submit" class="btn btn-default" data-ljpc-type-continue>{{ __('Continue') }}</button>
</form>

<datalist id="ljpc-swatches">
    @foreach ($page::SWATCHES as $swatch)
        <option value="{{ $swatch }}"></option>
    @endforeach
</datalist>

<div class="ljpc-type-form{{ $type === 'local' ? '' : ' hidden' }}" data-ljpc-type-form="local">
    <form class="form-horizontal margin-top" method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.store') }}">
        {{ csrf_field() }}
        <input type="hidden" name="provider" value="local">
        <div class="form-group{{ $localErrors->has('name') ? ' has-error' : '' }}">
            <label for="ljpc-name" class="col-sm-2 control-label">{{ __('Name') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-name" type="text" class="form-control input-sized" name="name" value="{{ $posted === 'local' ? $page::oldString('name') : '' }}" maxlength="191" required{{ $type === 'local' ? ' autofocus' : '' }}>
                @include('partials/field_error', ['field' => 'name', 'errors' => $localErrors])
            </div>
        </div>
        <div class="form-group{{ $localErrors->has('color') ? ' has-error' : '' }}">
            <label for="ljpc-color" class="col-sm-2 control-label">{{ __('Color') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-color" type="color" class="form-control ljpc-color" name="color" value="{{ $page::color($posted === 'local' ? old('color') : $page::SWATCHES[0]) }}" list="ljpc-swatches" required>
                @include('partials/field_error', ['field' => 'color', 'errors' => $localErrors])
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-6 col-sm-offset-2">
                <button type="submit" class="btn btn-primary">{{ __('Create calendar') }}</button>
                <a href="{{ $page::url() }}" class="btn btn-link">{{ __('Cancel') }}</a>
            </div>
        </div>
    </form>
</div>

<div class="ljpc-type-form{{ $type === 'ics' ? '' : ' hidden' }}" data-ljpc-type-form="ics">
    <form class="form-horizontal margin-top" method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.store') }}">
        {{ csrf_field() }}
        <input type="hidden" name="provider" value="ics">
        <div class="form-group{{ $icsErrors->has('name') ? ' has-error' : '' }}">
            <label for="ljpc-ics-name" class="col-sm-2 control-label">{{ __('Name') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-ics-name" type="text" class="form-control input-sized" name="name" value="{{ $posted === 'ics' ? $page::oldString('name') : '' }}" maxlength="191" required{{ $type === 'ics' ? ' autofocus' : '' }}>
                @include('partials/field_error', ['field' => 'name', 'errors' => $icsErrors])
            </div>
        </div>
        <div class="form-group{{ $icsErrors->has('color') ? ' has-error' : '' }}">
            <label for="ljpc-ics-color" class="col-sm-2 control-label">{{ __('Color') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-ics-color" type="color" class="form-control ljpc-color" name="color" value="{{ $page::color($posted === 'ics' ? old('color') : $page::SWATCHES[0]) }}" list="ljpc-swatches" required>
                @include('partials/field_error', ['field' => 'color', 'errors' => $icsErrors])
            </div>
        </div>
        <div class="form-group{{ $icsErrors->has('url') ? ' has-error' : '' }}">
            <label for="ljpc-ics-url" class="col-sm-2 control-label">{{ __('Calendar link') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-ics-url" type="url" class="form-control" name="url" value="{{ $posted === 'ics' ? $page::oldString('url') : '' }}" maxlength="2000" placeholder="https://" required>
                <p class="help-block">{{ __('The ICS or webcal link the other app shares, starting with https:// or webcal://. Anyone with the link can read the calendar, so only administrators see it here.') }}</p>
                @include('partials/field_error', ['field' => 'url', 'errors' => $icsErrors])
            </div>
        </div>
        <div class="form-group{{ $icsErrors->has('sync_interval') ? ' has-error' : '' }}">
            <label for="ljpc-ics-interval" class="col-sm-2 control-label">{{ __('Check for changes') }}</label>
            <div class="col-sm-6">
                <select id="ljpc-ics-interval" class="form-control input-sized" name="sync_interval">
                    @foreach ($page::syncIntervals() as $minutes => $label)
                        <option value="{{ $minutes }}"{{ $interval === (string) $minutes ? ' selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="help-block">{{ __('Events in the feed are read-only in FreeScout; they change in the app that shares the feed.') }}</p>
                @include('partials/field_error', ['field' => 'sync_interval', 'errors' => $icsErrors])
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-6 col-sm-offset-2">
                <button type="submit" class="btn btn-primary">{{ __('Create calendar') }}</button>
                <a href="{{ $page::url() }}" class="btn btn-link">{{ __('Cancel') }}</a>
            </div>
        </div>
    </form>
</div>

<div class="ljpc-type-form{{ $type === 'account' ? '' : ' hidden' }}" data-ljpc-type-form="account">
    @if ($accounts->isEmpty())
        <p class="margin-top">
            {{ __('No connected account can be used right now. Connect a Google, Microsoft 365 or CalDAV account first, or check the accounts that need attention.') }}
            <a href="{{ $page::connectionsUrl() }}">{{ __('Open the Connections tab') }}</a>
        </p>
    @else
        {{-- Opens the account's pick screen on the Connections tab, where its calendars are ticked. --}}
        <form method="GET" action="{{ $page::url() }}" class="form-horizontal margin-top" data-ljpc-account-form>
            <input type="hidden" name="tab" value="connections">
            <input type="hidden" name="pick" value="1">
            <div class="form-group">
                <label for="ljpc-account" class="col-sm-2 control-label">{{ __('Account') }}</label>
                <div class="col-sm-6">
                    <select id="ljpc-account" class="form-control input-sized" name="connection" required>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ __($page::PROVIDERS[$account->provider] ?? $account->provider) }}: {{ (string) $account->account }}</option>
                        @endforeach
                    </select>
                    <p class="help-block">
                        {{ __('Next, tick the calendars of this account that FreeScout shows.') }}
                        <a href="{{ $page::connectionsUrl() }}">{{ __('Connect another account') }}</a>
                    </p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-6 col-sm-offset-2">
                    <button type="submit" class="btn btn-primary">{{ __('Choose calendars') }}</button>
                    <a href="{{ $page::url() }}" class="btn btn-link">{{ __('Cancel') }}</a>
                </div>
            </div>
        </form>
    @endif
</div>
