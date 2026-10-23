<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Conversation;
use App\Thread;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\ExplainsRecurrenceRefusal;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * Creates, changes and deletes events. On a calendar of another provider the provider writes first, with the readable
 * block in the description, and FreeScout saves only after it confirmed; a ProviderException leaves everything as it
 * was, and after a conflict the calendar is synced before the agent is answered. A move to a calendar of another
 * provider or account creates the event there before it is deleted from the source. When that delete fails, the move
 * still counts, the source is listed in full at its next sync (so its copy shows up again) and lastWarning() says so;
 * the API passes it to the agent. A meeting with attendees at its provider never leaves that calendar: deleting it
 * there would cancel it for every attendee (decision 2026-10-15).
 */
class EventService
{
    public const SCOPE_SERIES = 'series';
    public const SCOPE_OCCURRENCE = 'occurrence';
    /** How long the sync after a conflict may take before the agent gets the 409. */
    public const CONFLICT_SYNC_SECONDS = 10;

    private OccurrenceService $occurrences;

    private ProviderRegistry $providers;

    private SyncService $sync;

    private ?string $warning = null;

    public function __construct(OccurrenceService $occurrences, ProviderRegistry $providers, SyncService $sync)
    {
        $this->occurrences = $occurrences;
        $this->providers = $providers;
        $this->sync = $sync;
    }

    /** What the agent should know about the last create, update or delete although it succeeded; null when nothing. */
    public function lastWarning(): ?string
    {
        return $this->warning;
    }

    /**
     * $input['timezone'] is the zone of the series a timed series is copied from: the agent types the times in their own
     * zone, and they are written, with the end date of the rule, in that zone, so the series' weekdays still match its
     * start. It is ignored for a single or all-day event.
     */
    public function create(Calendar $calendar, User $user, array $input, ?Conversation $conversation = null): CalendarEvent
    {
        $this->warning = null;
        $times = TimeInput::normalize((bool) ($input['all_day'] ?? false), (string) ($input['start'] ?? ''), $input['end'] ?? null, TimeInput::userTimezone($user));
        if (!$times['all_day'] && !empty($input['recurrence']) && !empty($input['timezone'])) {
            $zone = new DateTimeZone((string) $input['timezone']);
            $times = ['start' => $times['start']->setTimezone($zone), 'end' => $times['end']->setTimezone($zone), 'timezone' => $zone->getName()] + $times;
        }
        $values = FieldValues::validate($calendar, (array) ($input['field_values'] ?? []));
        $titleInput = (string) ($input['title'] ?? '');

        $event = new CalendarEvent([
            'calendar_id'      => $calendar->id,
            'author_id'        => $user->id,
            'conversation_id'  => $conversation ? $conversation->id : null,
            'field_values'     => $values,
            'title_input'      => $titleInput,
            'reminder_minutes' => $input['reminder_minutes'] ?? null,
            'attendees'        => !empty($input['attendees']) ? $input['attendees'] : null,
        ]);
        $event->uid = self::newUid();
        $event->setRelation('calendar', $calendar);

        $vcal = EventIcs::build([
            'uid'         => $event->uid,
            'title'       => TitleTemplate::render($calendar, $titleInput, $values),
            'all_day'     => $times['all_day'],
            'start'       => $times['start'],
            'end'         => $times['end'],
            'location'    => $input['location'] ?? null,
            'description' => $input['description'] ?? null,
            'rrule'       => !empty($input['recurrence']) ? RecurrenceRule::toRrule($input['recurrence'], $times['all_day'], $times['timezone']) : null,
            'attendees'   => $input['attendees'] ?? [],
            'organizer'   => $input['organizer'] ?? null,
        ]);
        $master = EventIcs::master($vcal, $event->uid);
        if (!$calendar->canWriteSeries($master)) {
            throw ValidationException::withMessages(['recurrence' => $this->refusal($calendar, $master) ?? __('This calendar can\'t store this repeat rule.')]);
        }
        if (!$calendar->isLocal()) {
            $ref = $this->write($calendar, function () use ($calendar, $vcal, $event) {
                return $this->providers->for($calendar)->create($calendar, $this->remoteCopy($vcal, $event));
            });
            self::keepRemote($event, $vcal, $ref);
        }

        try {
            DB::transaction(function () use ($event, $vcal, $conversation, $user) {
                $this->store($event, $vcal);
                if ($conversation) {
                    $this->addLineItem($event, $conversation, $user);
                }
            });
        } catch (QueryException $e) {
            // A sync may have listed the calendar between the provider's create and this save, and stored the event
            // already (its UID and remote id are unique per calendar): that row becomes the agent's event.
            $imported = $calendar->isLocal() ? null
                : (CalendarEvent::findByRemoteId($calendar->id, (string) $event->remote_id) ?? CalendarEvent::findByUid($calendar->id, $event->uid));
            if ($imported === null) {
                throw $e;
            }
            $event = $this->completeImported($imported, $event, $conversation, $user);
        }

        return $event->fresh();
    }

