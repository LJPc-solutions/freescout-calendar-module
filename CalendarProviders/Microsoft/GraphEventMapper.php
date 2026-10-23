<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Microsoft;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\LJPcCalendarModule\CalendarProviders\HtmlText;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\VTimezone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\NoInstancesException;
use Sabre\VObject\Settings;

/**
 * Microsoft Graph event resources (v1.0 event reference) to and from VEVENTs. Graph answers in UTC (the provider sends
 * Prefer: outlook.timezone="UTC"); the zone the event was made in (originalStartTimeZone, a Windows name) becomes its
 * TZID. A series is its master plus its instances in a window (events/{id}/instances, research question 2): an
 * exception becomes an override with the RECURRENCE-ID of its originalStart (always UTC), a cancelled instance an
 * EXDATE, and an occurrence of the rule that Graph doesn't list in the window was deleted in Outlook and becomes an
 * EXDATE too, unless Graph lists occurrences the rule doesn't give (then the rule and Graph disagree, and nothing is
 * taken as deleted). Pure code: the provider does the requests.
 */
final class GraphEventMapper
{
    /** Graph's dateTime: a local time without an offset, with up to seven decimals ("2026-10-14T08:00:00.0000000"). */
    private const LOCAL_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?$/';
    /** Graph's DateTimeOffset, always in UTC ("2026-10-26T09:00:00Z"). */
    private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/i';
    private const CLASSES = ['private' => 'PRIVATE', 'confidential' => 'CONFIDENTIAL'];
    private const SENSITIVITY = ['PRIVATE' => 'private', 'CONFIDENTIAL' => 'confidential', 'PUBLIC' => 'normal'];
    private const PARTSTAT = ['organizer' => 'ACCEPTED', 'accepted' => 'ACCEPTED', 'tentativelyAccepted' => 'TENTATIVE', 'declined' => 'DECLINED'];
    private const UTC_NAMES = ['UTC', 'Z', '+00:00', 'Etc/UTC', 'GMT', 'Etc/GMT'];

    /**
     * One VCALENDAR for a single event, or a series with its instances. $window is [from, to], the time range the
     * instances were read for: an occurrence of the rule starting in it without an instance becomes an EXDATE. Without
     * a window (the instances were not read) only exceptions and cancellations count. Throws InvalidArgumentException for
     * an event without a usable id, start or (for a series) recurrence. $logContext (calendar_id) goes with what is logged.
     */
    public static function toVCalendar(array $master, array $instances, ?array $window, string $calendarTimezone, array $logContext = []): string
    {
        $vcal = new VCalendar(['PRODID' => EventIcs::PRODID]);
        $zone = new DateTimeZone(self::zoneOf($master, $calendarTimezone));
        $allDay = !empty($master['isAllDay']);
        $vevent = self::vevent($vcal, $master, self::uid($master), $zone, $calendarTimezone);
        if (($master['type'] ?? null) === 'seriesMaster') {
            $rrule = is_array($master['recurrence'] ?? null) ? GraphRecurrence::toRrule($master['recurrence'], $zone->getName(), $allDay) : null;
            if ($rrule === null) {
                throw new InvalidArgumentException('The series has no recurrence FreeScout can read');
            }
            $vevent->RRULE = $rrule;
            $present = [];
            $listed = [];
            foreach ($instances as $instance) {
                if (!is_array($instance)) {
                    continue;
                }
                $key = self::occurrenceKey($instance, $allDay, $zone->getName());
                $present[] = $key;
                if (empty($instance['isCancelled']) && $window !== null && self::inWindow((string) $instance['originalStart'], $window)) {
                    $listed[] = $key;
                }
                if (!empty($instance['isCancelled'])) {
                    EventIcs::addExdate($vevent, $key, $zone);
                } elseif (($instance['type'] ?? null) === 'exception') {
                    $override = self::vevent($vcal, $instance, (string) $vevent->UID, $zone, $calendarTimezone);
                    self::addRecurrenceId($override, $key, $allDay, $zone);
                }
            }
            if ($window !== null) {
                self::addMissing($vevent, $present, $listed, $window, $zone, $logContext);
            }
        }
        VTimezone::addForEvents($vcal);

        return $vcal->serialize();
    }

