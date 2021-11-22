<form class="form-horizontal margin-top margin-bottom" method="POST" action="" enctype="multipart/form-data">
    {{ csrf_field() }}

    <div class="form-group">
        <label for="" class="col-sm-2 control-label">{{ __('Calendars') }}</label>

        <div class="col-sm-6">
            <textarea class="form-control" name="settings[calendar_list]"
                      rows="5">{{ old('settings[calendar_list]', $settings['calendar_list']) }}</textarea>
            <p class="form-help">{{__('One calendar name per line. External ICS files are also supported.')}}</p>
        </div>
    </div>

    <div class="form-group margin-top margin-bottom">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">
                {{ __('Save') }}
            </button>
        </div>
    </div>
</form>

@include('partials/editor')
