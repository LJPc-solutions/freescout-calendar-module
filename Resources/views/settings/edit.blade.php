@php
    $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class;
    $posted = $page::posted();
    $enabled = $posted ? (bool) old('enabled') : (bool) $calendar->enabled;
@endphp
<p><a href="{{ $page::url() }}">{{ __('All calendars') }}</a></p>
<h3 class="ljpc-title">
    <span class="ljpc-swatch" style="background-color: {{ $page::color($calendar->color) }}" aria-hidden="true"></span>
    {{ $calendar->name }}
    <small>{{ __($page::PROVIDERS[$calendar->provider] ?? $calendar->provider) }}</small>
</h3>

@foreach (['calendar', 'form'] as $general)
    @if ($errors->has($general))
        <div class="alert alert-danger">{{ $errors->first($general) }}</div>
    @endif
@endforeach

@if ($calendar->export_token === null)
    <div class="alert alert-warning">{{ __('This calendar has not been upgraded to version 3 yet, so it can\'t be changed. The upgrade report above shows why. You can still delete it.') }}</div>
@else
    <form class="form-horizontal margin-top" method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]) }}">
        {{ csrf_field() }}

        <h4 class="ljpc-section">{{ __('General') }}</h4>
        <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
            <label for="ljpc-name" class="col-sm-2 control-label">{{ __('Name') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-name" type="text" class="form-control input-sized" name="name" value="{{ $page::oldString('name', $calendar->name) }}" maxlength="191" required>
                @include('partials/field_error', ['field' => 'name'])
            </div>
        </div>
        <div class="form-group{{ $errors->has('color') ? ' has-error' : '' }}">
            <label for="ljpc-color" class="col-sm-2 control-label">{{ __('Color') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-color" type="color" class="form-control ljpc-color" name="color" value="{{ $page::color(old('color', $calendar->color)) }}" list="ljpc-swatches" required>
                <datalist id="ljpc-swatches">
                    @foreach ($page::SWATCHES as $swatch)
                        <option value="{{ $swatch }}"></option>
                    @endforeach
                </datalist>
                @include('partials/field_error', ['field' => 'color'])
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-6 col-sm-offset-2">
                <input type="hidden" name="enabled" value="0">
                <label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" @if ($enabled) checked @endif> {{ __('Show this calendar to agents') }}</label>
            </div>
        </div>
        <div class="form-group{{ $errors->has('display_priority') ? ' has-error' : '' }}">
            <label for="ljpc-priority" class="col-sm-2 control-label">{{ __('Display order') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-priority" type="number" class="form-control input-sized" name="display_priority" min="0" max="9999" value="{{ $page::oldString('display_priority', $calendar->display_priority) }}" required>
                <p class="help-block">{{ __('Lower numbers come first in the all-day row and in the list of calendars.') }}</p>
                @include('partials/field_error', ['field' => 'display_priority'])
            </div>
        </div>
        <div class="form-group{{ $errors->has('timezone') ? ' has-error' : '' }}">
            <label for="ljpc-timezone" class="col-sm-2 control-label">{{ __('Time zone') }}</label>
            <div class="col-sm-6">
                @php $timezone = $posted ? $page::oldString('timezone') : (string) $calendar->timezone; @endphp
                <select id="ljpc-timezone" class="form-control input-sized" name="timezone">
                    <option value="">{{ __('FreeScout default (:timezone)', ['timezone' => config('app.timezone')]) }}</option>
                    @foreach (\Modules\LJPcCalendarModule\Http\CalendarForm::timezones($calendar) as $identifier)
                        <option value="{{ $identifier }}" @if ($timezone === $identifier) selected @endif>{{ $identifier }}</option>
                    @endforeach
                </select>
                <p class="help-block">{{ __('Used for events that have no time zone of their own.') }}</p>
                @include('partials/field_error', ['field' => 'timezone'])
            </div>
        </div>
        <div class="form-group{{ $errors->has('default_reminder_minutes') ? ' has-error' : '' }}">
            <label for="ljpc-reminder" class="col-sm-2 control-label">{{ __('Default reminder') }}</label>
            <div class="col-sm-6">
                @php $reminder = $posted ? $page::oldString('default_reminder_minutes') : (string) $calendar->default_reminder_minutes; @endphp
                <select id="ljpc-reminder" class="form-control input-sized" name="default_reminder_minutes">
                    <option value="">{{ __('No reminder') }}</option>
                    @foreach ($page::reminderOptions() as $minutes => $label)
                        <option value="{{ $minutes }}" @if ($reminder === (string) $minutes) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
                @include('partials/field_error', ['field' => 'default_reminder_minutes'])
            </div>
        </div>
        <div class="form-group{{ $errors->has('title_template') ? ' has-error' : '' }}">
            <label for="ljpc-title-template" class="col-sm-2 control-label">{{ __('Title template') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-title-template" type="text" class="form-control" name="title_template" maxlength="191" value="{{ $page::oldString('title_template', $calendar->title_template) }}" data-ljpc-template-input>
                <div class="ljpc-chips margin-top-10">
                    <button type="button" class="btn btn-default btn-xs" data-ljpc-insert="{{ $page::token() }}">{{ __('Title') }}</button>
                    @foreach ($calendar->fieldDefinitions() as $field)
                        <button type="button" class="btn btn-default btn-xs" data-ljpc-insert="{{ $page::token($field['id']) }}">{{ $field['name'] }}</button>
                    @endforeach
                </div>
                <p class="help-block ljpc-template-preview" data-ljpc-template-preview aria-live="polite"></p>
                <p class="help-block">{{ __('Optional. Builds every event title from the typed title and custom fields; click a button to insert it. Leave empty to use the typed title as it is. Save new fields first to use them here.') }}</p>
                @include('partials/field_error', ['field' => 'title_template'])
            </div>
        </div>

        @unless ($calendar->isLocal())
            @include('calendar::settings.sync')
        @endunless

        <h4 class="ljpc-section">{{ __('Custom fields') }}</h4>
        @include('calendar::settings.fields')

        <h4 class="ljpc-section">{{ __('Who can use this calendar') }}</h4>
        @include('calendar::settings.permissions')

        <div class="form-group margin-top">
            <div class="col-sm-6 col-sm-offset-2">
                <button type="submit" class="btn btn-primary">{{ __('Save calendar') }}</button>
            </div>
        </div>
        {{-- Must stay the form's last field: PHP drops fields beyond max_input_vars, and CalendarForm refuses a form without it. --}}
        <input type="hidden" name="form_complete" value="1">
    </form>
    @unless ($calendar->isLocal())
        {{-- The "Sync now" button sits in the sync section above and submits this form through its form attribute. --}}
        <form id="ljpc-sync-now" method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.sync', ['id' => $calendar->id]) }}" data-ljpc-sync-form>
            {{ csrf_field() }}
        </form>
    @endunless

    <h4 class="ljpc-section">{{ __('Subscribe from other apps') }}</h4>
    <p class="text-help">{{ __('Anyone with one of these links can read the calendar, so share them with care. Making a new link stops the old one.') }}</p>
    @foreach (['full' => [__('All details'), $calendar->export_token], 'busy' => [__('Only free and busy'), $calendar->export_busy_token]] as $which => [$label, $token])
        <div class="form-group ljpc-export">
            <label for="ljpc-export-{{ $which }}" class="control-label">{{ $label }}</label>
            <div class="input-group">
                <input id="ljpc-export-{{ $which }}" type="text" class="form-control" readonly value="{{ route('ljpccalendarmodule.export', ['id' => $calendar->id, 'token' => $token]) }}">
                <span class="input-group-btn">
                    <button type="button" class="btn btn-default hidden" data-ljpc-copy="ljpc-export-{{ $which }}" data-ljpc-copied="{{ __('Copied') }}">{{ __('Copy') }}</button>
                </span>
            </div>
            <form method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.tokens', ['id' => $calendar->id]) }}" data-ljpc-confirm="{{ __('Make a new link? Apps that use the old link stop receiving updates.') }}">
                {{ csrf_field() }}
                <input type="hidden" name="which" value="{{ $which }}">
                <button type="submit" class="btn btn-link ljpc-new-link">{{ __('Make a new link') }}</button>
            </form>
        </div>
    @endforeach
@endif

<h4 class="ljpc-section">{{ __('Delete calendar') }}</h4>
<form method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.delete', ['id' => $calendar->id]) }}" data-ljpc-confirm="{{ __('Delete this calendar and all its events? This can\'t be undone.') }}">
    {{ csrf_field() }}
    <p class="text-help">{{ __('Deletes the calendar and all its events. Conversations keep their notes about events.') }}</p>
    <button type="submit" class="btn btn-danger">{{ __('Delete calendar') }}</button>
</form>