    /**
     * The event resource for a create or an update: subject, body (text), location, start and end, isAllDay, sensitivity
     * (only when the VEVENT has a CLASS; without one Graph's own value stays) and, for a master, recurrence (null for a
     * single event). Times are sent in the zone the event lives in, under its Windows name (WindowsZones::fromIana()); a
     * floating time is read in $timezone, an all-day event is set in $timezone, and a zone without a name (an offset) is
     * sent as UTC. Attendees and the organizer are never written. Throws InvalidArgumentException for a rule Graph can't
     * hold (MicrosoftProvider::writableRecurrence() keeps those from coming here).
     */
    public static function fromVEvent(VEvent $vevent, bool $isMaster, string $timezone): array
    {
        $json = [
            'subject'  => (string) ($vevent->SUMMARY ?? ''),
            'body'     => ['contentType' => 'text', 'content' => (string) ($vevent->DESCRIPTION ?? '')],
            'location' => ['displayName' => (string) ($vevent->LOCATION ?? '')],
        ];
        $floating = new DateTimeZone($timezone);
        $start = self::sentStart($vevent, $floating);
        if ($vevent->DTSTART->hasTime()) {
            $startZone = $start->getTimezone()->getName();
            $end = EventIcs::endOf($vevent, $floating);
            $endZone = isset($vevent->DTEND) && !$vevent->DTEND->isFloating() ? self::sendZone($end->getTimezone()) : $startZone;
            $json['start'] = ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => WindowsZones::fromIana($startZone)];
            $json['end'] = ['dateTime' => $end->setTimezone(new DateTimeZone($endZone))->format('Y-m-d\TH:i:s'), 'timeZone' => WindowsZones::fromIana($endZone)];
            $json['isAllDay'] = false;
        } else {
            $windows = WindowsZones::fromIana(self::sendZone($floating));
            $end = max(EventIcs::endOf($vevent, $floating), $start->modify('+1 day'));
            $json['start'] = ['dateTime' => $start->format('Y-m-d\T00:00:00'), 'timeZone' => $windows];
            $json['end'] = ['dateTime' => $end->format('Y-m-d\T00:00:00'), 'timeZone' => $windows];
            $json['isAllDay'] = true;
        }
        $class = strtoupper((string) ($vevent->CLASS ?? ''));
        if (isset(self::SENSITIVITY[$class])) {
            $json['sensitivity'] = self::SENSITIVITY[$class];
        }
        if ($isMaster) {
            $json['recurrence'] = self::recurrence($vevent, $start);
        }

