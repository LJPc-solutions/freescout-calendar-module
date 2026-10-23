@php $rows = $page::fieldRows($calendar); @endphp
<div class="ljpc-fields" data-ljpc-fields>
    @if ($errors->has('fields'))
        <div class="alert alert-danger">{{ $errors->first('fields') }}</div>
    @endif
    <table class="table table-condensed ljpc-fields-table">
        <thead>
            <tr>
                <th><span class="sr-only">{{ __('Order') }}</span></th>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Options, one per line') }}</th>
                <th>{{ __('Remove') }}</th>
            </tr>
        </thead>
        <tbody data-ljpc-field-rows>
            @foreach ($rows as $key => $row)
                @include('calendar::settings.field_row', ['key' => $key, 'row' => $row])
            @endforeach
        </tbody>
    </table>
    <template data-ljpc-field-template>
        @include('calendar::settings.field_row', ['key' => '__KEY__', 'row' => ['id' => '', 'name' => '', 'type' => 'text', 'required' => false, 'options' => '', 'remove' => false]])
    </template>
    <button type="button" class="btn btn-default hidden" data-ljpc-add-field>{{ __('Add field') }}</button>
    <p class="help-block">{{ __('Fill in the empty row to add a field. Drag a row by its handle to change the order. Renaming a field keeps its values.') }}</p>
</div>
