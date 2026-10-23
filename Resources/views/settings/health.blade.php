@if ($health)
    <div class="alert alert-warning ljpc-health" role="status">
        <p><strong>{{ __('These calendar problems need an administrator:') }}</strong></p>
        <ul>
            @foreach ($health as $problem)
                <li><a href="{{ $problem['url'] }}">{{ $problem['text'] }}</a></li>
            @endforeach
        </ul>
    </div>
@endif
