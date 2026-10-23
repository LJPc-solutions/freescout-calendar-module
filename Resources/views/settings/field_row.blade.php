<tr data-ljpc-field-row>
    <td><span class="ljpc-drag-handle glyphicon glyphicon-menu-hamburger" aria-hidden="true"></span></td>
    <td class="{{ $errors->has("fields.$key.name") ? 'has-error' : '' }}">
        <input type="hidden" name="fields[{{ $key }}][id]" value="{{ $row['id'] }}">
        <input type="text" class="form-control" name="fields[{{ $key }}][name]" value="{{ $row['name'] }}" maxlength="100" aria-label="{{ __('Name') }}">
        @include('partials/field_error', ['field' => "fields.$key.name"])
    </td>
    <td class="{{ $errors->has("fields.$key.type") ? 'has-error' : '' }}">
        <select class="form-control" name="fields[{{ $key }}][type]" data-ljpc-field-type aria-label="{{ __('Type') }}">
            @foreach ($page::fieldTypes() as $value => $label)
                <option value="{{ $value }}" @if ($row['type'] === $value) selected @endif>{{ $label }}</option>
            @endforeach
        </select>
        @include('partials/field_error', ['field' => "fields.$key.type"])
    </td>
    <td><input type="checkbox" name="fields[{{ $key }}][required]" value="1" aria-label="{{ __('Required') }}" @if ($row['required']) checked @endif></td>
    <td class="{{ $errors->has("fields.$key.options") ? 'has-error' : '' }}" data-ljpc-field-options>
        <textarea class="form-control" name="fields[{{ $key }}][options]" rows="2" aria-label="{{ __('Options, one per line') }}">{{ $row['options'] }}</textarea>
        @include('partials/field_error', ['field' => "fields.$key.options"])
    </td>
    <td><input type="checkbox" name="fields[{{ $key }}][remove]" value="1" data-ljpc-field-remove aria-label="{{ __('Remove') }}" @if ($row['remove']) checked @endif></td>
</tr>
