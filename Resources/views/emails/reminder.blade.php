@extends('emails/user/layouts/system')

@section('content')
    <div style="color:#2a3b47;font:500 20px/26px 'Helvetica Neue',Helvetica,Arial,sans-serif">{{ $title }}</div>

    <p style="color:#72808e;font:400 16px/26px 'Helvetica Neue',Helvetica,Arial,sans-serif;padding:0">
        {{ $when }}<br>
        {{ __('Calendar: :calendar', ['calendar' => $calendar]) }}
        @if ($location !== '')
            <br>{{ __('Where: :location', ['location' => $location]) }}
        @endif
        @if ($conversation)
            <br>{{ __('Conversation') }}: <a href="{{ $conversation->url() }}">#{{ $conversation->number }} {{ $conversation->getSubject() }}</a>
        @endif
    </p>

    <p style="font:400 16px/26px 'Helvetica Neue',Helvetica,Arial,sans-serif;padding:0">
        <a href="{{ $link }}">{{ __('View event') }}</a>
    </p>
@endsection

@section('footer')
    {{ __('You get this reminder because you added this event or its conversation is assigned to you. Change or turn off the reminder in the event, or the default in the calendar\'s settings.') }}
@endsection