    /**
     * An invite added to a calendar (InviteActions): the organizer's VCALENDAR as it is, under the organizer's UID, so
     * later updates, cancellations and answers find it. Google and Microsoft write it without attendees (their mappers
     * never send any), and the readable block names the organizer; CalDAV keeps them with SCHEDULE-AGENT=CLIENT.
     */
    public function createFromIcs(Calendar $calendar, User $user, VCalendar $vcal, string $uid, ?Conversation $conversation): CalendarEvent
    {
        $this->warning = null;
        if (CalendarEvent::findByUid($calendar->id, $uid) !== null) {
            throw ValidationException::withMessages(['calendar_id' => __('That calendar already has this event.')]);
        }
        unset($vcal->METHOD);
        $master = EventIcs::master($vcal, $uid);
        if (!$calendar->canWriteSeries($master)) {
            throw ValidationException::withMessages(['calendar_id' => $this->refusal($calendar, $master) ?? __('That calendar can\'t store the repeat rule of this series.')]);
        }
        $event = new CalendarEvent([
            'calendar_id'     => $calendar->id,
            'author_id'       => $user->id,
            'conversation_id' => $conversation ? $conversation->id : null,
            'field_values'    => [],
        ]);
        $event->uid = $uid;
        $event->setRelation('calendar', $calendar);
        if (!$calendar->isLocal()) {
            $ref = $this->write($calendar, function () use ($calendar, $vcal, $event) {
                return $this->providers->for($calendar)->create($calendar, $this->remoteCopy($vcal, $event));
            });
            self::keepRemote($event, $vcal, $ref);
        }
        try {
            DB::transaction(function () use ($event, $vcal) {
                $this->store($event, $vcal);
            });
        } catch (QueryException $e) {
            $imported = $calendar->isLocal() ? null
                : (CalendarEvent::findByRemoteId($calendar->id, (string) $event->remote_id) ?? CalendarEvent::findByUid($calendar->id, $event->uid));
            if ($imported === null) {
                throw $e;
            }
            $event = $this->completeImported($imported, $event, $conversation, $user);
        }

        return $event->fresh();
    }

    /**
     * Writes a changed VCALENDAR of the event (an invite's update or cancellation), for the whole event or for one
     * occurrence when $occurrenceKey is given. What only FreeScout knows (fields, ticket, reminder) stays.
     */
    public function replaceIcs(CalendarEvent $event, VCalendar $vcal, ?string $occurrenceKey, array $columns = []): CalendarEvent
    {
        $this->warning = null;
        $calendar = $event->calendar;
        if ($occurrenceKey === null && !$calendar->canWriteSeries(EventIcs::master($vcal, $event->uid))) {
            throw new SeriesNotWritable();
        }
        if (!$calendar->isLocal()) {
            $ref = $this->write($calendar, function () use ($calendar, $event, $vcal, $occurrenceKey) {
                return $this->providers->for($calendar)->update($calendar, $event, $this->remoteCopy($vcal, $event), $occurrenceKey);
            });
            self::keepRemote($event, $vcal, $ref);
        }
        foreach ($columns as $column => $value) {
            $event->{$column} = $value;
        }
        DB::transaction(function () use ($event, $vcal) {
            $this->store($event, $vcal);
        });

        return $event->fresh();
    }

    /** The pulled copy of an event FreeScout just created, with what only FreeScout knows: author, ticket, fields, reminder. */
    private function completeImported(CalendarEvent $imported, CalendarEvent $created, ?Conversation $conversation, User $user): CalendarEvent
    {
        foreach (['author_id', 'conversation_id', 'field_values', 'title_input', 'reminder_minutes', 'attendees'] as $field) {
            $imported->{$field} = $created->{$field};
        }
        DB::transaction(function () use ($imported, $conversation, $user) {
            $imported->save();
            if ($conversation) {
                $this->addLineItem($imported, $conversation, $user);
            }
        });

        return $imported;
    }

    public function update(CalendarEvent $event, User $user, array $input): CalendarEvent
    {
        $this->warning = null;
        if (array_key_exists('all_day', $input) && !array_key_exists('start', $input)) {
            throw ValidationException::withMessages(['start' => __('Send the start and the end when switching between all-day and timed.')]);
        }
        $occurrenceScope = ($input['scope'] ?? self::SCOPE_SERIES) === self::SCOPE_OCCURRENCE;
        $key = null;
        if ($occurrenceScope) {
            $this->assertOccurrenceInput($event, $input);
            $key = $this->occurrenceKey($event, $input['recurrence_id'] ?? null);
        }

        // Everything below validates into locals, and the provider writes before the model changes: the model is only
        // changed once nothing can fail any more.
        $source = $event->calendar;
        $calendar = $source;
        $moved = isset($input['calendar_id']) && (int) $input['calendar_id'] !== $event->calendar_id;
        if ($moved) {
            $calendar = Calendar::findOrFail((int) $input['calendar_id']);
        }
        $fieldsGiven = $moved || array_key_exists('field_values', $input);
        $fieldValues = (array) $event->field_values;
        if ($fieldsGiven) {
            $fieldValues = FieldValues::validate($calendar, (array) ($input['field_values'] ?? $event->field_values));
        }

        $vcal = Reader::read($event->ics);
        $master = EventIcs::master($vcal, $event->uid);
        if (!$occurrenceScope && !$source->canWriteSeries($master)) {
            throw new SeriesNotWritable();
        }
        // Every move away from a provider deletes the event there, also within one account (create, then delete).
        if ($moved && !$source->isLocal() && self::hasAttendees($vcal, $event->uid, $source)) {
            throw ValidationException::withMessages(['calendar_id' => __('This is a meeting with attendees. Move it in the calendar app it comes from, so the attendees are not sent a cancellation.')]);
        }
        // A UID is unique per calendar: a copy that stayed after an earlier move (its delete failed) would only fail the
        // save, after the source had deleted the event.
        if ($moved && CalendarEvent::findByUid($calendar->id, $event->uid) !== null) {
            throw ValidationException::withMessages(['calendar_id' => __('That calendar already has this event.')]);
        }
        $tz = new DateTimeZone($event->timezone ?: $calendar->timezoneName());

        if ($occurrenceScope) {
            $override = EventIcs::findOverride($vcal, $event->uid, $key, $tz) ?? EventIcs::addOverride($vcal, $master, $key, $tz);
            $this->applyInput($override, $master, $event, $input, $user, $tz, $calendar, $fieldValues, false);
        } else {
            $previousTitle = (string) ($master->SUMMARY ?? '');
            $scheduleChanged = $this->applyInput($master, $master, $event, $input, $user, $tz, $calendar, $fieldValues, $fieldsGiven);
            // A rule the agent just chose, or the series' rule in the calendar it moves to, must be one the provider can
            // write; that is settled before the agent is asked to confirm a reset.
            if (!$calendar->canWriteSeries($master)) {
                $reason = $this->refusal($calendar, $master);
                throw ValidationException::withMessages($moved
                    ? ['calendar_id' => $reason ?? __('That calendar can\'t store the repeat rule of this series.')]
                    : ['recurrence' => $reason ?? __('This calendar can\'t store this repeat rule.')]);
            }
            if ($scheduleChanged) {
                // A new start or rule resets edited and deleted occurrences, but only once the agent has confirmed it.
                // Only the parsed copy has changed so far, so throwing here saves nothing.
                if (EventIcs::hasExceptions($vcal, $master) && !($input['drop_exceptions'] ?? false)) {
                    throw new DropsExceptions('Saving would drop the exceptions of event ' . $event->id);
                }
                EventIcs::dropExceptions($vcal, $master);
            } elseif ((string) ($master->SUMMARY ?? '') !== $previousTitle) {
                $this->propagateTitle($vcal, $master, $previousTitle);
            }
            // The people we invite (InviteSender), on every component of the event.
            if (array_key_exists('attendees', $input)) {
                foreach ($vcal->select('VEVENT') as $vevent) {
                    if ((string) $vevent->UID === $event->uid) {
                        EventIcs::apply($vevent, ['attendees' => $input['attendees'], 'organizer' => $input['organizer'] ?? null]);
                    }
                }
            }
        }

        // The event as it will be saved, for the readable block the provider gets.
        $saved = clone $event;
        $saved->field_values = $fieldValues;
        if (array_key_exists('attendees', $input)) {
            $saved->attendees = $input['attendees'] ?: null;
        }
        $saved->setRelation('calendar', $calendar);
        $ref = null;
        if ($moved && !($source->isLocal() && $calendar->isLocal())) {
            [$ref, $this->warning] = $this->moveBetweenProviders($event, $source, $calendar, $this->remoteCopy($vcal, $saved));
        } elseif (!$calendar->isLocal()) {
            $ref = $this->write($calendar, function () use ($calendar, $event, $vcal, $saved, $occurrenceScope, $key) {
                return $this->providers->for($calendar)->update($calendar, $event, $this->remoteCopy($vcal, $saved), $occurrenceScope ? $key : null);
            });
        }

        if ($moved) {
            $event->calendar_id = $calendar->id;
            $event->setRelation('calendar', $calendar);
            // Generations count per calendar: one from the source could outrank every listing of the target, and the
            // event would never be swept there. Saved now, it is safe from a listing that is running (updated_at).
            $event->sync_generation = 0;
        }
        if ($ref !== null) {
            self::keepRemote($event, $vcal, $ref, $moved);
        }
        if ($fieldsGiven) {
            $event->field_values = $fieldValues;
        }
        if (array_key_exists('attendees', $input)) {
            $event->attendees = $input['attendees'] ?: null;
        }
        if (!$occurrenceScope) { // an occurrence's own title and reminder never touch the series
            if (array_key_exists('title', $input)) {
                $event->title_input = (string) $input['title'];
            }
            if (array_key_exists('reminder_minutes', $input)) {
                $event->reminder_minutes = $input['reminder_minutes'];
            }
        }

        DB::transaction(function () use ($event, $vcal) {
            $this->store($event, $vcal);
        });

        return $event->fresh();
    }

