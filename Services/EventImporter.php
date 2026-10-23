<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

/**
 * Stores one pulled event. The provider wins: the VCALENDAR and everything derived from it are replaced, without
 * FreeScout's readable block, v2's metadata or a private event's details. FreeScout's own fields stay: author,
 * conversation, field values, reminder and legacy id.
 */
class EventImporter
{
    private const PRIVATE_CLASSES = ['PRIVATE', 'CONFIDENTIAL'];

    private OccurrenceService $occurrences;

    public function __construct(OccurrenceService $occurrences)
    {
        $this->occurrences = $occurrences;
    }

    /** Creates or updates the stored event for one pulled event. Returns the event, or null when the pulled data could not be parsed (logged). */
    public function upsert(Calendar $calendar, RemoteEvent $remote, int $generation): ?CalendarEvent
    {
        try {
            $incoming = Reader::read($remote->ics, Reader::OPTION_FORGIVING);
        } catch (ParseException | InvalidDataException $e) {
            return $this->skip($calendar, $remote, 'The pulled data is not iCalendar: ' . $e->getMessage());
        }
        $uid = $incoming instanceof VCalendar ? self::masterUid($incoming) : null;
        $event = null;
        if ($uid === null && $remote->partial && $incoming instanceof VCalendar) {
            // Only cancelled occurrences come without any VEVENT; the stored event says which UID they belong to.
            $event = CalendarEvent::findByRemoteId($calendar->id, $remote->remoteId);
            $uid = $event ? $event->uid : null;
        }
        if ($uid === null) {
            return $this->skip($calendar, $remote, 'The pulled data holds no event with a UID');
        }
        // A local create whose remote id was never stored, or a provider that changed its ids, is found by UID.
        $event = $event ?? CalendarEvent::findByRemoteId($calendar->id, $remote->remoteId) ?? CalendarEvent::findByUid($calendar->id, $uid);
        $tz = new DateTimeZone(($event ? $event->timezone : null) ?: $calendar->timezoneName());

        $vcal = $incoming;
        if ($remote->partial && $event !== null) {
            try {
                $stored = Reader::read($event->ics);
            } catch (ParseException | InvalidDataException $e) {
                return $this->skip($calendar, $remote, 'The stored event could not be read: ' . $e->getMessage());
            }
            $vcal = self::merge($stored, $incoming, $uid, $remote->cancelledKeys, $tz);
        } elseif ($remote->partial && !self::hasMaster($incoming, $uid)) {
            Log::info('A partial calendar update for an unknown event was skipped', ['calendar_id' => $calendar->id, 'remote_id_hash' => sha1($remote->remoteId)]);

            return null;
        }

        $master = EventIcs::master($vcal, $uid);
        if (!isset($master->DTSTART)) {
            return $this->skip($calendar, $remote, 'The pulled event has no start');
        }
        $legacy = self::cleanDescriptions($vcal, $master, $uid, $calendar);
        $private = self::isPrivate($master);
        $reduced = false;
        foreach (self::components($vcal, $uid) as $vevent) {
            if ($private || self::isPrivate($vevent)) {
                $class = strtoupper((string) ($vevent->CLASS ?? $master->CLASS));
                ExportService::reduceToBusy($vevent);
                // Kept, so the event is still marked private (derive reads it) after its details are gone.
                $vevent->CLASS = in_array($class, self::PRIVATE_CLASSES, true) ? $class : 'PRIVATE';
                $reduced = true;
            }
        }
        try {
            $derived = EventIcs::derive($vcal, $uid, $calendar->timezoneName());
        } catch (InvalidDataException $e) {
            return $this->skip($calendar, $remote, 'The pulled event has an invalid date: ' . $e->getMessage());
        }
        // A rule the provider can't write locks whole-series changes only (EventService), so it is no reason here. Nor is
        // the calendar's own writability (its provider, the account's access, the connection): every request checks it
        // (CalendarPolicy), so the events need no new import once the calendar can be written again. Any component
        // reduced to busy makes the whole event read-only: a write sends the stored copy, which would put the busy stub
        // over the provider's private occurrence.
        $readOnly = $remote->readOnly || $reduced || !self::hasMaster($vcal, $uid);

        $event = $event ?? new CalendarEvent(['calendar_id' => $calendar->id, 'field_values' => []]);
        // FreeScout stores the title its template rendered; a different title was typed elsewhere, and the form shows that one.
        if ($event->exists && $event->title_input !== null && $derived['summary'] !== $event->summary) {
            $event->title_input = null;
        }
        if ($legacy !== null) {
            $event->field_values = $event->field_values ?: $legacy['field_values'];
            $event->conversation_id = $event->conversation_id ?? $legacy['conversation_id'];
            $event->author_id = $event->author_id ?? $legacy['author_id'];
        }
        $event->uid = $uid;
        $event->remote_id = $remote->remoteId;
        // A partial update may come without the series' href or etag; then the stored ones still apply.
        if (!$remote->partial || $remote->href !== null) {
            $event->remote_href = $remote->href;
        }
        if (!$remote->partial || $remote->etag !== null) {
            $event->etag = $remote->etag;
        }
        $event->sync_generation = $generation;
        $event->read_only = $readOnly;
        $event->ics = $vcal->serialize();
        $event->fill($derived);
        $event->setRelation('calendar', $calendar);

        if ($event->exists && self::onlyStampsChanged($event)) {
            // Feeds such as Google's export write a new DTSTAMP on every request; nothing else changed, so the
            // event and its occurrences stay as they are and only the listing's generation is recorded.
            DB::table('calendar_events')->where('id', $event->id)->update(['sync_generation' => $generation]);
            $event->ics = $event->getOriginal('ics');
            $event->syncOriginal();

            return $event;
        }

        DB::transaction(function () use ($event) {
            $event->save();
            $this->occurrences->refresh($event);
        });

        return $event;
    }

