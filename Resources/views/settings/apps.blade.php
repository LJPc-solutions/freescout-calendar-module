@php
    // An app form shows the posted values and errors only when it was the one posted (old('app')); the secret is never shown.
    $google = $apps['google'];
    $googleMine = old('app') === 'google';
    $googleErrors = $googleMine ? $errors->google : $none;
    $microsoft = $apps['microsoft'];
    $microsoftMine = old('app') === 'microsoft';
    $microsoftErrors = $microsoftMine ? $errors->microsoft : $none;
@endphp
<div class="panel panel-default ljpc-provider" id="ljpc-app-google">
    <div class="panel-heading">
        <strong>{{ __('Google Calendar') }}</strong>
        <a href="{{ $page::wikiUrl('Google-Calendar') }}" target="_blank" rel="noopener noreferrer" class="ljpc-wiki">{{ __('How to create the Google app') }}</a>
    </div>
    <div class="panel-body">
        <p class="text-help">{{ __('Google accounts connect through an app you create once in the Google Cloud console. Every Google account then signs in with it.') }}</p>
        <form class="form-horizontal" method="POST" action="{{ route('ljpccalendarmodule.settings.apps', ['provider' => 'google']) }}">
            {{ csrf_field() }}
            <div class="form-group{{ $googleErrors->has('client_id') ? ' has-error' : '' }}">
                <label for="ljpc-google-client-id" class="col-sm-3 control-label">{{ __('Client id') }}</label>
                <div class="col-sm-8">
                    <input id="ljpc-google-client-id" type="text" class="form-control" name="client_id" value="{{ $googleMine ? $page::oldString('client_id') : $google['client_id'] }}" maxlength="191" required autocomplete="off">
                    @include('partials/field_error', ['field' => 'client_id', 'errors' => $googleErrors])
                </div>
            </div>
            <div class="form-group{{ $googleErrors->has('client_secret') ? ' has-error' : '' }}">
                <label for="ljpc-google-secret" class="col-sm-3 control-label">{{ __('Client secret') }}</label>
                <div class="col-sm-8">
                    <input id="ljpc-google-secret" type="password" class="form-control" name="client_secret" value="" maxlength="500" autocomplete="new-password">
                    <p class="help-block">{{ $google['has_secret'] ? __('A secret is saved. Leave it empty to keep it.') : __('No secret is saved yet.') }}</p>
                    @include('partials/field_error', ['field' => 'client_secret', 'errors' => $googleErrors])
                </div>
            </div>
            <div class="form-group">
                <label for="ljpc-google-redirect" class="col-sm-3 control-label">{{ __('Redirect URL') }}</label>
                <div class="col-sm-8">
                    <div class="input-group">
                        <input id="ljpc-google-redirect" type="text" class="form-control" readonly value="{{ $google['redirect_url'] }}">
                        <span class="input-group-btn">
                            <button type="button" class="btn btn-default hidden" data-ljpc-copy="ljpc-google-redirect" data-ljpc-copied="{{ __('Copied') }}">{{ __('Copy') }}</button>
                        </span>
                    </div>
                    <p class="help-block">{{ __('Add this address to the authorized redirect URIs of the app.') }}</p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-8 col-sm-offset-3">
                    <button type="submit" class="btn btn-default">{{ __('Save Google app') }}</button>
                </div>
            </div>
        </form>
        <div class="ljpc-connect-action">
            @if ($google['ready'])
                <a href="{{ route('ljpccalendarmodule.oauth.start', ['provider' => 'google']) }}" class="btn btn-primary">{{ __('Connect a Google account') }}</a>
            @else
                <button type="button" class="btn btn-primary" disabled>{{ __('Connect a Google account') }}</button>
                <p class="help-block">{{ __('Save the client id and secret first.') }}</p>
            @endif
        </div>
    </div>
</div>