    public function delete(CalendarEvent $event, string $scope, ?string $recurrenceId): void
    {
        $this->warning = null;
        if ($scope === self::SCOPE_OCCURRENCE && !$event->is_recurring) {
            throw ValidationException::withMessages(['scope' => __('This event does not repeat.')]);
        }
        $calendar = $event->calendar;
        if ($scope === self::SCOPE_OCCURRENCE) {
            $key = $this->occurrenceKey($event, $recurrenceId);
            $vcal = Reader::read($event->ics);
            $master = EventIcs::master($vcal, $event->uid);
            $tz = new DateTimeZone($event->timezone ?: $calendar->timezoneName());
            EventIcs::removeOverride($vcal, $event->uid, $key, $tz);
            EventIcs::addExdate($master, $key, $tz);
            EventIcs::bumpSequence($master);
            if (!$calendar->isLocal()) {
                $ref = $this->write($calendar, function () use ($calendar, $event, $vcal, $key) {
                    return $this->providers->for($calendar)->update($calendar, $event, $this->remoteCopy($vcal, $event), $key);
                });
                self::keepRemote($event, $vcal, $ref);
            }
            DB::transaction(function () use ($event, $vcal) {
                $this->store($event, $vcal);
            });

            return;
        }
        if (!$calendar->isLocal()) {
            $this->write($calendar, function () use ($calendar, $event) {
                $this->providers->for($calendar)->delete($calendar, $event);
            });
        }
        DB::transaction(function () use ($event) {
            $event->delete(); // occurrences cascade through the foreign key
        });
    }

