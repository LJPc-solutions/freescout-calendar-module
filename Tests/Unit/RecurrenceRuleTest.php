<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Services\RecurrenceRule;
use Modules\LJPcCalendarModule\Tests\TestCase;

class RecurrenceRuleTest extends TestCase
{
    public function test_builds_rules_for_every_form_option(): void
    {
        $tz = 'Europe/Amsterdam';
        $this->assertSame('FREQ=DAILY;INTERVAL=2', RecurrenceRule::toRrule(['freq' => 'daily', 'interval' => 2], false, $tz));
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10', RecurrenceRule::toRrule(['freq' => 'WEEKLY', 'byday' => ['mo', 'we'], 'count' => 10], false, $tz));
        $this->assertSame('FREQ=MONTHLY;BYMONTHDAY=15', RecurrenceRule::toRrule(['freq' => 'MONTHLY', 'bymonthday' => 15], false, $tz));
        $this->assertSame('FREQ=MONTHLY;BYDAY=-1FR', RecurrenceRule::toRrule(['freq' => 'MONTHLY', 'byday' => ['FR'], 'bysetpos' => -1], false, $tz));
        $this->assertSame('FREQ=YEARLY;UNTIL=20271231', RecurrenceRule::toRrule(['freq' => 'YEARLY', 'until' => '2027-12-31'], true, $tz));
        // Timed events: UNTIL is the end of that local day, in UTC (RFC 5545 requires UTC with a TZID start).
        $this->assertSame('FREQ=WEEKLY;UNTIL=20271231T225959Z', RecurrenceRule::toRrule(['freq' => 'WEEKLY', 'until' => '2027-12-31'], false, $tz));
    }

    public function test_ignores_blank_form_values(): void
    {
        $this->assertSame('FREQ=MONTHLY;BYMONTHDAY=15', RecurrenceRule::toRrule(['freq' => 'MONTHLY', 'bymonthday' => 15, 'bysetpos' => '', 'byday' => [], 'count' => '', 'until' => ''], false, 'UTC'));
        $this->assertSame('FREQ=DAILY', RecurrenceRule::toRrule(['freq' => 'DAILY', 'interval' => '', 'byday' => null, 'bysetpos' => null, 'bymonthday' => '', 'until' => null, 'count' => null], false, 'UTC'));
    }

