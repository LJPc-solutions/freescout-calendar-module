<li class="ljpc-invite" data-ljpc-invite="{{ $id }}">
    <div class="ljpc-invite-heading"><i class="glyphicon glyphicon-calendar" aria-hidden="true"></i> {{ $heading }}</div>
    <div class="ljpc-invite-title">{{ $title }}@if ($repeats) <span class="ljpc-invite-repeats">{{ __('Repeats') }}</span>@endif</div>
    <dl class="ljpc-invite-details">
        <dt>{{ __('When') }}</dt>
        <dd>@if ($before)<del>{{ $before }}</del> <i class="glyphicon glyphicon-arrow-right" aria-label="{{ __('becomes') }}"></i> @endif{{ $when }}</dd>
        @if ($organizer)
            <dt>{{ __('Organizer') }}</dt>
            <dd>{{ $organizer }}</dd>
        @endif
        @if ($location !== '')
            <dt>{{ __('Location') }}</dt>
            <dd>{{ $location }}</dd>
        @endif
    </dl>
    @if ($occurrence)
        <p class="ljpc-invite-note">{{ $occurrence }}</p>
    @endif
    @foreach ($warnings as $warning)
        <p class="ljpc-invite-warning"><i class="glyphicon glyphicon-warning-sign" aria-hidden="true"></i> {{ $warning }}</p>
    @endforeach
    @if ($status || $link)
        <p class="ljpc-invite-status">{{ $status }}@if ($link) <a href="{{ $link }}">{{ __('View event') }}</a>@endif</p>
    @endif
    @if ($answered)
        <p class="ljpc-invite-status">{{ $answered }}</p>
    @endif
    @if ($button || $answers)
        <form class="ljpc-invite-form" data-ljpc-invite-form action="{{ route('ljpccalendarmodule.api.invites.apply', ['id' => $id]) }}" method="post">
            @if ($calendars)
                <label class="sr-only" for="ljpc-invite-calendar-{{ $id }}">{{ __('Calendar') }}</label>
                <select class="form-control input-sm" name="calendar_id" id="ljpc-invite-calendar-{{ $id }}">
                    @foreach ($calendars as $calendar)
                        <option value="{{ $calendar->id }}">{{ $calendar->name }}</option>
                    @endforeach
                </select>
            @endif
            @if ($button)
                <button type="submit" class="btn btn-primary btn-sm">{{ $button }}</button>
            @endif
            @if ($answers)
                <span class="ljpc-invite-answer">
                    <span id="ljpc-invite-answer-{{ $id }}">{{ __('Answer the organizer') }}</span>
                    <span class="btn-group btn-group-sm" role="group" aria-labelledby="ljpc-invite-answer-{{ $id }}">
                        @foreach ($answers as $answer => $label)
                            <button type="submit" class="btn btn-default" name="response" value="{{ $answer }}" formaction="{{ route('ljpccalendarmodule.api.invites.answer', ['id' => $id]) }}">{{ $label }}</button>
                        @endforeach
                    </span>
                </span>
            @endif
        </form>
    @endif
</li>
