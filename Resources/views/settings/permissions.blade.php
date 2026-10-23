@php $rightLabels = $page::rightLabels($calendar->isReadOnly()); @endphp
<p class="help-block">{{ __('Rights add up: a person gets every right from their own row, their teams and the mailboxes they can open. Administrators always have every right. Edit includes deleting. Dashboard shows the next events on the person\'s dashboard, also without View.') }}</p>
@if ($calendar->isReadOnly())
    <p class="help-block">
        {{ __('This calendar is read-only, so people can only view its events.') }}
        @if ($calendar->remote_read_only)
            {{ __('Create and edit rights given earlier stay stored. Once the account may change this calendar, they count again for people who can view it.') }}
        @endif
    </p>
@endif
@foreach ($errors->getMessages() as $key => $messages)
    @if (strpos($key, 'permissions.') === 0)
        <div class="alert alert-danger">{{ $messages[0] }}</div>
    @endif
@endforeach
@foreach ($subjects as $type => $group)
    @if ($group['items'])
        <table class="table table-condensed ljpc-matrix">
            <thead>
                <tr>
                    <th>{{ $group['title'] }}</th>
                    @foreach ($rightLabels as $right => $label)
                        <th>
                            {{ $label }}
                            <input type="checkbox" class="hidden" data-ljpc-select-all="{{ $type }}:{{ $right }}" aria-label="{{ __('Select all: :right', ['right' => $label]) }}">
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($group['items'] as $subject)
                    <tr>
                        <td>{{ $subject['name'] }}</td>
                        @foreach ($rightLabels as $right => $label)
                            <td><input type="checkbox" name="permissions[{{ $type }}][{{ $subject['id'] }}][{{ $right }}]" value="1" data-ljpc-right="{{ $type }}:{{ $right }}" aria-label="{{ $subject['name'] }}: {{ $label }}" @if ($page::ticked($rights, $type, $subject['id'], $right)) checked @endif></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach
