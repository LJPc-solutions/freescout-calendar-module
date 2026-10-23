{{-- Its own card row keeps the layout intact; the script moves the card to the front of the mailbox cards' row, as 2.x did. --}}
<div class="dash-cards margin-top ljpc-dash-cards">
    <div class="dash-card dash-card-calendar">
        <div class="dash-card-content">
            <h3 class="text-wrap-break"><a href="{{ route('ljpccalendarmodule.index') }}" class="mailbox-name">{{ __('Calendar') }}</a></h3>
            <div class="dash-card-link text-truncate"><span class="text-help">{{ __('Next 7 days') }}</span></div>
            <ul class="dash-card-list ljpc-dash-list">
                @foreach ($events as $event)
                    @include('calendar::partials.dash_card_event', ['event' => $event])
                @endforeach
            </ul>
        </div>
        <div class="dash-card-footer">
            <div class="btn-group btn-group-justified btn-group-rounded">
                <a href="{{ route('ljpccalendarmodule.index') }}" class="btn btn-trans" data-toggle="tooltip" title="{{ __('Open calendar') }}" aria-label="{{ __('Open calendar') }}"><i class="glyphicon glyphicon-arrow-right" aria-hidden="true"></i></a>
            </div>
        </div>
    </div>
</div>
<script{!! \Helper::cspNonceAttr() !!}>
document.addEventListener('DOMContentLoaded', function () {
    var row = document.querySelector('.ljpc-dash-cards');
    var mailboxes = document.querySelector('.dash-cards:not(.ljpc-dash-cards)');
    if (row && mailboxes) {
        mailboxes.insertBefore(row.firstElementChild, mailboxes.firstChild);
        row.parentNode.removeChild(row);
    }
});
</script>
