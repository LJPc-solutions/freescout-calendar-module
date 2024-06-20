<div id="calendar-settings-app"></div>

@section('javascripts')
    @include('calendar::partials/translations')
    <script {!! \Helper::cspNonceAttr() !!}>
        window.ljpccalendarmodule = {
            csrf: '{{ csrf_token() }}'
        };
    </script>
    <script type="module" src="{!! $settings['js'] !!}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection

@section('stylesheets')
    <link rel="stylesheet" href="{!! $settings['css'] !!}">
@endsection

@include('partials/editor')
