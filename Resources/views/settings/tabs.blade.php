@php $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class; @endphp
<ul class="nav nav-tabs ljpc-tabs">
    @foreach (['calendars' => [__('Calendars'), $page::url()], 'connections' => [__('Connections'), $page::connectionsUrl()]] as $name => [$label, $url])
        @if ($tab === $name)
            <li class="active"><a href="{{ $url }}" aria-current="page">{{ $label }}</a></li>
        @else
            <li><a href="{{ $url }}">{{ $label }}</a></li>
        @endif
    @endforeach
</ul>