    public static function newUid(): string
    {
        return bin2hex(random_bytes(16)) . '@' . (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'freescout');
    }

    /** Applies form input to one VEVENT, the master or one of its overrides. Returns true when the master's start or rule changed. */
    private function applyInput(VEvent $component, VEvent $master, CalendarEvent $event, array $input, User $user, DateTimeZone $tz, Calendar $calendar, array $fieldValues, bool $fieldsGiven): bool
    {
        $isMaster = $component === $master;
        $current = EventIcs::read($component, $tz);
        $data = [];
        $titleGiven = array_key_exists('title', $input);
        // A raw title that was never stored (title_input null) is kept as is unless the user types a new one.
        if ($titleGiven || ($fieldsGiven && $event->title_input !== null)) {
            $data['title'] = TitleTemplate::render($calendar, $titleGiven ? (string) $input['title'] : (string) $event->title_input, $fieldValues);
        }
        foreach (['location', 'description'] as $key) {
            if (array_key_exists($key, $input)) {
                $data[$key] = $input[$key];
            }
        }

        // The editor types times in their own timezone, but a timed event keeps the timezone it lives in (TZID or UTC)
        // and a floating one stays floating. Otherwise its exceptions stop matching in weeks where DST differs.
        // Only a component without a time of day yet takes the editor's timezone, unless it is an occurrence of a
        // floating series: that one becomes floating too, on the series' wall clock ($tz).
        if (!$current['all_day']) {
            $zone = $current['start']->getTimezone();
            $floating = $component->DTSTART->isFloating();
        } else {
            $floating = !$isMaster && $master->DTSTART->hasTime() && $master->DTSTART->isFloating();
            $zone = $floating ? $tz : new DateTimeZone(TimeInput::userTimezone($user));
        }
        $scheduleChanged = false;
        if (array_key_exists('start', $input)) {
            $allDay = array_key_exists('all_day', $input) ? (bool) $input['all_day'] : $current['all_day'];
            $times = TimeInput::normalize($allDay, (string) $input['start'], $input['end'] ?? null, TimeInput::userTimezone($user));
            if (!$times['all_day']) {
                $times['start'] = $times['start']->setTimezone($zone);
                $times['end'] = $times['end']->setTimezone($zone);
            }
            $data += ['all_day' => $times['all_day'], 'start' => $times['start'], 'end' => $times['end'], 'floating' => $floating];
            $scheduleChanged = $times['all_day'] !== $current['all_day'] || !self::sameMoment($times['start'], $current['start']);
        }
        if ($isMaster && array_key_exists('recurrence', $input)) {
            $allDay = $data['all_day'] ?? $current['all_day'];
            // UNTIL is the end of that day where the event lives: in UTC for a start with a zone, in local time for a
            // floating one.
            $rrule = $input['recurrence'] ? RecurrenceRule::toRrule($input['recurrence'], $allDay, $zone->getName(), $floating && !$allDay) : null;
            // A rule that means the same keeps its stored text (and the exceptions), however another app wrote it. It is
            // compared in the zone the details show it in. Switching all-day always rewrites it, because UNTIL changes form.
            $unchanged = $rrule === $current['rrule']
                || ($allDay === $current['all_day'] && self::sameRule($current['rrule'], $rrule, $allDay, EventIcs::ruleZone($component, $event->calendar->timezoneName())->getName()));
            if (!$unchanged) {
                $data['rrule'] = $rrule;
                $scheduleChanged = true;
            }
        }
        if ($data) {
            EventIcs::apply($component, $data);
            EventIcs::bumpSequence($component);
        }

        return $isMaster && $scheduleChanged;
    }

