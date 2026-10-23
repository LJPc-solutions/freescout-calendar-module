<li class="ljpc-dash-item" title="{{ $event['start']['full'] }}">
    @if ($event['linkable'])
        <a href="{{ $event['url'] }}" class="ljpc-dash-title">{{ $event['title'] }}</a>
    @else
        <span class="ljpc-dash-title">{{ $event['title'] }}</span>
    @endif
    <span class="ljpc-dash-meta">
        <span class="ljpc-dash-calendar"><span class="ljpc-dash-swatch" style="background-color: {{ $event['calendar']['color'] }}" aria-hidden="true"></span> {{ $event['calendar']['name'] }}</span>
        <span class="ljpc-dash-when">{{ $event['start']['time'] }}, {{ $event['start']['diff'] }}</span>
    </span>
</li>
