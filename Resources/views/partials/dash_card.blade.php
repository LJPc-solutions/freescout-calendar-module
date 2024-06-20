<div class="margin-top">
    <div class="dash-card dash-card-calendar">
        <div class="dash-card-content">
            <h3 class="text-wrap-break "><a href="{{route('ljpccalendarmodule.index')}}"
                                            class="mailbox-name">{{ __('Calendar') }}</a></h3>
            <div class="dash-card-link text-truncate">
                <a href="{{route('ljpccalendarmodule.index')}}"
                   class="text-truncate help-link">{{__('Active and upcoming')}}</a>
            </div>

            <div class="dash-card-list dash-calendar-contents">
                @foreach($events as $event)
                    @include('calendar::partials.dash_card_event', ['event' => $event])
                @endforeach
            </div>
        </div>

        <div class="dash-card-footer" style="margin-top:6px;">
            <div class="btn-group btn-group-justified btn-group-rounded">
                <a href="{{route('ljpccalendarmodule.index')}}" class="btn btn-trans" data-toggle="tooltip" title=""
                   data-original-title="{{__('Open calendar')}}"><i class="glyphicon glyphicon-arrow-right"></i></a>
            </div>
        </div>
    </div>
</div>
