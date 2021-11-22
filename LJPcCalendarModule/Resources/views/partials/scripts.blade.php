<script src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/jquery-3.6.0.min.js' }}"></script>
<script src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/moment-with-locales.min.js' }}"></script>
<script src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/tui-code-snippet.min.js' }}"></script>
<script src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/tui-time-picker.min.js' }}"></script>
<script src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/tui-date-picker.min.js' }}"></script>
<script src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/tui-calendar.min.js' }}"></script>
<script src="{{ Module::getPublicPath(LJPC_CALENDARS_MODULE).'/js/calendar-app.js' }}"></script>
<script>
    moment.locale('{{Helper::getRealAppLocale()}}');

    const dayTranslations = ['{{__('Sun')}}', '{{__('Mon')}}', '{{__('Tue')}}', '{{__('Wed')}}', '{{__('Thu')}}', '{{__('Fri')}}', '{{__('Sat')}}'];
    const translations = {
        popupIsAllDay: '{{__('All Day')}}',
        popupStateFree: '{{__('Free')}}',
        popupStateBusy: '{{__('Busy')}}',
        popupSave: '{{__('Save')}}',
        popupUpdate: '{{__('Update')}}',
        popupDetailLocation: '{{__('Location')}}',
        popupDetailUser: '{{__('User')}}',
        popupDetailState: '{{__('Availability')}}',
        popupDetailRepeat: '{{__('Repeat')}}',
        popupDetailBody: '{{__('Body')}}',
        popupEdit: '{{__('Edit')}}',
        popupDelete: '{{__('Delete')}}',
        dateFormat: '{{__('DD-MM-YYYY')}}',
        monthFormat: '{{__('MM-YYYY')}}',
        timeFormat: '{{__('hh:mm a')}}',
        titlePlaceholder: '{{__('Subject')}}',
        locationPlaceholder: '{{__('Location')}}',
        startDatePlaceholder: '{{__('Start date')}}',
        endDatePlaceholder: '{{__('End date')}}',
        alldayTitle: '{{__('ALL DAY')}}',
    }

    const ajaxHelpers = {
        'csrfToken': '{{ csrf_token() }}',
        'url': '{{route('ljpccalendarmodule.ajax')}}'
    }

    const calendars = {!! $calendars !!};

    for (let i = 0; i < calendars.length; i++) {
        let calendar = new CalendarInfo();
        calendar.id = calendars[i]['id'];
        calendar.external = calendars[i]['external'];
        calendar.name = calendars[i]['name'];
        calendar.color = calendars[i]['colors']['textColor'];
        calendar.bgColor = calendars[i]['colors']['backgroundColor'];
        calendar.dragBgColor = calendars[i]['colors']['backgroundColor'];
        calendar.borderColor = calendars[i]['colors']['borderColor'];
        addCalendar(calendar);
    }

</script>

