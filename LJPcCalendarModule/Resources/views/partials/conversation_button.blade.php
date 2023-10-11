<span class="conv-add-to-calendar conv-action glyphicon glyphicon-calendar" data-toggle="tooltip"
      data-placement="bottom" title="{{ __("Add to calendar") }}" aria-label="{{ __("Add to calendar") }}"
      role="button"></span>
<script>
    const addToCalendar = {
        'route': '{{route('ljpccalendarmodule.ajax')}}',
        'translationBusy': '{{__('Busy')}}',
        'html': `
    <div>
            <div class="text-larger margin-top-10">{{__('Add to calendar')}}</div>
            <div class="form-group">
                <label for="calendar-select">{{__('Calendar')}}*:</label>
                <select required="required" class="form-control" id="calendar-select">
                    @foreach($calendars as $calendar)
        <option value="{{$calendar->id}}">{{$calendar->name}}</option>
                    @endforeach
        </select>
    </div>
    <div class="form-group">
        <label for="calendar-item-title">{{__('Title')}}*:</label>
                <input required="required" type="text" class="form-control" id="calendar-item-title" value="{{$conversation->user? '[' . $conversation->user->getFullName() . '] ': ''}}{{$conversation->getSubject()}}">
            </div>
            <div class="form-group">
                <label for="calendar-item-datetime">{{__('Date and time')}}*:</label>
                <input required="required" type="datetime-local" class="form-control" id="calendar-item-datetime" value={{date('Y-m-d\TH:i')}}>
            </div>
            <div class="form-group margin-top">
                <button class="btn btn-primary add-to-calendar-ok">{{__('Add')}}</button>
                <button class="btn btn-link" data-dismiss="modal">{{__('Cancel')}}</button>
            </div>

    </div>
    `
    }
</script>
<script {!! \Helper::cspNonceAttr() !!} src="{{Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/js/jquery-3.6.0.min.js'}}"></script>
<script {!! \Helper::cspNonceAttr() !!} src="{{Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/js/moment-with-locales.min.js'}}"></script>
<script {!! \Helper::cspNonceAttr() !!}>moment.locale('{{Helper::getRealAppLocale()}}');</script>
<script {!! \Helper::cspNonceAttr() !!} src="{{Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/js/calendar-conversation.js'}}"></script>
