<?php

namespace Modules\LJPcCalendarModule\Services;

use Dallgoot\Yaml\Loader;
use Dallgoot\Yaml\Yaml;
use DateTimeInterface;
use Modules\LJPcCalendarModule\Entities\Calendar;

/**
 * Reads the metadata v2 wrote into the descriptions of CalDAV events: a YAML mapping with the text under "body", the
 * links and field values under "custom_fields" ("custom_field_<field id>", "conversation_id", "author_id") and the
 * field names under "custom_fields_mapping". v2 dumped it with dallgoot/yaml, which writes the body as a JSON string,
 * and turned every line break into an ICS escape, so a body with line breaks comes back spread over several lines.
 * v3 keeps v2's field ids, so "custom_field_7" is field 7.
 */
final class LegacyDescription
{
    private const FLAGS = Loader::IGNORE_COMMENTS | Loader::IGNORE_DIRECTIVES | Loader::NO_OBJECT_FOR_DATE;
    /** The most YAML that is parsed; v2's links and field values are a few hundred bytes. */
    private const MAX_YAML_BYTES = 64 * 1024;
    /**
     * An anchor (&name) where YAML reads one: at the start of a node. v2 never wrote one, and aliases of nested anchors
     * explode in memory; an alias (*name) without an anchor expands to nothing, so a value such as "Call *before* noon"
     * still reads.
     */
    private const ANCHOR = '/(?:^|[\[{,]|[:?-][ \t])[ \t]*&[^\s\[\]{},]/m';

    /**
     * @return array|null ['body' => string, 'field_values' => array, 'conversation_id' => ?int, 'author_id' => ?int],
     *                    or null when the description is not v2 metadata
     */
    public static function parse(string $description, Calendar $calendar): ?array
    {
        // v2 wrote its metadata into CalDAV events only; on any other calendar this text was typed by someone else.
        if ($calendar->provider !== Calendar::PROVIDER_CALDAV) {
            return null;
        }
        $text = str_replace("\r\n", "\n", $description);
        // v2 always wrote the body first and always wrote custom_fields; plain text doesn't start that way.
        if (strncmp($text, 'body:', 5) !== 0 || !preg_match('/^custom_fields:/m', $text, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $offset = $match[0][1];
        // Only the part from custom_fields on is YAML that dallgoot can still read; the body is rebuilt separately.
        $yaml = substr($text, $offset);
        if (strlen($yaml) > self::MAX_YAML_BYTES || preg_match(self::ANCHOR, $yaml)) {
            return null; // not what v2 wrote, so the description is ordinary text and stays as it is
        }
        try {
            $data = self::plain(Yaml::parse($yaml, self::FLAGS));
        } catch (\Exception $e) {
            return null; // not YAML after all, so the description is ordinary text and stays as it is
        }
        if (!is_array($data) || !array_key_exists('custom_fields', $data)) {
            return null;
        }
        $customFields = is_array($data['custom_fields']) ? $data['custom_fields'] : [];
        $mapping = is_array($data['custom_fields_mapping'] ?? null) ? $data['custom_fields_mapping'] : [];

        return [
            'body'            => self::body(substr($text, 5, $offset - 5)),
            'field_values'    => FieldValues::valid($calendar, self::fieldValues($calendar, $customFields, $mapping)),
            'conversation_id' => self::id($customFields['conversation_id'] ?? null),
            'author_id'       => self::id($customFields['author_id'] ?? null),
        ];
    }

    /** The body as v2's form had it: the JSON string dallgoot wrote, with its line breaks escaped again, decoded. */
    private static function body(string $raw): string
    {
        $raw = rtrim((string) preg_replace('/^ /', '', $raw), "\n");
        $json = str_replace("\n", '\n', $raw);
        $quoted = strlen($json) >= 2 && $json[0] === '"' && substr($json, -1) === '"';
        $decoded = json_decode($quoted ? $json : '"' . $json . '"');
        $body = is_string($decoded) ? $decoded : $raw;

        return $body === '-' ? '' : $body; // v2 wrote "-" for an empty description
    }

    /** Values keyed by v3 field id. A key whose id is not on this calendar is matched by the field name v2 recorded. */
    private static function fieldValues(Calendar $calendar, array $customFields, array $mapping): array
    {
        $ids = [];
        $byName = [];
        foreach ($calendar->fieldDefinitions() as $field) {
            $ids[(string) $field['id']] = true;
            $byName[(string) $field['name']] = $byName[(string) $field['name']] ?? (string) $field['id'];
        }
        $values = [];
        foreach ($customFields as $key => $value) {
            $key = (string) $key;
            if ($key === 'conversation_id' || $key === 'author_id') {
                continue;
            }
            $id = strpos($key, 'custom_field_') === 0 ? substr($key, 13) : $key;
            $name = $mapping[$key] ?? null;
            if (!isset($ids[$id]) && is_scalar($name) && isset($byName[(string) $name])) {
                $id = $byName[(string) $name];
            }
            if (isset($ids[$id])) {
                $values[$id] = $value;
            }
        }

        return $values;
    }

    private static function id($value): ?int
    {
        $id = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;

        return $id > 0 ? $id : null;
    }

    /** dallgoot returns objects and dates; the rest of the module works with arrays and strings. */
    private static function plain($value)
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }
        if (is_object($value) && method_exists($value, 'jsonSerialize')) {
            $value = $value->jsonSerialize();
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(function ($item) {
            return self::plain($item);
        }, $value) : $value;
    }
}