    /**
     * Whether any component of the event lists an attendee other than the calendar's own account: the connection's
     * account, the calendar's id when it is an address (Google), or the mailbox a Microsoft calendar belongs to. A room
     * or resource counts, and so does an organizer who lists itself.
     */
    private static function hasAttendees(VCalendar $vcal, string $uid, Calendar $calendar): bool
    {
        $connection = $calendar->calendarConnection;
        $own = [$connection ? $connection->account : null, $calendar->remote_id];
        if (preg_match('~^users/([^/]+)/~', (string) $calendar->remote_id, $match)) {
            $own[] = rawurldecode($match[1]);
        }
        $own = array_filter(array_map(function ($address) {
            return is_string($address) ? EventIcs::email($address) : null;
        }, $own));
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ((string) $vevent->UID !== $uid) {
                continue;
            }
            foreach ($vevent->select('ATTENDEE') as $attendee) {
                $address = EventIcs::email((string) $attendee);
                if ($address !== null && !in_array($address, $own, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Why the calendar's provider can't write this master's rule, when it can say so (ExplainsRecurrenceRefusal). */
    private function refusal(Calendar $calendar, VEvent $master): ?string
    {
        $provider = $this->providers->for($calendar);

        return $provider instanceof ExplainsRecurrenceRefusal ? $provider->recurrenceRefusal($master) : null;
    }

    private function assertOccurrenceInput(CalendarEvent $event, array $input): void
    {
        if (!$event->is_recurring) {
            throw ValidationException::withMessages(['scope' => __('This event does not repeat.')]);
        }
        if (isset($input['calendar_id']) && (int) $input['calendar_id'] !== $event->calendar_id) {
            throw ValidationException::withMessages(['calendar_id' => __('Move the whole series to change its calendar.')]);
        }
        $messages = [
            'recurrence'       => __('Change the repeat rule on the series.'),
            'field_values'     => __('Custom fields belong to the whole series; edit the series to change them.'),
            'reminder_minutes' => __('The reminder belongs to the whole series; edit the series to change it.'),
            'attendees'        => __('The attendees belong to the whole series; edit the series to change them.'),
        ];
        foreach ($messages as $name => $message) {
            if (array_key_exists($name, $input)) {
                throw ValidationException::withMessages([$name => $message]);
            }
        }
    }

    /** Overrides that still carry the series' previous title follow the new one; custom-titled overrides stay. */
    private function propagateTitle(VCalendar $vcal, VEvent $master, string $previousTitle): void
    {
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ($vevent !== $master && (string) $vevent->UID === (string) $master->UID && (string) ($vevent->SUMMARY ?? '') === $previousTitle) {
                EventIcs::apply($vevent, ['title' => (string) $master->SUMMARY]);
                EventIcs::bumpSequence($vevent);
            }
        }
    }

    private function occurrenceKey(CalendarEvent $event, ?string $key): string
    {
        if (!$key || !$this->occurrences->hasOccurrence($event, $key)) {
            throw ValidationException::withMessages(['recurrence_id' => __('This occurrence does not exist.')]);
        }

        return $key;
    }

    /**
     * Creates the event in the target first and deletes it from the source second (skipped for a local source). A
     * failed create throws and changes nothing; a failed delete leaves the event in both calendars and returns the
     * warning for the agent.
     *
     * @return array{0: RemoteRef, 1: ?string} where the target keeps the event, and the warning
     */
    private function moveBetweenProviders(CalendarEvent $event, Calendar $source, Calendar $target, VCalendar $remoteCopy): array
    {
        $ref = $this->write($target, function () use ($target, $remoteCopy) {
            return $this->providers->for($target)->create($target, $remoteCopy);
        });
        if ($source->isLocal()) {
            return [$ref, null];
        }
        try {
            $this->providers->for($source)->delete($source, $event);
        } catch (ProviderException $e) {
            Log::warning('A moved calendar event could not be removed from its previous calendar', [
                'event_id' => $event->id, 'calendar_id' => $source->id, 'provider' => $source->provider, 'status' => $e->httpStatus(), 'error' => $e->userMessage(),
            ]);
            // A sync token would never report the copy that stayed, because it did not change; a full listing shows it.
            $this->sync->relist($source);

            return [$ref, __('The event was moved, but it could not be removed from ":calendar". It is now in both calendars.', ['calendar' => $source->name])];
        }

        return [$ref, null];
    }

    /**
     * Runs one provider write. After a conflict the calendar is synced first, so "Reload and try again" shows the other
     * change at once.
     *
     * @return mixed what the write returns
     */
    private function write(Calendar $calendar, callable $call)
    {
        try {
            return $call();
        } catch (ProviderConflict $e) {
            $this->catchUp($calendar);
            throw $e;
        }
    }

    /**
     * Syncs the calendar right away, within CONFLICT_SYNC_SECONDS. When a run holds it (the cron is busy with it), or the
     * sync could not start, the next run is asked for instead. Nothing here changes the answer: a sync that fails is in
     * last_error, an unexpected error is reported, and the agent gets the conflict all the same.
     */
    private function catchUp(Calendar $calendar): void
    {
        try {
            if ($this->sync->syncNow($calendar, SyncBudget::seconds(self::CONFLICT_SYNC_SECONDS))) {
                return;
            }
        } catch (\Throwable $e) {
            report($e);
        }
        $this->sync->requestSync($calendar);
    }

    /** The VCALENDAR the provider gets: every component of the event carries the readable block, which is never stored. */
    private function remoteCopy(VCalendar $vcal, CalendarEvent $event): VCalendar
    {
        $copy = clone $vcal;
        foreach ($copy->select('VEVENT') as $vevent) {
            if ((string) $vevent->UID === $event->uid) {
                ReadableBlock::addTo($vevent, $event);
            }
        }

        return $copy;
    }

    /**
     * Keeps where the provider stores the event now. After a move every remote field comes from the target (none for
     * a local one); otherwise the id and href stay unless the provider names new ones. A UID the provider assigned
     * replaces FreeScout's, in the VCALENDAR too.
     */
    private static function keepRemote(CalendarEvent $event, VCalendar $vcal, RemoteRef $ref, bool $moved = false): void
    {
        if ($moved || $ref->remoteId !== '') {
            $event->remote_id = $ref->remoteId;
        }
        if ($moved || $ref->href !== null) {
            $event->remote_href = $ref->href;
        }
        $event->etag = $ref->etag;
        if ($ref->uid !== null && $ref->uid !== $event->uid) {
            foreach ($vcal->select('VEVENT') as $vevent) {
                if ((string) $vevent->UID === $event->uid) {
                    $vevent->UID = $ref->uid;
                }
            }
            $event->uid = $ref->uid;
        }
    }

    private function store(CalendarEvent $event, VCalendar $vcal): void
    {
        $event->ics = $vcal->serialize();
        $event->fill(EventIcs::derive($vcal, $event->uid, $event->calendar->timezoneName()));
        $event->save();
        $this->occurrences->refresh($event);
    }

    private function addLineItem(CalendarEvent $event, Conversation $conversation, User $user): void
    {
        Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'user_id'            => $conversation->user_id,
            'created_by_user_id' => $user->id,
            'action_type'        => CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR,
            'source_via'         => Thread::PERSON_USER,
            'source_type'        => Thread::SOURCE_TYPE_WEB,
            'meta'               => ['event_id' => $event->id, 'calendar_id' => $event->calendar_id],
        ]);
    }

    /**
     * Whether two RRULEs describe the same repeat pattern in the form's terms (RecurrenceRule::canonical()). A rule the
     * form can't show (a custom rule) only matches its exact text, which the caller already compared.
     */
    private static function sameRule(?string $stored, ?string $rrule, bool $allDay, string $timezone): bool
    {
        if ($stored === null || $rrule === null) {
            return false;
        }
        $before = RecurrenceRule::canonical($stored, $allDay, $timezone);

        return $before !== null && $before === RecurrenceRule::canonical($rrule, $allDay, $timezone);
    }

    private static function sameMoment($a, $b): bool
    {
        if ($a instanceof DateTimeImmutable && $b instanceof DateTimeImmutable) {
            return $a->getTimestamp() === $b->getTimestamp();
        }

        return (string) $a === (string) $b;
    }
}
