<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Option;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\NoInstancesException;
use Sabre\VObject\Settings;

/**
 * Stores occurrences of recurring events for a fixed window (12 months back, 24 ahead) and expands
 * on the fly outside it. Non-recurring events always have exactly one stored row.
 */
class OccurrenceService
{
    public const MAX_PER_EVENT = 5000;
    public const MAX_ITERATIONS = 100000;
    public const WINDOW_OPTION = 'ljpccalendarmodule.occurrence_window';
    public const PAST_MONTHS = 12;
    public const FUTURE_MONTHS = 24;

    public function window(): array
    {
        $stored = Option::get(self::WINDOW_OPTION, null, true, false);
        if (is_array($stored) && isset($stored['from'], $stored['to'])) {
            return [new DateTimeImmutable($stored['from'], self::utc()), new DateTimeImmutable($stored['to'], self::utc())];
        }

        return $this->moveWindow();
    }

    public function moveWindow(?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('now', self::utc()))->setTimezone(self::utc())->setTime(0, 0);
        $from = $today->modify('-' . self::PAST_MONTHS . ' months');
        $to = $today->modify('+' . self::FUTURE_MONTHS . ' months');
        Option::set(self::WINDOW_OPTION, ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]);

        return [$from, $to];
    }

    public function refresh(CalendarEvent $event): void
    {
        [$from, $to] = $this->window();
        $rows = $event->is_recurring ? $this->expand($event, $from, $to) : [$this->single($event)];
        if ($rows === null) {
            return; // expansion failed (already logged); keep the stored rows
        }

        CalendarOccurrence::where('event_id', $event->id)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            CalendarOccurrence::insert(array_map(function (array $row) use ($event) {
                return $row + ['event_id' => $event->id, 'calendar_id' => $event->calendar_id];
            }, $chunk));
        }
    }

    public function refreshRecurring(): int
    {
        $count = 0;
        CalendarEvent::where('is_recurring', true)->with('calendar')->chunk(100, function ($events) use (&$count) {
            foreach ($events as $event) {
                DB::transaction(function () use ($event) {
                    $this->refresh($event);
                });
                $count++;
            }
        });

        return $count;
    }

    /**
     * Re-derives the floating events of a calendar (timed, without a zone, so read in the calendar's zone) and refreshes
     * their occurrences, after the calendar's zone changed. Code that changes a calendar's zone calls it; a change of
     * FreeScout's own time zone, which calendars without a zone follow, does not, so such events catch up at the next
     * change. Returns how many it changed. An event that can't be read keeps its stored times (logged).
     */
    public function refreshFloating(Calendar $calendar): int
    {
        $zone = $calendar->timezoneName();
        $count = 0;
        // derive() stores the zone a floating event was read in as its timezone: one already read in the new zone is skipped.
        $calendar->events()->where('all_day', false)->where(function ($query) use ($zone) {
            $query->whereNull('timezone')->orWhere('timezone', '<>', $zone);
        })->chunkById(200, function ($events) use ($calendar, $zone, &$count) {
            foreach ($events as $event) {
                /** @var CalendarEvent $event */
                try {
                    $vcal = Reader::read($event->ics);
                    $master = EventIcs::master($vcal, $event->uid);
                } catch (ParseException | InvalidDataException | InvalidArgumentException $e) {
                    Log::error('A calendar event could not be read to follow its calendar\'s new time zone', ['event_id' => $event->id, 'error' => $e->getMessage()]);
                    continue;
                }
                if (!$master->DTSTART->hasTime() || !$master->DTSTART->isFloating()) {
                    continue;
                }
                $event->setRelation('calendar', $calendar);
                $event->fill(EventIcs::derive($vcal, $event->uid, $zone));
                $event->save();
                $this->refresh($event);
                $count++;
            }
        });

        return $count;
    }

    /**
     * @return array|null rows for the range; [] when no instances exist; null when the event could not be expanded (logged)
     */
    public function expand(CalendarEvent $event, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        $tz = new DateTimeZone($event->timezone ?: $event->calendar->timezoneName());
        // Our own cap (MAX_PER_EVENT) bounds the output; sabre's cap also counts fast-forwarded instances, so give it a generous bound.
        $previousMax = Settings::$maxRecurrences;
        Settings::$maxRecurrences = self::MAX_ITERATIONS;

        try {
            $iterator = new EventIterator(Reader::read($event->ics), $event->uid, $tz);
            $iterator->fastForward($from);
            $rows = [];
            while ($iterator->valid() && $iterator->getDtStart() < $to && count($rows) < self::MAX_PER_EVENT) {
                $instance = $iterator->getEventObject();
                $allDay = !$instance->DTSTART->hasTime();
                $summary = (string) ($instance->SUMMARY ?? '');
                $rows[] = [
                    'recurrence_id' => EventIcs::recurrenceKey($instance->{'RECURRENCE-ID'}, $tz),
                    'summary'       => $summary !== $event->summary ? mb_substr($summary, 0, 500) : null,
                    'starts_at'     => self::column($iterator->getDtStart(), $allDay),
                    'ends_at'       => self::column($iterator->getDtEnd(), $allDay),
                    'all_day'       => $allDay,
                    'status'        => strtolower((string) ($instance->STATUS ?? 'CONFIRMED')),
                ];
                $iterator->next();
            }

            return $rows;
        } catch (NoInstancesException $e) {
            return []; // every instance is excluded; nothing to show
        } catch (InvalidDataException | MaxInstancesExceededException | ParseException | InvalidArgumentException $e) {
            Log::error('Calendar event recurrence could not be expanded', ['event_id' => $event->id, 'error' => $e->getMessage()]);

            return null;
        } finally {
            Settings::$maxRecurrences = $previousMax;
        }
    }

    public function find(CalendarEvent $event, string $key): ?array
    {
        $center = DateTimeImmutable::createFromFormat('!Ymd', substr($key, 0, 8), self::utc());
        if ($center === false) {
            return null;
        }
        // An override may move an occurrence far from its original slot, so also look at the override's own start.
        foreach ($this->expand($event, $center->modify('-2 days'), $center->modify('+2 days')) ?? [] as $row) {
            if ($row['recurrence_id'] === $key) {
                return $row;
            }
        }
        foreach ($this->expand($event, $center->modify('-' . self::PAST_MONTHS . ' months'), $center->modify('+' . self::FUTURE_MONTHS . ' months')) ?? [] as $row) {
            if ($row['recurrence_id'] === $key) {
                return $row;
            }
        }

        return null;
    }

    public function hasOccurrence(CalendarEvent $event, string $key): bool
    {
        return $this->find($event, $key) !== null;
    }

    public function between(array $calendarIds, DateTimeImmutable $from, DateTimeImmutable $to, string $timezone): array
    {
        if (!$calendarIds) {
            return [];
        }
        $bounds = [
            'fromUtc'   => $from->setTimezone(self::utc())->format('Y-m-d H:i:s'),
            'toUtc'     => $to->setTimezone(self::utc())->format('Y-m-d H:i:s'),
            'fromLocal' => $from->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i:s'),
            'toLocal'   => $to->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i:s'),
        ];

        $rows = CalendarOccurrence::whereIn('calendar_id', $calendarIds)
            ->where('starts_at', '<', $to->setTimezone(self::utc())->modify('+14 hours')->format('Y-m-d H:i:s'))
            ->where('ends_at', '>=', $from->setTimezone(self::utc())->modify('-14 hours')->format('Y-m-d H:i:s'))
            ->orderBy('starts_at')
            ->get(['event_id', 'calendar_id', 'recurrence_id', 'summary', 'starts_at', 'ends_at', 'all_day', 'status'])
            ->map(function (CalendarOccurrence $occurrence) {
                return $occurrence->toArray();
            })
            ->filter(function (array $row) use ($bounds) {
                return self::overlaps($row, $bounds);
            })
            ->values()
            ->all();

        [$windowFrom, $windowTo] = $this->window();
        if ($from < $windowFrom || $to > $windowTo) {
            $rows = array_merge($rows, $this->outsideWindow($calendarIds, $from, $to, $bounds, $rows));
        }

        return $rows;
    }

    private function outsideWindow(array $calendarIds, DateTimeImmutable $from, DateTimeImmutable $to, array $bounds, array $stored): array
    {
        $seen = [];
        foreach ($stored as $row) {
            $seen[$row['event_id'] . '|' . $row['recurrence_id']] = true;
        }
        $rows = [];
        $events = CalendarEvent::whereIn('calendar_id', $calendarIds)->where('is_recurring', true)->with('calendar')->get();
        foreach ($events as $event) {
            foreach ($this->expand($event, $from, $to) ?? [] as $row) {
                $key = $event->id . '|' . $row['recurrence_id'];
                if (!isset($seen[$key]) && self::overlaps($row, $bounds)) {
                    $seen[$key] = true;
                    $rows[] = $row + ['event_id' => $event->id, 'calendar_id' => $event->calendar_id];
                }
            }
        }

        return $rows;
    }

    private static function overlaps(array $row, array $bounds): bool
    {
        if ($row['all_day']) {
            return $row['starts_at'] < $bounds['toLocal'] && $row['ends_at'] > $bounds['fromLocal'];
        }
        $zeroLength = $row['starts_at'] === $row['ends_at'];

        return $row['starts_at'] < $bounds['toUtc']
            && ($row['ends_at'] > $bounds['fromUtc'] || ($zeroLength && $row['starts_at'] >= $bounds['fromUtc']));
    }

    private function single(CalendarEvent $event): array
    {
        return [
            'recurrence_id' => null,
            'summary'       => null,
            'starts_at'     => $event->starts_at,
            'ends_at'       => $event->ends_at,
            'all_day'       => (bool) $event->all_day,
            'status'        => $event->status,
        ];
    }

    private static function column(DateTimeImmutable $dateTime, bool $allDay): string
    {
        return $allDay ? $dateTime->format('Y-m-d 00:00:00') : $dateTime->setTimezone(self::utc())->format('Y-m-d H:i:s');
    }

    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