<div class="panel panel-default ljpc-provider" id="ljpc-app-microsoft">
    <div class="panel-heading">
        <strong>{{ __('Microsoft 365') }}</strong>
        <a href="{{ $page::wikiUrl('Microsoft-365') }}" target="_blank" rel="noopener noreferrer" class="ljpc-wiki">{{ __('How to register the Microsoft app') }}</a>
    </div>
    <div class="panel-body">
        <p class="text-help">{{ __('Microsoft 365 accounts connect through an app registration in Microsoft Entra. Its accounts can also add the calendars of shared mailboxes they have access to.') }}</p>
        <form class="form-horizontal" method="POST" action="{{ route('ljpccalendarmodule.settings.apps', ['provider' => 'microsoft']) }}">
            {{ csrf_field() }}
            <div class="form-group{{ $microsoftErrors->has('tenant') ? ' has-error' : '' }}">
                <label for="ljpc-microsoft-tenant" class="col-sm-3 control-label">{{ __('Directory (tenant) id') }}</label>
                <div class="col-sm-8">
                    <input id="ljpc-microsoft-tenant" type="text" class="form-control" name="tenant" value="{{ $microsoftMine ? $page::oldString('tenant') : $microsoft['tenant'] }}" maxlength="100" required autocomplete="off">
                    <p class="help-block">{{ __('The tenant id or a domain of your organization. "organizations" only works for an app that accepts accounts of other organizations.') }}</p>
                    @include('partials/field_error', ['field' => 'tenant', 'errors' => $microsoftErrors])
                </div>
            </div>
            <div class="form-group{{ $microsoftErrors->has('client_id') ? ' has-error' : '' }}">
                <label for="ljpc-microsoft-client-id" class="col-sm-3 control-label">{{ __('Application (client) id') }}</label>
                <div class="col-sm-8">
                    <input id="ljpc-microsoft-client-id" type="text" class="form-control" name="client_id" value="{{ $microsoftMine ? $page::oldString('client_id') : $microsoft['client_id'] }}" maxlength="191" required autocomplete="off">
                    @include('partials/field_error', ['field' => 'client_id', 'errors' => $microsoftErrors])
                </div>
            </div>
            <div class="form-group{{ $microsoftErrors->has('client_secret') ? ' has-error' : '' }}">
                <label for="ljpc-microsoft-secret" class="col-sm-3 control-label">{{ __('Client secret') }}</label>
                <div class="col-sm-8">
                    <input id="ljpc-microsoft-secret" type="password" class="form-control" name="client_secret" value="" maxlength="500" autocomplete="new-password">
                    <p class="help-block">{{ $microsoft['has_secret'] ? __('A secret is saved. Leave it empty to keep it.') : __('No secret is saved yet.') }}</p>
                    @include('partials/field_error', ['field' => 'client_secret', 'errors' => $microsoftErrors])
                </div>
            </div>
            <div class="form-group{{ $microsoftErrors->has('secret_expires_at') ? ' has-error' : '' }}">
                <label for="ljpc-microsoft-expires" class="col-sm-3 control-label">{{ __('Secret expires on') }}</label>
                <div class="col-sm-8">
                    <input id="ljpc-microsoft-expires" type="date" class="form-control input-sized" name="secret_expires_at" value="{{ $microsoftMine ? $page::oldString('secret_expires_at') : (string) $microsoft['secret_expires_at'] }}">
                    <p class="help-block">{{ __('Optional. Administrators get an email 30 days before the secret expires, so a new one is in place in time.') }}</p>
                    @include('partials/field_error', ['field' => 'secret_expires_at', 'errors' => $microsoftErrors])
                </div>
            </div>
            <div class="form-group">
                <label for="ljpc-microsoft-redirect" class="col-sm-3 control-label">{{ __('Redirect URL') }}</label>
                <div class="col-sm-8">
                    <div class="input-group">
                        <input id="ljpc-microsoft-redirect" type="text" class="form-control" readonly value="{{ $microsoft['redirect_url'] }}">
                        <span class="input-group-btn">
                            <button type="button" class="btn btn-default hidden" data-ljpc-copy="ljpc-microsoft-redirect" data-ljpc-copied="{{ __('Copied') }}">{{ __('Copy') }}</button>
                        </span>
                    </div>
                    <p class="help-block">{{ __('Add this address as a Web redirect URI of the app registration.') }}</p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-8 col-sm-offset-3">
                    <button type="submit" class="btn btn-default">{{ __('Save Microsoft app') }}</button>
                </div>
            </div>
        </form>
        <div class="ljpc-connect-action">
            @if ($microsoft['ready'])
                <a href="{{ route('ljpccalendarmodule.oauth.start', ['provider' => 'microsoft']) }}" class="btn btn-primary">{{ __('Connect a Microsoft account') }}</a>
            @else
                <button type="button" class="btn btn-primary" disabled>{{ __('Connect a Microsoft account') }}</button>
                <p class="help-block">{{ __('Save the tenant, client id and secret first.') }}</p>
            @endif
        </div>
    </div>
</div>
