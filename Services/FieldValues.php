<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;

/** Validates custom field values against a calendar's field definitions. Values are keyed by field id. */
final class FieldValues
{
    public const TYPES = ['text', 'number', 'dropdown', 'boolean', 'multiselect', 'date', 'email', 'source'];

    public static function validate(Calendar $calendar, array $input): array
    {
        $values = [];
        $errors = [];
        foreach ($calendar->fieldDefinitions() as $field) {
            $id = (string) $field['id'];
            $key = 'field_values.' . $id;
            $value = $input[$id] ?? null;

            if ($value === null || $value === '' || $value === []) {
                if (!empty($field['required'])) {
                    $errors[$key] = __(':field is required.', ['field' => $field['name']]);
                }
                continue;
            }
            $cast = self::cast($field, $value);
            if ($cast === null) {
                $errors[$key] = __(':field has an invalid value.', ['field' => $field['name']]);
                continue;
            }
            $values[$id] = $cast;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * The values that pass validation, for data FreeScout did not write itself (a pulled event): invalid values are
     * left out instead of failing, and required fields are not enforced.
     */
    public static function valid(Calendar $calendar, array $input): array
    {
        $values = [];
        foreach ($calendar->fieldDefinitions() as $field) {
            $id = (string) $field['id'];
            $value = $input[$id] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $cast = self::cast($field, $value);
            if ($cast !== null) {
                $values[$id] = $cast;
            }
        }

        return $values;
    }

    public static function display(array $field, $value): string
    {
        if (is_array($value)) {
            return implode(', ', $value);
        }
        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        return (string) $value;
    }

    private static function cast(array $field, $value)
    {
        $options = array_map('strval', (array) ($field['options'] ?? []));
        switch ($field['type']) {
            case 'number':
                return is_numeric($value) && is_finite((float) $value) ? $value + 0 : null;
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            case 'dropdown':
                return self::isOption($value) && in_array((string) $value, $options, true) ? (string) $value : null;
            case 'multiselect':
                if (!is_array($value)) {
                    $value = [$value];
                }
                foreach ($value as $item) {
                    if (!self::isOption($item)) {
                        return null;
                    }
                }
                $list = array_map('strval', $value);

                return array_diff($list, $options) ? null : array_values(array_unique($list));
            case 'date':
                $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

                return $date && $date->format('Y-m-d') === $value ? $value : null;
            case 'email':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
            default: // text, source
                return is_scalar($value) && !is_bool($value) && mb_strlen((string) $value) <= 2000 ? (string) $value : null;
        }
    }

    private static function isOption($value): bool
    {
        return is_string($value) || is_int($value) || is_float($value);
    }
}
