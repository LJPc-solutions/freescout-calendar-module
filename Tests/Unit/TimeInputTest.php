<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Services\TimeInput;
use Modules\LJPcCalendarModule\Tests\TestCase;

class TimeInputTest extends TestCase
{
    public function test_naive_times_are_read_in_the_users_timezone_not_the_browsers(): void
    {
        $times = TimeInput::normalize(false, '2026-10-27T10:00', '2026-10-27T11:00', 'Europe/Amsterdam');

        $this->assertSame('2026-10-27T10:00:00+01:00', $times['start']->format(DATE_ATOM));
        $this->assertSame('Europe/Amsterdam', $times['start']->getTimezone()->getName());
    }

    public function test_times_with_an_offset_are_converted_to_the_users_timezone(): void
    {
        $times = TimeInput::normalize(false, '2026-10-27T09:00:00Z', null, 'Europe/Amsterdam');

        $this->assertSame('2026-10-27T10:00:00+01:00', $times['start']->format(DATE_ATOM));
        $this->assertSame('2026-10-27T11:00:00+01:00', $times['end']->format(DATE_ATOM)); // default one hour
    }

    public function test_all_day_dates_have_an_exclusive_end(): void
    {
        $times = TimeInput::normalize(true, '2026-10-05', null, 'Europe/Amsterdam');
        $this->assertSame(['2026-10-05', '2026-10-06'], [$times['start'], $times['end']]);

        $times = TimeInput::normalize(true, '2026-10-05T00:00:00+02:00', '2026-10-08', 'Europe/Amsterdam');
        $this->assertSame(['2026-10-05', '2026-10-08'], [$times['start'], $times['end']]);
    }

    public function test_rejects_bad_input(): void
    {
        foreach ([[false, 'tomorrow', null], [false, '2026-10-05T10:00', '2026-10-05T09:00'], [true, '2026-10-05', '2026-10-05'], [true, '2026-02-30', null], [false, '2026-02-30T10:00', null], [false, '2026-10-05T24:00', null]] as [$allDay, $start, $end]) {
            try {
                TimeInput::normalize($allDay, $start, $end, 'Europe/Amsterdam');
                $this->fail("Accepted $start / $end");
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_rejects_years_outside_1900_to_2200_with_the_usual_errors(): void
    {
        $cases = [
            [false, '0001-01-01T10:00', null, 'start', 'Invalid date and time.'],
            [false, '1899-12-31T23:59', null, 'start', 'Invalid date and time.'],
            [false, '2026-10-05T10:00', '2201-01-01T10:00', 'end', 'Invalid date and time.'],
            [true, '0999-06-01', null, 'start', 'Invalid date.'],
            [true, '2026-10-05', '2201-01-01', 'end', 'Invalid date.'],
        ];
        foreach ($cases as [$allDay, $start, $end, $key, $message]) {
            try {
                TimeInput::normalize($allDay, $start, $end, 'Europe/Amsterdam');
                $this->fail("Accepted $start / $end");
            } catch (ValidationException $e) {
                $this->assertSame([$key => [$message]], $e->errors(), "$start / $end");
            }
        }

        $this->assertSame('1900', TimeInput::normalize(false, '1900-01-01T10:00', null, 'Europe/Amsterdam')['start']->format('Y'));
        $this->assertSame(['2200-12-30', '2200-12-31'], array_values(array_slice(TimeInput::normalize(true, '2200-12-30', '2200-12-31', 'UTC'), 1, 2)));
    }

    public function test_user_timezone_falls_back_to_utc_when_invalid(): void
    {
        $this->assertSame('Europe/Amsterdam', TimeInput::userTimezone($this->makeUser(['timezone' => 'Europe/Amsterdam'])));
        $this->assertSame('UTC', TimeInput::userTimezone($this->makeUser(['timezone' => 'Mars/Olympus'])));
    }

    public function test_all_day_values_are_a_date_or_a_date_with_a_valid_time(): void
    {
        foreach (['2026-10-05xyz', '2026-10-05 10:00', '2026-10-05T', '2026-10-05T25'] as $value) {
            try {
                TimeInput::normalize(true, $value, null, 'Europe/Amsterdam');
                $this->fail('Accepted ' . $value);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('start', $e->errors());
            }
        }
        $this->assertSame('2026-10-05', TimeInput::normalize(true, '2026-10-05T00:00:00Z', null, 'UTC')['start']);
    }

    public function test_a_timed_end_before_the_start_says_so(): void
    {
        try {
            TimeInput::normalize(false, '2026-10-05T10:00', '2026-10-05T09:00', 'UTC');
            $this->fail('Accepted an end before the start');
        } catch (ValidationException $e) {
            $this->assertSame([__('The end can\'t be before the start.')], $e->errors()['end']);
        }
        $this->assertSame('2026-10-05 10:00', TimeInput::normalize(false, '2026-10-05T10:00', '2026-10-05T10:00', 'UTC')['end']->format('Y-m-d H:i'));
    }
}