        return $json;
    }

    /**
     * Whether Graph can hold the master's rule as fromVEvent() would send it: no rule, or one GraphRecurrence maps from
     * the start as it is sent; never with an RDATE or EXRULE. (An EXDATE is fine: its occurrence is deleted on its own.)
     */
    public static function canWriteRule(VEvent $master): bool
    {
        if (isset($master->RDATE) || isset($master->EXRULE) || count($master->select('RRULE')) > 1) {
            return false;
        }
        if (!isset($master->RRULE)) {
            return true;
        }

        // Any zone does for a floating start: its weekday and day of the month are its wall clock's.
        return GraphRecurrence::fromRrule((string) $master->RRULE, self::sentStart($master, new DateTimeZone('UTC')), !$master->DTSTART->hasTime()) !== null;
    }

    /**
     * The fields of an update ($changes, what fromVEvent() made) that differ from the event as Graph has it ($current,
     * read with the same fields and originalStartTimeZone), in the order of $changes. An update sends only these: Graph
     * mails a meeting's attendees on every update (event-update reference), and Outlook may reset a series' exceptions
     * when its times or rule are set. A changed all-day flag sends start, end and the flag together; the rule goes along
     * when it changes or when the series starts on another day (its range starts on the start's date).
     *
     * @return string[]
     */
    public static function changedFields(array $current, array $changes, string $calendarTimezone): array
    {
        $zone = new DateTimeZone(self::zoneOf($current, $calendarTimezone));
        $allDay = !empty($current['isAllDay']);
        $changed = [];
        if (array_key_exists('subject', $changes) && (string) $changes['subject'] !== (string) ($current['subject'] ?? '')) {
            $changed[] = 'subject';
        }
        if (array_key_exists('body', $changes) && self::comparable((string) ($changes['body']['content'] ?? '')) !== self::comparable(self::text($current['body'] ?? null))) {
            $changed[] = 'body';
        }
        if (array_key_exists('location', $changes) && trim((string) ($changes['location']['displayName'] ?? '')) !== trim((string) ($current['location']['displayName'] ?? ''))) {
            $changed[] = 'location';
        }
        if (array_key_exists('sensitivity', $changes) && $changes['sensitivity'] !== ($current['sensitivity'] ?? 'normal')) {
            $changed[] = 'sensitivity';
        }
        if (array_key_exists('isAllDay', $changes) && $changes['isAllDay'] !== $allDay) {
            $changed = array_merge($changed, ['start', 'end', 'isAllDay', 'recurrence']);
        } else {
            foreach (['start', 'end'] as $field) {
                if (array_key_exists($field, $changes) && !self::sameTime($current[$field] ?? null, $changes[$field], $allDay, $zone)) {
                    $changed[] = $field;
                }
            }
            if (array_key_exists('recurrence', $changes) && self::ruleOf($current['recurrence'] ?? null, $zone, $allDay) !== self::ruleOf($changes['recurrence'], $zone, $allDay)) {
                $changed[] = 'recurrence';
            }
        }

        return array_values(array_intersect(array_keys($changes), $changed));
    }

    /**
     * The occurrence key (EventIcs::recurrenceKey format) of an instance: its originalStart in UTC, or for an all-day
     * series the date it starts on in $zone (the series' zone). UNVERIFIED whether Graph gives an all-day occurrence's
     * originalStart as the UTC midnight of its date or as the midnight of the series' zone; both give that date.
     */
    public static function occurrenceKey(array $instance, bool $allDay, string $zone): string
    {
        $original = $instance['originalStart'] ?? null;
        if (!is_string($original) || !preg_match(self::INSTANT, $original)) {
            throw new InvalidArgumentException('The instance has no originalStart');
        }
        $moment = (new DateTimeImmutable($original))->setTimezone(new DateTimeZone('UTC'));

        return $allDay ? self::dateOf($moment, new DateTimeZone($zone))->format('Ymd') : $moment->format('Ymd\THis\Z');
    }

    /**
     * The occurrences of the master's rule that start in [$from, $to) (or started before and end after $from, as Graph
     * lists them) without a key in $presentKeys: the ones Graph no longer has. [] for a master without a rule.
     *
     * @return string[] occurrence keys
     */
    public static function missingKeys(VEvent $master, array $presentKeys, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $tz): array
    {
        return array_values(array_diff(self::expectedKeys($master, $from, $to, $tz), $presentKeys));
    }

    /**
     * The IANA zone of an event: the zone it was made in (originalStartTimeZone), else its rule's zone
     * (recurrenceTimeZone; Outlook's legacy custom zones have no name), else the calendar's. A Windows name that stands
     * for the calendar's own zone reads as that zone, so FreeScout's own events keep their zone name.
     */
    public static function zoneOf(array $event, string $calendarTimezone): string
    {
        return self::zoneFor($event['originalStartTimeZone'] ?? null, $calendarTimezone)
            ?? self::zoneFor($event['recurrence']['range']['recurrenceTimeZone'] ?? null, $calendarTimezone)
            ?? $calendarTimezone;
    }

    /**
     * EXDATEs for the occurrences of the rule in the window that Graph doesn't list. When Graph lists an occurrence (its
     * original start in the window) that the rule doesn't give, the series is read in another zone or by other rules
     * than Graph's: then nothing is taken as deleted, and that is logged.
     */
    private static function addMissing(VEvent $master, array $present, array $listed, array $window, DateTimeZone $zone, array $logContext): void
    {
        $expected = self::expectedKeys($master, $window[0], $window[1], $zone);
        $unexpected = array_values(array_diff($listed, $expected));
        if ($unexpected !== []) {
            Log::warning('The occurrences Microsoft lists for a series are not the ones its rule gives; none of them is taken as deleted', $logContext + ['uid_hash' => sha1((string) $master->UID), 'keys' => array_slice($unexpected, 0, 5)]);

            return;
        }
        foreach (array_diff($expected, $present) as $key) {
            EventIcs::addExdate($master, $key, $zone);
        }
    }

    /** The keys of the master's occurrences that start in [$from, $to), or started before and end after $from. */
    private static function expectedKeys(VEvent $master, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $tz): array
    {
        if (!isset($master->RRULE)) {
            return [];
        }
        $vcal = new VCalendar();
        $copy = $vcal->add(clone $master);
        $keys = [];
        $previous = Settings::$maxRecurrences;
        Settings::$maxRecurrences = OccurrenceService::MAX_ITERATIONS;
        try {
            $iterator = new EventIterator($vcal, (string) $copy->UID, $tz);
            $iterator->fastForward($from);
            while ($iterator->valid() && $iterator->getDtStart() < $to && count($keys) < OccurrenceService::MAX_PER_EVENT) {
                $keys[] = EventIcs::recurrenceKey($iterator->getEventObject()->{'RECURRENCE-ID'}, $tz);
                $iterator->next();
            }
        } catch (NoInstancesException $e) {
            return []; // every occurrence is an EXDATE already
        } finally {
            Settings::$maxRecurrences = $previous;
        }

        return $keys;
    }

    /** Whether an instance's original start lies in the window its instances were read for. */
    private static function inWindow(string $originalStart, array $window): bool
    {
        $moment = new DateTimeImmutable($originalStart);

        return $moment >= $window[0] && $moment < $window[1];
    }

    private static function vevent(VCalendar $vcal, array $item, string $uid, DateTimeZone $zone, string $calendarTimezone): VEvent
    {
        $vevent = $vcal->add('VEVENT', ['UID' => $uid]);
        $modified = is_string($item['lastModifiedDateTime'] ?? null) && preg_match(self::INSTANT, $item['lastModifiedDateTime'])
            ? (new DateTimeImmutable($item['lastModifiedDateTime']))->setTimezone(new DateTimeZone('UTC'))
            : null;
        $vevent->DTSTAMP = $modified ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($modified !== null) {
            $vevent->{'LAST-MODIFIED'} = $modified;
        }
        $start = self::moment($item['start'] ?? null);
        $end = is_array($item['end'] ?? null) ? self::moment($item['end']) : $start;
        if (!empty($item['isAllDay'])) {
            $from = self::dateOf($start, $zone);
            $until = self::dateOf($end, new DateTimeZone(self::zoneFor($item['originalEndTimeZone'] ?? null, $calendarTimezone) ?? $zone->getName()));
            // Graph's end is the midnight after the last day, as DTEND's date is.
            $vevent->add('DTSTART', $from->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', max($until, $from->modify('+1 day'))->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $endZone = self::zoneFor($item['originalEndTimeZone'] ?? null, $calendarTimezone) ?? $zone->getName();
            $vevent->add('DTSTART', $start->setTimezone($zone));
            $vevent->add('DTEND', max($end, $start)->setTimezone(new DateTimeZone($endZone)));
        }
        if (is_string($item['subject'] ?? null) && $item['subject'] !== '') {
            $vevent->SUMMARY = $item['subject'];
        }
        $description = self::text($item['body'] ?? null);
        if ($description !== '') {
            $vevent->DESCRIPTION = $description;
        }
        $location = $item['location']['displayName'] ?? null;
        if (is_string($location) && trim($location) !== '') {
            $vevent->LOCATION = $location;
        }
        if (!empty($item['isCancelled'])) {
            $vevent->STATUS = 'CANCELLED';
        }
        if (isset(self::CLASSES[$item['sensitivity'] ?? ''])) {
            $vevent->CLASS = self::CLASSES[$item['sensitivity']];
        }
        if (($item['showAs'] ?? null) === 'free') {
            $vevent->TRANSP = 'TRANSPARENT';
        }
        $organizer = $item['organizer']['emailAddress'] ?? null;
        if (is_array($organizer) && self::isAddress($organizer['address'] ?? null)) {
            $vevent->add('ORGANIZER', 'mailto:' . $organizer['address'], array_filter(['CN' => $organizer['name'] ?? null], 'is_string'));
        }
        foreach ((array) ($item['attendees'] ?? []) as $attendee) {
            $address = is_array($attendee) ? ($attendee['emailAddress'] ?? null) : null;
            if (!is_array($address) || !self::isAddress($address['address'] ?? null)) {
                continue;
            }
            $vevent->add('ATTENDEE', 'mailto:' . $address['address'], array_filter([
                'CN'       => $address['name'] ?? null,
                'CUTYPE'   => ($attendee['type'] ?? null) === 'resource' ? 'RESOURCE' : null,
                'ROLE'     => ($attendee['type'] ?? null) === 'optional' ? 'OPT-PARTICIPANT' : 'REQ-PARTICIPANT',
                'PARTSTAT' => self::PARTSTAT[$attendee['status']['response'] ?? ''] ?? 'NEEDS-ACTION',
            ], 'is_string'));
        }

        return $vevent;
    }

    /** A RECURRENCE-ID in the series' own form: a date for an all-day series, else the original start in $zone. */
    private static function addRecurrenceId(VEvent $override, string $key, bool $allDay, DateTimeZone $zone): void
    {
        if ($allDay) {
            $override->add('RECURRENCE-ID', $key, ['VALUE' => 'DATE']);

            return;
        }
        $override->add('RECURRENCE-ID', DateTimeImmutable::createFromFormat('Ymd\THis\Z', $key, new DateTimeZone('UTC'))->setTimezone($zone));
    }

    /** Graph's patternedRecurrence for a master, null without a rule; $start is the start as it is sent. */
    private static function recurrence(VEvent $vevent, DateTimeImmutable $start): ?array
    {
        if (!self::canWriteRule($vevent)) {
            throw new InvalidArgumentException('Graph has no pattern for the rule of ' . $vevent->UID);
        }

        return isset($vevent->RRULE) ? GraphRecurrence::fromRrule((string) $vevent->RRULE, $start, !$vevent->DTSTART->hasTime()) : null;
    }

    /**
     * The start as fromVEvent() sends it: a date at midnight in $floating for an all-day event, else the moment in the
     * zone it is sent in (its own named zone, $floating for a floating time, UTC for a zone that is only an offset).
     */
    private static function sentStart(VEvent $vevent, DateTimeZone $floating): DateTimeImmutable
    {
        $start = $vevent->DTSTART->getDateTime($floating);
        if (!$vevent->DTSTART->hasTime()) {
            return $start;
        }

        return $start->setTimezone(new DateTimeZone(self::sendZone($vevent->DTSTART->isFloating() ? $floating : $start->getTimezone())));
    }

    /** The plain text of a Graph itemBody: HTML (Outlook's full documents) becomes the text it shows; text stays. */
    private static function text($body): string
    {
        $content = is_array($body) && is_string($body['content'] ?? null) ? $body['content'] : '';
        if (strtolower((string) ($body['contentType'] ?? 'text')) !== 'html') {
            return rtrim(str_replace("\r\n", "\n", $content));
        }
        // Outlook sends a whole document: its head (with a style sheet) shows nothing, and source line breaks and
        // indentation are no text. An empty paragraph holds a <br> that is its only line.
        $html = self::cleaned(preg_replace(['~<(head|style|script)\b[^>]*>.*?</\1>~is', '~<!--.*?-->~s'], '', $content));
        $html = self::cleaned(preg_replace(['~>\s+<~', '~\s+~', '~<br\s*/?>\s*(</(?:div|p)>)~i'], ['><', ' ', '$1'], $html));

        return HtmlText::toPlain($html);
    }

    /** A body PCRE gave up on (null) is refused, never emptied: the event is skipped and its stored copy kept. */
    private static function cleaned(?string $html): string
    {
        if ($html === null) {
            throw new InvalidArgumentException('The HTML body could not be read: ' . preg_last_error_msg());
        }

        return $html;
    }

    /** Whether a start or end Graph has is the one an update sends: the same moment, or the same date for an all-day event. */
    private static function sameTime($current, array $new, bool $allDay, DateTimeZone $zone): bool
    {
        try {
            $now = self::moment($current);
            $then = self::moment($new);
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return $allDay ? self::dateOf($now, $zone)->format('Y-m-d') === $then->format('Y-m-d') : $now->getTimestamp() === $then->getTimestamp();
    }

    /** A patternedRecurrence as comparable text: its rule and its start date; false for one FreeScout can't read. */
    private static function ruleOf($recurrence, DateTimeZone $zone, bool $allDay): string|false|null
    {
        if (!is_array($recurrence)) {
            return null;
        }
        $rule = GraphRecurrence::toRrule($recurrence, $zone->getName(), $allDay);

        return $rule === null ? false : $rule . '@' . ($recurrence['range']['startDate'] ?? '');
    }

    /** Text as it compares: Graph may give back what FreeScout wrote with other line ends or spacing. */
    private static function comparable(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** An event's start or end (dateTimeTimeZone) as a moment: its dateTime read in its timeZone, UTC as the provider asks. */
    private static function moment($time): DateTimeImmutable
    {
        $value = is_array($time) ? ($time['dateTime'] ?? null) : null;
        if (!is_string($value) || !preg_match(self::LOCAL_TIME, $value)) {
            throw new InvalidArgumentException('The event has no usable start or end');
        }
        $zone = WindowsZones::toIana((string) ($time['timeZone'] ?? 'UTC')) ?? 'UTC';

        return new DateTimeImmutable(substr($value, 0, 19), new DateTimeZone($zone));
    }

    /**
     * The date an all-day moment stands for. Graph sets all-day events at midnight in their zone; rendered in UTC that
     * may be another hour (UNVERIFIED, research question 3). A UTC midnight is its date, a midnight in $zone is that
     * date, and anything else is rounded to the nearest midnight in $zone.
     */
    private static function dateOf(DateTimeImmutable $moment, DateTimeZone $zone): DateTimeImmutable
    {
        $utc = $moment->setTimezone(new DateTimeZone('UTC'));
        if ($utc->format('H:i:s') === '00:00:00') {
            return DateTimeImmutable::createFromFormat('!Y-m-d', $utc->format('Y-m-d'), new DateTimeZone('UTC'));
        }

        $local = $moment->setTimezone($zone)->modify('+12 hours');

        return DateTimeImmutable::createFromFormat('!Y-m-d', $local->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /** The IANA zone a Graph time zone name stands for: the calendar's own zone when it is that one; null when unknown. */
    private static function zoneFor($name, string $calendarTimezone): ?string
    {
        if (!is_string($name) || trim($name) === '') {
            return null;
        }
        if ($name === $calendarTimezone || WindowsZones::fromIana($calendarTimezone) === $name) {
            return $calendarTimezone;
        }

        return WindowsZones::toIana($name);
    }

    /** The IANA zone a time is sent in: a zone with a name, else UTC (an offset has no Windows name). */
    private static function sendZone(DateTimeZone $zone): string
    {
        $name = $zone->getName();
        if (in_array($name, self::UTC_NAMES, true)) {
            return 'UTC';
        }

        return VTimezone::isNamedZone($name) ? $name : 'UTC';
    }

    private static function uid(array $item): string
    {
        foreach (['iCalUId', 'id'] as $field) {
            if (is_string($item[$field] ?? null) && trim($item[$field]) !== '' && !preg_match('/[\r\n]/', $item[$field])) {
                return $item[$field];
            }
        }

        throw new InvalidArgumentException('The event has no iCalUId');
    }

    private static function isAddress($email): bool
    {
        return is_string($email) && $email !== '' && !preg_match('/[\s"]/', $email);
    }
}