    /** Master and overrides replaced by RECURRENCE-ID, cancelled occurrences turned into EXDATEs. */
    private static function merge(VCalendar $stored, VCalendar $incoming, string $uid, array $cancelledKeys, DateTimeZone $tz): VCalendar
    {
        foreach (self::components($incoming, $uid) as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                EventIcs::removeOverride($stored, $uid, EventIcs::recurrenceKey($vevent->{'RECURRENCE-ID'}, $tz), $tz);
            } else {
                foreach (self::components($stored, $uid) as $old) {
                    if (!isset($old->{'RECURRENCE-ID'})) {
                        $stored->remove($old);
                    }
                }
            }
            $stored->add(clone $vevent);
        }
        $master = self::hasMaster($stored, $uid) ? EventIcs::master($stored, $uid) : null;
        foreach ($cancelledKeys as $key) {
            EventIcs::removeOverride($stored, $uid, $key, $tz);
            if ($master !== null) {
                EventIcs::addExdate($master, $key, $tz);
            }
        }
        $zones = [];
        foreach ($stored->select('VTIMEZONE') as $zone) {
            $zones[(string) $zone->TZID] = true;
        }
        foreach ($incoming->select('VTIMEZONE') as $zone) {
            if (!isset($zones[(string) $zone->TZID])) {
                $stored->add(clone $zone);
            }
        }

        return $stored;
    }

    /**
     * Strips the readable block from every description of the event, or reads v2's metadata out of it.
     * Returns that metadata, the master's before an override's.
     */
    private static function cleanDescriptions(VCalendar $vcal, VEvent $master, string $uid, Calendar $calendar): ?array
    {
        $found = null;
        $components = array_merge([$master], array_filter(self::components($vcal, $uid), function (VEvent $vevent) use ($master) {
            return $vevent !== $master;
        }));
        foreach ($components as $vevent) {
            if (!isset($vevent->DESCRIPTION)) {
                continue;
            }
            $description = (string) $vevent->DESCRIPTION;
            $legacy = LegacyDescription::parse($description, $calendar);
            $clean = $legacy !== null ? $legacy['body'] : ReadableBlock::strip($description);
            if ($clean !== $description) {
                unset($vevent->DESCRIPTION);
                if ($clean !== '') {
                    $vevent->DESCRIPTION = $clean;
                }
            }
            $found = $found ?? $legacy;
        }

        return $found;
    }

    /** The UID of the master, or of the first override when the series' master isn't there. */
    private static function masterUid(VCalendar $vcal): ?string
    {
        $first = null;
        foreach ($vcal->select('VEVENT') as $vevent) {
            $uid = (string) ($vevent->UID ?? '');
            if ($uid === '') {
                continue;
            }
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $uid;
            }
            $first = $first ?? $uid;
        }

        return $first;
    }

    private static function hasMaster(VCalendar $vcal, string $uid): bool
    {
        foreach (self::components($vcal, $uid) as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return true;
            }
        }

        return false;
    }

    /** @return VEvent[] */
    private static function components(VCalendar $vcal, string $uid): array
    {
        return array_values(array_filter($vcal->select('VEVENT'), function (VEvent $vevent) use ($uid) {
            return (string) ($vevent->UID ?? '') === $uid;
        }));
    }

    /** Whether the only difference with the stored event is the DTSTAMP of its components (and the generation). */
    private static function onlyStampsChanged(CalendarEvent $event): bool
    {
        if (array_diff(array_keys($event->getDirty()), ['ics', 'sync_generation'])) {
            return false;
        }
        $withoutStamps = function (string $ics): string {
            return (string) preg_replace('/^DTSTAMP[;:][^\r\n]*\r?\n/m', '', $ics);
        };

        return $withoutStamps((string) $event->getOriginal('ics')) === $withoutStamps((string) $event->ics);
    }

    private static function isPrivate(VEvent $vevent): bool
    {
        return in_array(strtoupper((string) ($vevent->CLASS ?? '')), self::PRIVATE_CLASSES, true);
    }

    private function skip(Calendar $calendar, RemoteEvent $remote, string $reason): ?CalendarEvent
    {
        Log::warning('A pulled calendar event was skipped', ['calendar_id' => $calendar->id, 'remote_id_hash' => sha1($remote->remoteId), 'reason' => $reason]);

        return null;
    }
}
