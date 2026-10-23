<?php

namespace Modules\LJPcCalendarModule\Services;

use Illuminate\Support\Facades\Log;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/** Renders a calendar as ICS from the database, never by proxying an upstream feed. */
class ExportService
{
    private const BUSY_KEEP = ['UID', 'DTSTAMP', 'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID', 'STATUS', 'SEQUENCE', 'TRANSP'];
    private const TZID_PROPERTIES = ['DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE'];

    public function render(Calendar $calendar, bool $busyOnly): string
    {
        $copies = [];
        $firstYears = [];
        $zones = [];
        $calendar->events()->with('conversation')->orderBy('id')->chunk(200, function ($events) use ($calendar, $busyOnly, &$copies, &$firstYears, &$zones) {
            foreach ($events as $event) {
                /** @var CalendarEvent $event */
                $event->setRelation('calendar', $calendar);
                try {
                    $source = Reader::read($event->ics);
                    foreach ($source->select('VEVENT') as $vevent) {
                        if ((string) $vevent->UID !== $event->uid) {
                            continue;
                        }
                        $copy = clone $vevent;
                        $busyOnly ? self::reduceToBusy($copy) : ReadableBlock::addTo($copy, $event);
                        $copies[] = $copy;
                        self::collectTimezones($copy, $source, $firstYears, $zones);
                    }
                } catch (\Throwable $e) {
                    Log::error('Calendar event skipped in export because its ICS could not be read', [
                        'calendar_id' => $calendar->id, 'event_id' => $event->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $vcal = new VCalendar(['PRODID' => EventIcs::PRODID]);
        $vcal->add('X-WR-CALNAME', $calendar->name);
        $lastYear = (int) gmdate('Y') + 2;
        foreach ($firstYears as $tzid => $year) {
            $from = $year - 1;
            if ($zones[$tzid] === null) {
                // The feed keeps the TZID; an app that knows the name still reads it right.
                Log::warning('Calendar export has no VTIMEZONE for a TZID it could not resolve', ['calendar_id' => $calendar->id, 'tzid' => $tzid]);
                continue;
            }
            // Built from the zone the TZID stands for, under its own name: also for a Windows name, or a custom name
            // that only the event's own VTIMEZONE explains.
            VTimezone::addTo($vcal, $tzid, $from, max($lastYear, $from), $zones[$tzid]);
        }
        foreach ($copies as $copy) {
            $vcal->add($copy);
        }

        return $vcal->serialize();
    }

    /** Records every TZID used, the earliest local year of DTSTART per TZID, and the zone it stands for in its event's VCALENDAR. */
    private static function collectTimezones(VEvent $vevent, VCalendar $source, array &$firstYears, array &$zones): void
    {
        foreach (self::TZID_PROPERTIES as $name) {
            foreach ($vevent->select($name) as $property) {
                $tzid = (string) ($property['TZID'] ?? '');
                if ($tzid === '' || $tzid === 'UTC') {
                    continue;
                }
                $zones[$tzid] = $zones[$tzid] ?? VTimezone::resolve($tzid, $source);
                $firstYears[$tzid] = $firstYears[$tzid] ?? PHP_INT_MAX;
                if ($name === 'DTSTART') {
                    $firstYears[$tzid] = min($firstYears[$tzid], (int) substr((string) $property, 0, 4));
                }
            }
        }
        foreach ($firstYears as $tzid => $year) {
            if ($year === PHP_INT_MAX) {
                $firstYears[$tzid] = (int) gmdate('Y');
            }
        }
    }

    /** Keeps only the times, the rule and the status, with "Busy" as the title: free/busy exports and private events. */
    public static function reduceToBusy(VEvent $vevent): void
    {
        foreach ($vevent->children() as $child) {
            if (!in_array($child->name, self::BUSY_KEEP, true)) {
                $vevent->remove($child);
            }
        }
        $vevent->SUMMARY = __('Busy');
        if (!isset($vevent->TRANSP)) {
            $vevent->TRANSP = 'OPAQUE';
        }
    }
}
