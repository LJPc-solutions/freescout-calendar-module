<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\CalendarProviders\Microsoft\GraphRecurrence;
use Modules\LJPcCalendarModule\Tests\TestCase;

class GraphRecurrenceTest extends TestCase
{
    private const ZONE = 'Europe/Amsterdam';

    private static function start(string $local, string $zone = self::ZONE): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone($zone));
    }

    /** A patternedRecurrence as Graph returns it, with the noise its answers carry (research question 4). */
    private static function graph(array $pattern, array $range): array
    {
        return [
            'pattern' => $pattern + ['interval' => 1, 'month' => 0, 'dayOfMonth' => 0, 'daysOfWeek' => [], 'firstDayOfWeek' => 'sunday', 'index' => 'first'],
            'range'   => $range + ['startDate' => '2026-10-05', 'endDate' => '0001-01-01', 'recurrenceTimeZone' => 'W. Europe Standard Time', 'numberOfOccurrences' => 0],
        ];
    }

    /** Each pattern type: what Graph sends, the RRULE FreeScout stores, and the series start the pattern belongs to. */
    public static function patterns(): array
    {
        return [
            'daily, every other day, ten times' => [
                ['type' => 'daily', 'interval' => 2], ['type' => 'numbered', 'numberOfOccurrences' => 10],
                'FREQ=DAILY;INTERVAL=2;COUNT=10', '2026-10-05 10:00',
            ],
            'weekly on three days until a date' => [
                ['type' => 'weekly', 'daysOfWeek' => ['monday', 'wednesday', 'friday']], ['type' => 'endDate', 'endDate' => '2026-12-31'],
                'FREQ=WEEKLY;BYDAY=MO,WE,FR;UNTIL=20261231T225959Z', '2026-10-05 10:00',
            ],
            'every other week on two days, weeks from Sunday' => [
                ['type' => 'weekly', 'interval' => 2, 'daysOfWeek' => ['tuesday', 'thursday']], ['type' => 'noEnd', 'startDate' => '2026-10-06'],
                'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH;WKST=SU', '2026-10-06 10:00',
            ],
            'monthly on the 15th' => [
                ['type' => 'absoluteMonthly', 'dayOfMonth' => 15], ['type' => 'noEnd', 'startDate' => '2026-10-15'],
                'FREQ=MONTHLY;BYMONTHDAY=15', '2026-10-15 10:00',
            ],
            'monthly on the 31st, the last day in shorter months' => [
                ['type' => 'absoluteMonthly', 'dayOfMonth' => 31], ['type' => 'numbered', 'startDate' => '2026-10-31', 'numberOfOccurrences' => 4],
                'FREQ=MONTHLY;BYMONTHDAY=28,29,30,31;BYSETPOS=-1;COUNT=4', '2026-10-31 10:00',
            ],
            'every three months on the second Tuesday' => [
                ['type' => 'relativeMonthly', 'interval' => 3, 'daysOfWeek' => ['tuesday'], 'index' => 'second'], ['type' => 'noEnd', 'startDate' => '2026-10-13'],
                'FREQ=MONTHLY;INTERVAL=3;BYDAY=2TU', '2026-10-13 10:00',
            ],
            'monthly on the last Friday' => [
                ['type' => 'relativeMonthly', 'daysOfWeek' => ['friday'], 'index' => 'last'], ['type' => 'noEnd', 'startDate' => '2026-10-30'],
                'FREQ=MONTHLY;BYDAY=-1FR', '2026-10-30 10:00',
            ],
            'monthly on the first weekend day' => [
                ['type' => 'relativeMonthly', 'daysOfWeek' => ['saturday', 'sunday'], 'index' => 'first'], ['type' => 'noEnd', 'startDate' => '2026-10-03'],
                'FREQ=MONTHLY;BYDAY=SA,SU;BYSETPOS=1', '2026-10-03 10:00',
            ],
            'yearly on the series start' => [
                ['type' => 'absoluteYearly', 'month' => 10, 'dayOfMonth' => 5], ['type' => 'noEnd'],
                'FREQ=YEARLY', '2026-10-05 10:00',
            ],
            'every two years on 15 March' => [
                ['type' => 'absoluteYearly', 'interval' => 2, 'month' => 3, 'dayOfMonth' => 15], ['type' => 'noEnd', 'startDate' => '2027-03-15'],
                'FREQ=YEARLY;INTERVAL=2', '2027-03-15 10:00',
            ],
            'yearly on 29 February, the 28th in other years' => [
                ['type' => 'absoluteYearly', 'month' => 2, 'dayOfMonth' => 29], ['type' => 'noEnd', 'startDate' => '2028-02-29'],
                'FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=28,29;BYSETPOS=-1', '2028-02-29 10:00',
            ],
            'yearly on the fourth Thursday of November' => [
                ['type' => 'relativeYearly', 'month' => 11, 'daysOfWeek' => ['thursday'], 'index' => 'fourth'], ['type' => 'numbered', 'startDate' => '2026-11-26', 'numberOfOccurrences' => 3],
                'FREQ=YEARLY;BYMONTH=11;BYDAY=4TH;COUNT=3', '2026-11-26 10:00',
            ],
        ];
    }

    /** @dataProvider patterns */
    public function test_every_pattern_becomes_an_rrule_and_back(array $pattern, array $range, string $rrule, string $start): void
    {
        $graph = self::graph($pattern, $range);

        $this->assertSame($rrule, GraphRecurrence::toRrule($graph, self::ZONE));

        $back = GraphRecurrence::fromRrule($rrule, self::start($start), false);
        $this->assertNotNull($back);
        $this->assertSame($rrule, GraphRecurrence::toRrule($back, self::ZONE));
        // Graph gets only the fields the pattern type needs.
        $needs = [
            'daily' => ['type', 'interval'], 'weekly' => ['type', 'interval', 'daysOfWeek', 'firstDayOfWeek'],
            'absoluteMonthly' => ['type', 'interval', 'dayOfMonth'], 'relativeMonthly' => ['type', 'interval', 'daysOfWeek', 'index'],
            'absoluteYearly' => ['type', 'interval', 'dayOfMonth', 'month'], 'relativeYearly' => ['type', 'interval', 'daysOfWeek', 'index', 'month'],
        ];
        $this->assertSame($needs[$pattern['type']], array_keys($back['pattern']));
        $this->assertSame(substr($start, 0, 10), $back['range']['startDate']);
        $this->assertArrayNotHasKey('recurrenceTimeZone', $back['range']);
    }

    public function test_the_end_date_is_the_end_of_that_day_where_the_series_lives(): void
    {
        $until = self::graph(['type' => 'daily'], ['type' => 'endDate', 'endDate' => '2027-03-09', 'recurrenceTimeZone' => 'Eastern Standard Time']);

        // 9 March 2027 is still winter time in New York (UTC-5); without a recurrenceTimeZone the series' zone counts.
        $this->assertSame('FREQ=DAILY;UNTIL=20270310T045959Z', GraphRecurrence::toRrule($until, self::ZONE));
        unset($until['range']['recurrenceTimeZone']);
        $this->assertSame('FREQ=DAILY;UNTIL=20270309T225959Z', GraphRecurrence::toRrule($until, self::ZONE));
        $this->assertSame('FREQ=DAILY;UNTIL=20270309', GraphRecurrence::toRrule($until, self::ZONE, true));

        // Back: the day of UNTIL in the series' zone, or the day before when that day's occurrence starts after it.
        $start = self::start('2026-10-05 10:00');
        $this->assertSame(['type' => 'endDate', 'startDate' => '2026-10-05', 'endDate' => '2026-12-31'], GraphRecurrence::fromRrule('FREQ=DAILY;UNTIL=20261231T225959Z', $start, false)['range']);
        $this->assertSame('2026-12-30', GraphRecurrence::fromRrule('FREQ=DAILY;UNTIL=20261231T080000Z', $start, false)['range']['endDate']);
        $this->assertSame('2026-12-31', GraphRecurrence::fromRrule('FREQ=DAILY;UNTIL=20261231T090000Z', $start, false)['range']['endDate']);
        $this->assertSame('2026-12-31', GraphRecurrence::fromRrule('FREQ=DAILY;UNTIL=20261231', self::start('2026-10-05'), true)['range']['endDate']);
        // A floating series' UNTIL is local time.
        $this->assertSame('2026-12-31', GraphRecurrence::fromRrule('FREQ=DAILY;UNTIL=20261231T235959', $start, false)['range']['endDate']);
    }

    public function test_rules_the_form_writes_map_onto_graph(): void
    {
        $monday = self::start('2026-10-05 10:00');

        $this->assertSame([
            'pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['monday'], 'firstDayOfWeek' => 'monday'],
            'range'   => ['type' => 'endDate', 'startDate' => '2026-10-05', 'endDate' => '2026-12-31'],
        ], GraphRecurrence::fromRrule('FREQ=WEEKLY;BYDAY=MO;UNTIL=20261231T225959Z', $monday, false));
        $this->assertSame(['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['monday'], 'firstDayOfWeek' => 'monday'], GraphRecurrence::fromRrule('FREQ=WEEKLY', $monday, false)['pattern']);
        $this->assertSame(['type' => 'numbered', 'startDate' => '2026-10-05', 'numberOfOccurrences' => 8], GraphRecurrence::fromRrule('FREQ=WEEKLY;BYDAY=MO;COUNT=8', $monday, false)['range']);
        $this->assertSame(['type' => 'noEnd', 'startDate' => '2026-10-05'], GraphRecurrence::fromRrule('FREQ=DAILY', $monday, false)['range']);
        // "Every weekday" is a weekly pattern in Graph.
        $this->assertSame(['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'firstDayOfWeek' => 'monday'], GraphRecurrence::fromRrule('FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR', $monday, false)['pattern']);
        $this->assertSame(['type' => 'weekly', 'interval' => 2, 'daysOfWeek' => ['monday', 'thursday'], 'firstDayOfWeek' => 'sunday'], GraphRecurrence::fromRrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;WKST=SU', $monday, false)['pattern']);
        // A month or a year without more parts takes its day from the start.
        $this->assertSame(['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => 5], GraphRecurrence::fromRrule('FREQ=MONTHLY', $monday, false)['pattern']);
        $this->assertSame(['type' => 'absoluteYearly', 'interval' => 1, 'dayOfMonth' => 5, 'month' => 10], GraphRecurrence::fromRrule('FREQ=YEARLY', $monday, false)['pattern']);
        // The last day of the month is Outlook's day 31.
        $this->assertSame(['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => 31], GraphRecurrence::fromRrule('FREQ=MONTHLY;BYMONTHDAY=-1', self::start('2026-10-31 10:00'), false)['pattern']);
        $this->assertSame(['type' => 'relativeMonthly', 'interval' => 1, 'daysOfWeek' => ['tuesday'], 'index' => 'second'], GraphRecurrence::fromRrule('FREQ=MONTHLY;BYDAY=TU;BYSETPOS=2', self::start('2026-10-13 10:00'), false)['pattern']);
        // All-day series work alike, with a date as UNTIL.
        $this->assertSame([
            'pattern' => ['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => 15],
            'range'   => ['type' => 'endDate', 'startDate' => '2026-10-15', 'endDate' => '2027-01-15'],
        ], GraphRecurrence::fromRrule('FREQ=MONTHLY;BYMONTHDAY=15;UNTIL=20270115', self::start('2026-10-15'), true));
    }

    public function test_rules_graph_cant_express_have_no_pattern(): void
    {
        $monday = self::start('2026-10-05 10:00');
        $rules = [
            'FREQ=HOURLY',
            'FREQ=MINUTELY;INTERVAL=30',
            'FREQ=MONTHLY;BYMONTHDAY=1,15',
            'FREQ=MONTHLY;BYDAY=MO,TU;BYSETPOS=1,2',
            'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=5',
            'FREQ=MONTHLY;BYDAY=-2FR',
            'FREQ=MONTHLY;BYDAY=1MO,3MO',
            'FREQ=MONTHLY;BYDAY=MO',
            'FREQ=WEEKLY;BYDAY=1MO',
            'FREQ=WEEKLY;BYMONTHDAY=5',
            'FREQ=YEARLY;BYWEEKNO=20',
            'FREQ=YEARLY;BYYEARDAY=100',
            'FREQ=YEARLY;BYMONTH=1,7',
            'FREQ=YEARLY;BYDAY=20MO',
            'FREQ=DAILY;BYHOUR=9,17',
            'FREQ=DAILY;INTERVAL=2;BYDAY=MO,TU',
            'FREQ=DAILY;COUNT=3;UNTIL=20261231T225959Z',
            'FREQ=DAILY;FREQ=WEEKLY',
            'FREQ=DAILY;RSCALE=GREGORIAN',
            'FREQ=DAILY;INTERVAL=0',
            'FREQ=DAILY;COUNT=0',
            'FREQ=DAILY;UNTIL=20261001T000000Z',
            'NONSENSE',
            '',
        ];
        foreach ($rules as $rule) {
            $this->assertNull(GraphRecurrence::fromRrule($rule, $monday, false), $rule);
        }
        // RFC 5545 skips months without the day; Outlook moves those occurrences to the last day instead.
        $this->assertNull(GraphRecurrence::fromRrule('FREQ=MONTHLY;BYMONTHDAY=31', self::start('2026-10-31 10:00'), false));
        $this->assertNull(GraphRecurrence::fromRrule('FREQ=MONTHLY', self::start('2026-10-30 10:00'), false));
        $this->assertNull(GraphRecurrence::fromRrule('FREQ=YEARLY', self::start('2028-02-29 10:00'), false));
        // Graph starts a series at its first occurrence, so a start that is no occurrence of the rule can't be kept.
        $this->assertNull(GraphRecurrence::fromRrule('FREQ=WEEKLY;BYDAY=TU,WE', $monday, false));
        $this->assertNull(GraphRecurrence::fromRrule('FREQ=MONTHLY;BYMONTHDAY=6', $monday, false));
        $this->assertNull(GraphRecurrence::fromRrule('FREQ=MONTHLY;BYDAY=2MO', $monday, false));
        $this->assertNull(GraphRecurrence::fromRrule('FREQ=YEARLY;BYMONTH=11;BYDAY=1MO', $monday, false));
    }

    public function test_a_yearly_day_other_than_the_start_keeps_its_month_and_day(): void
    {
        $other = self::graph(['type' => 'absoluteYearly', 'month' => 3, 'dayOfMonth' => 15], ['type' => 'noEnd']);

        $this->assertSame('FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=15', GraphRecurrence::toRrule($other, self::ZONE));
        $this->assertSame(
            ['type' => 'absoluteYearly', 'interval' => 1, 'dayOfMonth' => 15, 'month' => 3],
            GraphRecurrence::fromRrule('FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=15', self::start('2027-03-15 10:00'), false)['pattern']
        );
        $relative = self::graph(['type' => 'relativeYearly', 'month' => 5, 'daysOfWeek' => ['saturday', 'sunday'], 'index' => 'last'], ['type' => 'noEnd']);
        $this->assertSame('FREQ=YEARLY;BYMONTH=5;BYDAY=SA,SU;BYSETPOS=-1', GraphRecurrence::toRrule($relative, self::ZONE));
        $this->assertSame(
            ['type' => 'relativeYearly', 'interval' => 1, 'daysOfWeek' => ['saturday', 'sunday'], 'index' => 'last', 'month' => 5],
            GraphRecurrence::fromRrule('FREQ=YEARLY;BYMONTH=5;BYDAY=SA,SU;BYSETPOS=-1', self::start('2027-05-30 10:00'), false)['pattern']
        );
    }

    public function test_patterns_freescout_cant_read_have_no_rule(): void
    {
        $this->assertNull(GraphRecurrence::toRrule([], self::ZONE));
        $this->assertNull(GraphRecurrence::toRrule(self::graph(['type' => 'hourly'], ['type' => 'noEnd']), self::ZONE));
        $this->assertNull(GraphRecurrence::toRrule(self::graph(['type' => 'weekly', 'daysOfWeek' => []], ['type' => 'noEnd']), self::ZONE));
        $this->assertNull(GraphRecurrence::toRrule(self::graph(['type' => 'weekly', 'daysOfWeek' => ['someday']], ['type' => 'noEnd']), self::ZONE));
        $this->assertNull(GraphRecurrence::toRrule(self::graph(['type' => 'absoluteMonthly', 'dayOfMonth' => 32], ['type' => 'noEnd']), self::ZONE));
        $this->assertNull(GraphRecurrence::toRrule(self::graph(['type' => 'relativeMonthly', 'daysOfWeek' => ['monday'], 'index' => 'fifth'], ['type' => 'noEnd']), self::ZONE));
        $this->assertNull(GraphRecurrence::toRrule(self::graph(['type' => 'daily', 'interval' => 0], ['type' => 'noEnd']), self::ZONE));
        $this->assertNull(GraphRecurrence::toRrule(self::graph(['type' => 'daily'], ['type' => 'numbered', 'numberOfOccurrences' => 0]), self::ZONE));
        $this->assertNull(GraphRecurrence::toRrule(self::graph(['type' => 'daily'], ['type' => 'endDate', 'endDate' => 'soon']), self::ZONE));
        $this->assertNull(GraphRecurrence::toRrule(self::graph(['type' => 'daily'], ['type' => 'forever']), self::ZONE));
    }
}
