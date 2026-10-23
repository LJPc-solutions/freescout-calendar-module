<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Services\FieldValues;
use Modules\LJPcCalendarModule\Services\TitleTemplate;
use Modules\LJPcCalendarModule\Tests\TestCase;

class FieldValuesTest extends TestCase
{
    private function calendar(array $fields, ?string $template = null): Calendar
    {
        return new Calendar(['name' => 'x', 'fields' => $fields, 'title_template' => $template]);
    }

    public function test_casts_and_validates_each_type(): void
    {
        $calendar = $this->calendar([
            ['id' => 1, 'name' => 'Count', 'type' => 'number', 'required' => false, 'options' => []],
            ['id' => 2, 'name' => 'Paid', 'type' => 'boolean', 'required' => false, 'options' => []],
            ['id' => 3, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']],
            ['id' => 4, 'name' => 'Tags', 'type' => 'multiselect', 'required' => false, 'options' => ['A', 'B']],
            ['id' => 5, 'name' => 'Due', 'type' => 'date', 'required' => false, 'options' => []],
            ['id' => 6, 'name' => 'Mail', 'type' => 'email', 'required' => false, 'options' => []],
            ['id' => 7, 'name' => 'Note', 'type' => 'text', 'required' => false, 'options' => []],
        ]);

        $values = FieldValues::validate($calendar, ['1' => '3', '2' => '0', '3' => 'Visit', '4' => ['B'], '5' => '2026-10-05', '6' => 'a@b.nl', '7' => '<b>x</b>']);

        $this->assertSame(['1' => 3, '2' => false, '3' => 'Visit', '4' => ['B'], '5' => '2026-10-05', '6' => 'a@b.nl', '7' => '<b>x</b>'], $values);
    }

    public function test_reports_missing_and_invalid_values_per_field(): void
    {
        $calendar = $this->calendar([
            ['id' => 3, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call']],
            ['id' => 6, 'name' => 'Mail', 'type' => 'email', 'required' => false, 'options' => []],
        ]);
        try {
            FieldValues::validate($calendar, ['6' => 'not-an-email']);
            $this->fail('Accepted invalid values');
        } catch (ValidationException $e) {
            $this->assertSame(['field_values.3', 'field_values.6'], array_keys($e->errors()));
        }
    }

    public function test_title_template_uses_title_and_field_values(): void
    {
        $fields = [['id' => 3, 'name' => 'Kind', 'type' => 'dropdown', 'required' => false, 'options' => ['Call']], ['id' => 4, 'name' => 'Tags', 'type' => 'multiselect', 'required' => false, 'options' => ['A', 'B']]];

        $this->assertSame('Acme - Call (A, B)', TitleTemplate::render($this->calendar($fields, '{{title}} - {{field:3}} ({{field:4}})'), 'Acme', ['3' => 'Call', '4' => ['A', 'B']]));
        $this->assertSame('Acme -', TitleTemplate::render($this->calendar($fields, '{{title}} - {{field:3}}'), 'Acme', []));
        $this->assertSame(['3', '4'], TitleTemplate::fieldIds('{{field:3}} {{ field:4 }}'));

        $this->expectException(ValidationException::class);
        TitleTemplate::render($this->calendar($fields), '  ', []);
    }

    private function one(string $type, array $options = [], bool $required = false): Calendar
    {
        return $this->calendar([['id' => 1, 'name' => 'F', 'type' => $type, 'required' => $required, 'options' => $options]]);
    }

    private function rejects(Calendar $calendar, $value): void
    {
        try {
            FieldValues::validate($calendar, ['1' => $value]);
            $this->fail('Accepted ' . json_encode($value));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('field_values.1', $e->errors());
        }
    }

    public function test_rejects_malformed_values_without_exceptions(): void
    {
        $this->rejects($this->one('multiselect', ['A']), [['A']]);
        $this->rejects($this->one('date'), '2026-02-31');
        $this->rejects($this->one('number'), '1e999');
        $this->rejects($this->one('text'), str_repeat('a', 2001));
        $this->rejects($this->one('source'), ['x']);
    }

    public function test_dropdown_matches_scalar_numbers_as_strings(): void
    {
        $this->assertSame(['1' => '1'], FieldValues::validate($this->one('dropdown', ['1', '2']), ['1' => 1]));
        $this->assertSame(['1' => str_repeat('a', 2000)], FieldValues::validate($this->one('text'), ['1' => str_repeat('a', 2000)]));
    }

    public function test_required_boolean_needs_a_value_but_false_passes(): void
    {
        $calendar = $this->one('boolean', [], true);
        $this->rejects($calendar, null);
        $this->assertSame(['1' => false], FieldValues::validate($calendar, ['1' => false]));
    }

    public function test_text_rejects_booleans_and_multiselect_values_are_deduplicated(): void
    {
        $this->rejects($this->one('text'), true);
        $this->rejects($this->one('source'), false);
        $this->assertSame(['1' => ['A', 'B']], FieldValues::validate($this->one('multiselect', ['A', 'B']), ['1' => ['A', 'B', 'A']]));
    }
}
