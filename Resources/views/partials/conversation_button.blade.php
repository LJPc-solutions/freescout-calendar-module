<span class="conv-action glyphicon glyphicon-calendar ljpc-add-to-calendar" role="button" tabindex="0" data-toggle="tooltip" data-placement="bottom" title="{{ __('Add to calendar') }}" aria-label="{{ __('Add to calendar') }}"></span>
<script type="application/json" id="ljpc-conversation-config">{!! \Modules\LJPcCalendarModule\Http\FrontendConfig::json(\Modules\LJPcCalendarModule\Http\FrontendConfig::conversation($user, $conversation)) !!}</script>
{!! \Modules\LJPcCalendarModule\Http\Assets::style('conversation') !!}
{!! \Modules\LJPcCalendarModule\Http\Assets::script('conversation') !!}
