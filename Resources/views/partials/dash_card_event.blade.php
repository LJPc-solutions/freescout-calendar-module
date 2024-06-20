<div class="dash-card-list-item" style="display: flex; flex-direction: column; padding-bottom: 9px;" title="{{$event['start']['full']}}">
    <div style="display: flex; justify-content: space-between; height: 20px;">
        <small style="overflow: hidden;text-overflow: ellipsis;white-space: nowrap;margin-right: 5px;">{{$event['title']}}</small>
        <span class="has-value" style="text-transform:lowercase; font-weight:400; font-size: 85%; position: relative; top:3px;">{{$event['start']['time']}}</span>
    </div>
    <div style="display: flex; justify-content: space-between; height: 20px;">
        <span class="badge" style="background-color: {{$event['calendar']['color']}}">{{$event['calendar']['name']}}</span>
        <span class="has-value" style="text-transform:lowercase; font-weight:700;">{{$event['start']['diff']}}</span>
    </div>
</div>
