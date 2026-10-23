<?php

namespace Modules\LJPcCalendarModule\Services;

use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;

/** Renders a calendar's title template. Placeholders: {{title}} and {{field:<id>}}. */
final class TitleTemplate
{
    private const PLACEHOLDER = '/\{\{\s*(title|field:([^}\s]+))\s*\}\}/';

    public static function render(Calendar $calendar, string $title, array $values): string
    {
        $title = trim($title);
        $template = trim((string) $calendar->title_template);
        if ($template === '') {
            if ($title === '') {
                throw ValidationException::withMessages(['title' => __('The title is required.')]);
            }

            return mb_substr($title, 0, 500);
        }

        $fields = [];
        foreach ($calendar->fieldDefinitions() as $field) {
            $fields[(string) $field['id']] = $field;
        }
        $result = preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($title, $values, $fields) {
            if ($match[1] === 'title') {
                return $title;
            }
            $id = $match[2];

            return isset($values[$id], $fields[$id]) ? FieldValues::display($fields[$id], $values[$id]) : '';
        }, $template);
        $result = trim(preg_replace('/\s+/', ' ', $result));
        if ($result === '' && $title === '') {
            throw ValidationException::withMessages(['title' => __('The title is required.')]);
        }

        return mb_substr($result !== '' ? $result : $title, 0, 500);
    }

    public static function fieldIds(string $template): array
    {
        preg_match_all('/\{\{\s*field:([^}\s]+)\s*\}\}/', $template, $matches);

        return $matches[1];
    }
}
