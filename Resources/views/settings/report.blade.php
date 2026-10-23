@if (!$migration['finished'] || $migration['problems'])
    <div class="panel {{ $migration['finished'] ? 'panel-default' : 'panel-warning' }} ljpc-report">
        <div class="panel-heading">
            <strong>{{ $migration['finished'] ? __('Upgrade report') : __('The upgrade to version 3 has not finished') }}</strong>
        </div>
        <div class="panel-body">
            @if (!$migration['finished'])
                <p>{{ __('Agents can\'t use the calendar until the upgrade has finished. Solve or skip the events below, then run the upgrade again with "Migrate DB" under System tools.') }}</p>
                <p><a href="{{ route('system.tools') }}" class="btn btn-default">{{ __('Open System tools') }}</a></p>
            @endif

            @if ($errors->has('item'))
                <div class="alert alert-danger">{{ $errors->first('item') }}</div>
            @endif

            @if ($migration['pending'])
                <h4>{{ __('Events still waiting to be converted') }}</h4>
                <p class="text-help">{{ __('Skipping an event leaves it out of version 3. Its data stays in this report, so you can add it again by hand.') }}</p>
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ __('Title') }}</th>
                            <th>{{ __('Start') }}</th>
                            <th>{{ __('Calendar') }}</th>
                            <th><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($migration['pending'] as $item)
                            <tr>
                                <td>{{ $item['id'] }}</td>
                                <td>{{ $item['title'] }}</td>
                                <td>{{ $item['start'] }}</td>
                                <td>{{ $item['calendar'] ?? __('A deleted calendar') }}</td>
                                <td class="text-right">
                                    <form method="POST" action="{{ route('ljpccalendarmodule.settings.migration.skip', ['id' => $item['id']]) }}" data-ljpc-confirm="{{ __('Skip this event? It won\'t be in the calendar.') }}">
                                        {{ csrf_field() }}
                                        <button type="submit" class="btn btn-default btn-xs">{{ __('Skip') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <details {{ $migration['finished'] ? '' : 'open' }}>
                <summary>{{ __('Report entries (:count)', ['count' => count($migration['entries'])]) }}</summary>
                <ul class="list-unstyled ljpc-report-entries">
                    @foreach ($migration['entries'] as $entry)
                        <li>
                            <span class="label label-{{ ['error' => 'danger', 'warning' => 'warning'][$entry['level']] ?? 'default' }}">{{ ['error' => __('Error'), 'warning' => __('Warning')][$entry['level']] ?? __('Info') }}</span>
                            {{ $entry['text'] }}
                            @if ($entry['data'])
                                <details>
                                    <summary>{{ __('Data') }}</summary>
                                    <pre>@foreach ($entry['data'] as $key => $value){{ $key }}: {{ $value }}
@endforeach</pre>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        </div>
    </div>
@endif
