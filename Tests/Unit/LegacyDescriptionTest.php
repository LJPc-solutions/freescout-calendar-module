<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Dallgoot\Yaml\Yaml;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Services\LegacyDescription;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

class LegacyDescriptionTest extends TestCase
{
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = Calendar::create(['name' => 'Legacy ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'fields' => [
            ['id' => 7, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']],
            ['id' => 9, 'name' => 'Paid', 'type' => 'boolean', 'required' => false, 'options' => []],
            ['id' => 11, 'name' => 'Due', 'type' => 'date', 'required' => false, 'options' => []],
            ['id' => 12, 'name' => 'Rooms', 'type' => 'multiselect', 'required' => false, 'options' => ['A', 'B']],
        ]]);
    }

    /** What v2 wrote (dallgoot's dump, then v2's own ICS escaping) as sabre reads it back. */
    private static function v2Description(array $description): string
    {
        $text = preg_replace('/([\,;])/', '\\\$1', str_replace("\n", '\n', Yaml::dump($description)));
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//hacksw/handcal//NONSGML v1.0//EN\r\nBEGIN:VEVENT\r\nUID:v2@example.com\r\n"
            . "DTSTART;TZID=UTC:20261020T100000Z\r\nDESCRIPTION:" . $text . "\r\nEND:VEVENT\r\nEND:VCALENDAR";

        return (string) Reader::read($ics)->VEVENT->DESCRIPTION;
    }

    public function test_reads_the_body_links_and_field_values_v2_wrote(): void
    {
        $description = self::v2Description([
            'body'                  => "Caf\u{e9} \"quoted\"\nSecond line: with a colon\nThird, with a comma; and a semicolon\n/path",
            'custom_fields'         => ['conversation_id' => 12, 'author_id' => 3, 'custom_field_7' => 'Visit', 'custom_field_9' => true, 'custom_field_11' => '2026-10-20', 'custom_field_12' => ['A', 'B']],
            'custom_fields_mapping' => ['custom_field_7' => 'Kind', 'custom_field_9' => 'Paid'],
        ]);

        $this->assertSame([
            'body'            => "Caf\u{e9} \"quoted\"\nSecond line: with a colon\nThird, with a comma; and a semicolon\n/path",
            'field_values'    => ['7' => 'Visit', '9' => true, '11' => '2026-10-20', '12' => ['A', 'B']],
            'conversation_id' => 12,
            'author_id'       => 3,
        ], LegacyDescription::parse($description, $this->calendar));
    }

    public function test_an_empty_v2_description_has_no_body_and_no_values(): void
    {
        $description = self::v2Description(['body' => '-', 'custom_fields' => [], 'custom_fields_mapping' => []]);

        $this->assertSame(['body' => '', 'field_values' => [], 'conversation_id' => null, 'author_id' => null], LegacyDescription::parse($description, $this->calendar));
    }

    public function test_invalid_and_unknown_values_are_left_out_and_renamed_fields_are_found_by_name(): void
    {
        $description = self::v2Description([
            'body'                  => 'Notes',
            'custom_fields'         => ['custom_field_7' => 'Dinner', 'custom_field_99' => 'x', 'custom_field_3' => false, '12' => ['B'], 'conversation_id' => 'none'],
            'custom_fields_mapping' => ['custom_field_3' => 'Paid'],
        ]);

        $parsed = LegacyDescription::parse($description, $this->calendar);

        $this->assertSame(['9' => false, '12' => ['B']], $parsed['field_values']);
        $this->assertNull($parsed['conversation_id']);
        $this->assertSame('Notes', $parsed['body']);
    }

    public function test_plain_text_is_never_taken_for_v2_metadata(): void
    {
        foreach (['body: x', 'Just a call about the invoice', "Agenda:\n- item one\ncustom_fields: none", "body: x\ncustom_fields: [unclosed\nfoo: {bar"] as $text) {
            $this->assertNull(LegacyDescription::parse($text, $this->calendar), $text);
        }
    }

    public function test_yaml_anchors_and_aliases_are_never_parsed(): void
    {
        // Each level repeats the one before ten times; six levels need more than 256 MB once the aliases are expanded.
        $ten = function (string $item): string {
            return '[' . implode(',', array_fill(0, 10, $item)) . ']';
        };
        $nested = "body: \"Notes\"\ncustom_fields:\n  l0: &l0 " . $ten('a') . "\n  l1: &l1 " . $ten('*l0') . "\n  l2: " . $ten('*l1') . "\ncustom_fields_mapping: {}";
        $block = "body: \"Notes\"\ncustom_fields: &fields\n  custom_field_7: Visit\ncustom_fields_mapping:\n  copy: *fields";

        foreach ([$nested, $block] as $text) {
            $this->assertNull(LegacyDescription::parse($text, $this->calendar), $text);
        }
    }

    public function test_values_with_an_asterisk_or_an_ampersand_are_still_read(): void
    {
        // dallgoot writes these unquoted; they are no anchor, and an alias without an anchor expands to nothing.
        $description = self::v2Description([
            'body'                  => 'Notes',
            'custom_fields'         => ['custom_field_99' => 'Call *before* noon', 'custom_field_98' => 'Tom &Jerry', 'custom_field_97' => 'a, *b', 'conversation_id' => 12, 'custom_field_7' => 'Visit'],
            'custom_fields_mapping' => [],
        ]);

        $parsed = LegacyDescription::parse($description, $this->calendar);

        $this->assertSame([12, ['7' => 'Visit']], [$parsed['conversation_id'], $parsed['field_values']]);
    }

    public function test_metadata_longer_than_64_kb_is_never_parsed(): void
    {
        $text = "body: \"Notes\"\ncustom_fields:\n  custom_field_7: Visit\ncustom_fields_mapping:\n" . str_repeat("  custom_field_7: Kind\n", 3000);

        $this->assertGreaterThan(64 * 1024, strlen($text));
        $this->assertNull(LegacyDescription::parse($text, $this->calendar));
    }

    public function test_only_caldav_calendars_carry_v2_metadata(): void
    {
        $description = self::v2Description(['body' => 'Notes', 'custom_fields' => ['custom_field_7' => 'Visit'], 'custom_fields_mapping' => []]);

        foreach ([Calendar::PROVIDER_ICS, Calendar::PROVIDER_GOOGLE, Calendar::PROVIDER_MICROSOFT, Calendar::PROVIDER_LOCAL] as $provider) {
            $calendar = Calendar::create(['name' => 'Not CalDAV ' . uniqid(), 'provider' => $provider, 'fields' => $this->calendar->fields]);
            $this->assertNull(LegacyDescription::parse($description, $calendar), $provider);
        }
        $this->assertSame(['7' => 'Visit'], LegacyDescription::parse($description, $this->calendar)['field_values']);
    }
}