    public function test_rejects_invalid_rules(): void
    {
        $bad = [
            ['recurrence.freq', ['freq' => 'HOURLY']],
            ['recurrence.interval', ['freq' => 'DAILY', 'interval' => 0]],
            ['recurrence.interval', ['freq' => 'DAILY', 'interval' => '2abc']],
            ['recurrence.interval', ['freq' => 'DAILY', 'interval' => 2.5]],
            ['recurrence.interval', ['freq' => 'DAILY', 'interval' => 1000]],
            ['recurrence.byday', ['freq' => 'WEEKLY', 'byday' => ['XX']]],
            ['recurrence.byday', ['freq' => 'WEEKLY', 'byday' => [5]]],
            ['recurrence.byday', ['freq' => 'WEEKLY', 'byday' => [['MO']]]],
            ['recurrence.byday', ['freq' => 'DAILY', 'byday' => ['MO']]],
            ['recurrence.byday', ['freq' => 'YEARLY', 'byday' => ['MO']]],
            ['recurrence.bysetpos', ['freq' => 'MONTHLY', 'byday' => ['MO', 'TU'], 'bysetpos' => 2]],
            ['recurrence.bysetpos', ['freq' => 'MONTHLY', 'byday' => ['MO']]],
            ['recurrence.bysetpos', ['freq' => 'WEEKLY', 'bysetpos' => 1]],
            ['recurrence.bymonthday', ['freq' => 'MONTHLY', 'bymonthday' => 32]],
            ['recurrence.bymonthday', ['freq' => 'MONTHLY', 'bymonthday' => 15, 'bysetpos' => 1, 'byday' => ['MO']]],
            ['recurrence.bymonthday', ['freq' => 'WEEKLY', 'bymonthday' => 15]],
            ['recurrence.bymonthday', ['freq' => 'DAILY', 'bymonthday' => 15]],
            ['recurrence.count', ['freq' => 'DAILY', 'count' => 0]],
            ['recurrence.count', ['freq' => 'DAILY', 'count' => '0']],
            ['recurrence.count', ['freq' => 'DAILY', 'count' => '3x']],
            ['recurrence.count', ['freq' => 'DAILY', 'count' => 1000]],
            ['recurrence.until', ['freq' => 'DAILY', 'until' => '2027-01-01', 'count' => 3]],
            ['recurrence.until', ['freq' => 'DAILY', 'until' => '31-12-2027']],
        ];
        foreach ($bad as [$key, $input]) {
            try {
                RecurrenceRule::toRrule($input, false, 'UTC');
                $this->fail('Accepted ' . json_encode($input));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors(), json_encode($input));
            }
        }
    }

    public function test_round_trips_and_reports_unrepresentable_rules(): void
    {
        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'byday' => ['MO', 'WE'], 'count' => 10], RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10'));
        $this->assertSame(['freq' => 'MONTHLY', 'interval' => 1, 'byday' => ['FR'], 'bysetpos' => -1], RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=-1FR'));
        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'until' => '2027-12-31'], RecurrenceRule::fromRrule('FREQ=WEEKLY;UNTIL=20271231T225959Z', 'Europe/Amsterdam'));
        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'until' => '2027-12-31'], RecurrenceRule::fromRrule('FREQ=WEEKLY;UNTIL=20280101T045959Z', 'America/New_York'));
        $this->assertSame(['freq' => 'YEARLY', 'interval' => 1, 'until' => '2027-12-31'], RecurrenceRule::fromRrule('FREQ=YEARLY;UNTIL=20271231', 'Europe/Amsterdam'));
        $this->assertSame(['freq' => 'DAILY', 'interval' => 1], RecurrenceRule::fromRrule('FREQ=DAILY;WKST=MO'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=DAILY;WKST=SU'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=DAILY;INTERVAL=0'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=DAILY;INTERVAL=1000'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=DAILY;COUNT=0'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=MONTHLY;BYMONTHDAY=0'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=MONTHLY;BYMONTHDAY=32'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=DAILY;COUNT=3;UNTIL=20271231'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=1MO;BYMONTHDAY=3'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=HOURLY'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=MO;BYSETPOS=1,3'));
        $this->assertNull(RecurrenceRule::fromRrule(null));
    }

    public function test_a_floating_series_ends_on_a_local_until(): void
    {
        // RFC 5545 section 3.3.10: with a floating start, UNTIL is local time too.
        $this->assertSame('FREQ=WEEKLY;UNTIL=20271231T235959', RecurrenceRule::toRrule(['freq' => 'WEEKLY', 'until' => '2027-12-31'], false, 'Europe/Amsterdam', true));
        $this->assertSame('FREQ=YEARLY;UNTIL=20271231', RecurrenceRule::toRrule(['freq' => 'YEARLY', 'until' => '2027-12-31'], true, 'Europe/Amsterdam', true));

        // A local UNTIL reads as the same day in any zone; another app's local end time does not move the day.
        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'until' => '2027-12-31'], RecurrenceRule::fromRrule('FREQ=WEEKLY;UNTIL=20271231T235959', 'Europe/Amsterdam'));
        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'until' => '2027-12-31'], RecurrenceRule::fromRrule('FREQ=WEEKLY;UNTIL=20271231T090000', 'Pacific/Auckland'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=WEEKLY;UNTIL=20271231T2359'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=WEEKLY;UNTIL=20271231T235959X'));
    }

    public function test_canonical_rules_compare_what_the_form_can_show(): void
    {
        $tz = 'Europe/Amsterdam';
        $weekly = 'FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20271231T225959Z';
        $this->assertSame($weekly, RecurrenceRule::canonical($weekly, false, $tz));
        // Another app's spelling of the same rule: INTERVAL=1, WKST=MO, a different order, weekdays out of order and an
        // UNTIL earlier on the same day.
        $this->assertSame($weekly, RecurrenceRule::canonical('WKST=MO;BYDAY=WE,MO;INTERVAL=1;UNTIL=20271231T090000Z;FREQ=WEEKLY', false, $tz));
        $this->assertSame($weekly, RecurrenceRule::canonical('FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20271231T235959', false, $tz));
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20271231', RecurrenceRule::canonical('FREQ=WEEKLY;BYDAY=WE,MO;UNTIL=20271231', true, $tz));
        // A rule the form can't show has no canonical form: only its exact text matches.
        $this->assertNull(RecurrenceRule::canonical('FREQ=WEEKLY;BYDAY=MO;BYHOUR=10', false, $tz));
        $this->assertNull(RecurrenceRule::canonical('FREQ=MONTHLY;BYDAY=MO,TU', false, $tz));
    }
}
