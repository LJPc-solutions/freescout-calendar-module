<div class="dash-card ljpc_move_dash_card dash-card-calendar">
    <div class="dash-card-content">
        <h3 class="text-wrap-break "><a href="{{route('ljpccalendarmodule.index')}}"
                                        class="mailbox-name">{{ __('Calendar') }}</a></h3>
        <div class="dash-card-link text-truncate">
            <a href="{{route('ljpccalendarmodule.index')}}"
               class="text-truncate help-link">{{__('Active and upcoming')}}</a>
        </div>

        <div class="dash-card-list dash-calendar-contents">

        </div>
    </div>

    <div class="dash-card-footer">
        <div class="btn-group btn-group-justified btn-group-rounded">
            <a href="{{route('ljpccalendarmodule.index')}}" class="btn btn-trans" data-toggle="tooltip" title=""
               data-original-title="{{__('Open calendar')}}"><i class="glyphicon glyphicon-arrow-right"></i></a>
        </div>
    </div>
</div>

<script {!! \Helper::cspNonceAttr() !!}>
    const translations = {
        allDay: '{{__('All Day')}}',
        dateFormat: '{{__('DD-MM-YYYY')}}',
        timeFormat: '{{__('hh:mm a')}}',
        today: '{{__('Today')}}'
    };

    const calendars = {!! $calendars !!};
</script>
<script {!! \Helper::cspNonceAttr() !!} src="{{Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/js/jquery-3.6.0.min.js'}}"></script>
<script {!! \Helper::cspNonceAttr() !!} src="{{Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/js/moment-with-locales.min.js'}}"></script>
<script {!! \Helper::cspNonceAttr() !!}>moment.locale('{{Helper::getRealAppLocale()}}');</script>
<script {!! \Helper::cspNonceAttr() !!} src="{{Module::getPublicPath( LJPC_CALENDARS_MODULE ) . '/js/calendar-dashboard.js'}}"></script>
