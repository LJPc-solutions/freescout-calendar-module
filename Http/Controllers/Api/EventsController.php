<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use App\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Http\EventPresenter;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\DropsExceptions;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\InviteSender;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\SeriesNotWritable;
use Modules\LJPcCalendarModule\Services\TimeInput;
use Modules\LJPcCalendarModule\Services\VTimezone;

class EventsController extends Controller
{
    private EventService $events;
    private OccurrenceService $occurrences;
    private InviteSender $invites;

    public function __construct(EventService $events, OccurrenceService $occurrences, InviteSender $invites)
    {
        $this->events = $events;
        $this->occurrences = $occurrences;
        $this->invites = $invites;
    }

    public function index(Request $request)
    {
        $request->validate(['start' => 'required|string|max:40', 'end' => 'required|string|max:40']);
        $user = $request->user();
        $timezone = TimeInput::userTimezone($user);
        [$from, $to] = self::range((string) $request->input('start'), (string) $request->input('end'), $timezone);

        $calendars = CalendarPolicy::viewableCalendars($user)->keyBy('id');
        $rows = $this->occurrences->between($calendars->keys()->all(), $from, $to, $timezone);
        // The list shows titles and flags only; loading every event's VCALENDAR would multiply the response time.
        $events = CalendarEvent::whereIn('id', array_unique(array_column($rows, 'event_id')))
            ->get(['id', 'calendar_id', 'summary', 'is_recurring', 'read_only', 'invite_state'])
            ->keyBy('id');
        $canEdit = $calendars->map(function (Calendar $calendar) use ($user) {
            return $user->can('edit', $calendar);
        });

        $result = [];
        foreach ($rows as $row) {
            if (isset($events[$row['event_id']])) {
                $event = $events[$row['event_id']];
                $result[] = EventPresenter::occurrence($row, $event, (bool) $canEdit[$event->calendar_id], $timezone);
            }
        }

        return response()->json($result);
    }

    public function show(Request $request, $ref)
    {
        $user = $request->user();
        $request->validate(['recurrence_id' => 'nullable|string|max:20']);
        $event = self::findVisible((string) $ref, CalendarPolicy::viewableCalendarIds($user));
        abort_unless($event !== null, 404);
        // A permalink to an occurrence the series no longer produces (deleted, or the series moved) would otherwise
        // show the series' own times as that occurrence; the page opens the series instead.
        $key = $request->input('recurrence_id');
        if ($key !== null && !($event->is_recurring && $this->occurrences->hasOccurrence($event, (string) $key))) {
            return response()->json(['code' => 'occurrence_missing', 'message' => __('This occurrence no longer exists.')], 404);
        }

        return response()->json(EventPresenter::event($event, $user, TimeInput::userTimezone($user), $key));
    }

    public function store(Request $request)
    {
        $data = self::validateEvent($request, true);
        if (!empty($data['attendees'])) {
            throw ValidationException::withMessages(['attendees' => __('Only events added from a conversation can have attendees.')]);
        }
        $user = $request->user();
        $calendar = self::calendarFor($user, $data['calendar_id'], 'create');

        try {
            $event = $this->events->create($calendar, $user, $data);
        } catch (ProviderException $e) {
            return $e->toResponse(); // nothing was saved
        }

        return response()->json(self::withWarning(EventPresenter::event($event->fresh(), $user, TimeInput::userTimezone($user)), $this->events), 201);
    }

    public function update(Request $request, $id)
    {
        $data = self::validateEvent($request, false);
        $user = $request->user();
        $event = self::findEditable($user, $id);
        if (isset($data['calendar_id']) && (int) $data['calendar_id'] !== $event->calendar_id) {
            self::calendarFor($user, $data['calendar_id'], 'create');
        }
        $before = (array) $event->attendees;
        $sequence = $event->sequence;
        $invites = InviteSender::canInvite($event);
        if (array_key_exists('attendees', $data)) {
            if (!$invites) {
                throw ValidationException::withMessages(['attendees' => __('Only events added from a conversation can have attendees.')]);
            }
            $data['attendees'] = InviteSender::attendees((array) $data['attendees'], $event->conversation, $before);
            $data['organizer'] = InviteSender::organizer($event->conversation);
        }

        try {
            $event = $this->events->update($event, $user, $data);
        } catch (DropsExceptions $e) {
            // Nothing was saved. The agent confirms, and the same change comes back with drop_exceptions.
            return response()->json([
                'code'    => 'drops_exceptions',
                'message' => __('Edited and deleted occurrences of this series will be reset. Continue?'),
            ], 409);
        } catch (SeriesNotWritable $e) {
            return response()->json(['code' => 'series_not_writable', 'message' => $e->getMessage()], 403);
        } catch (ProviderException $e) {
            return $e->toResponse(); // nothing was saved
        }
        // "Notify attendees" is on unless the agent turned it off (a drag on the calendar sends no choice).
        if ($invites && ($data['notify'] ?? true) && ($before || $event->attendees)) {
            // EventService raises SEQUENCE for every change attendees see; an occurrence's change raises its own.
            $changed = ($data['scope'] ?? '') === EventService::SCOPE_OCCURRENCE || $event->sequence !== $sequence;
            $this->invites->notify($event, $user, $before, $changed);
        }

        return response()->json(self::withWarning(EventPresenter::event($event, $user, TimeInput::userTimezone($user)), $this->events));
    }

    public function destroy(Request $request, $id)
    {
        $request->validate(['scope' => 'nullable|in:series,occurrence', 'recurrence_id' => 'nullable|string|max:20', 'notify' => 'nullable|boolean']);
        $user = $request->user();
        $event = self::findEditable($user, $id);
        $scope = (string) $request->input('scope', EventService::SCOPE_SERIES);

        try {
            $this->events->delete($event, $scope, $request->input('recurrence_id'));
        } catch (ProviderException $e) {
            return $e->toResponse(); // nothing was deleted
        }
        // The attendees hear of it, unless the agent chose otherwise: a deleted occurrence as an update of the series.
        if ($event->attendees && $request->input('notify', true) && InviteSender::canInvite($event)) {
            $scope === EventService::SCOPE_OCCURRENCE
                ? $this->invites->send($event->fresh(), $user, 'REQUEST', $event->attendees, '', 'sent_update')
                : $this->invites->send($event, $user, 'CANCEL', $event->attendees, '', 'sent_cancellation');
        }

        return response()->json(['deleted' => true]);
    }

    /** Adds the service's warning about a write that succeeded only partly (a move the source didn't complete). */
    public static function withWarning(array $event, EventService $events): array
    {
        $warning = $events->lastWarning();

        return $warning === null ? $event : $event + ['warning' => $warning];
    }

    /**
     * A calendar that doesn't exist or isn't viewable is a 404, so ids can't be probed.
     * A viewable calendar without the given right (create/edit) is a 403, unless only its account stands in the way.
     */
    public static function calendarFor(User $user, $id, string $ability): Calendar
    {
        $calendar = Calendar::find($id);
        abort_unless($calendar && $user->can('view', $calendar), 404);
        if (!$user->can($ability, $calendar)) {
            self::refuseWithoutAccount($user, $calendar, $ability);
            abort(403);
        }

        return $calendar;
    }

    public static function findEditable(User $user, $id): CalendarEvent
    {
        $event = CalendarEvent::find($id);
        abort_unless($event && $user->can('view', $event->calendar), 404);
        abort_if($event->read_only, 403);
        if (!$user->can('edit', $event->calendar)) {
            self::refuseWithoutAccount($user, $event->calendar, 'edit');
            abort(403);
        }

        return $event;
    }

    /**
     * Someone who may write in a calendar whose account must sign in again (needs_reauth, or a v2 connection without
     * a login) gets what a write that met the refused token gets: 502 connection_needs_reauth. The page shows such a
     * calendar read-only, so this answers a page that was opened before.
     */
    private static function refuseWithoutAccount(User $user, Calendar $calendar, string $ability): void
    {
        if (!$calendar->usesConnection() || $calendar->remote_read_only || !CalendarPolicy::rights($user, $calendar)['can_' . $ability]) {
            return;
        }
        $connection = $calendar->calendarConnection;
        if (!$connection || $connection->status !== CalendarConnection::STATUS_OK) {
            throw new HttpResponseException((new ConnectionNeedsReauth())->toResponse());
        }
    }

    /** Shape validation only; EventService validates meaning (times, fields, recurrence). */
    public static function validateEvent(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'calendar_id'      => $required . '|integer',
            'title'            => 'nullable|string|max:500',
            'all_day'          => 'sometimes|boolean',
            'start'            => $required . '|string|max:40',
            'end'              => 'nullable|string|max:40',
            'location'         => 'nullable|string|max:500',
            'description'      => 'nullable|string|max:20000',
            'recurrence'       => 'nullable|array',
            'field_values'     => 'sometimes|array',
            'reminder_minutes' => 'nullable|integer|min:-1|max:40320',
            'scope'            => 'sometimes|in:series,occurrence',
            'recurrence_id'    => 'nullable|string|max:20',
            'drop_exceptions'  => 'nullable|boolean',
            'timezone'         => 'nullable|string|max:64',
            // Invites (InviteSender): only for events linked to a conversation.
            'attendees'        => 'sometimes|array|max:' . InviteSender::MAX_ATTENDEES,
            'attendees.*'      => 'nullable|string|max:191',
            'message'          => 'nullable|string|max:5000',
            'notify'           => 'sometimes|boolean',
        ]);
        // A named zone only (the details send one): an offset would be written as a TZID no calendar app resolves.
        if (isset($data['timezone']) && !VTimezone::isNamedZone($data['timezone'])) {
            throw ValidationException::withMessages(['timezone' => __('Choose a time zone by its name, such as Europe/Amsterdam.')]);
        }

        return $data;
    }

    private static function range(string $start, string $end, string $timezone): array
    {
        try {
            $from = new DateTimeImmutable($start, new DateTimeZone($timezone));
            $to = new DateTimeImmutable($end, new DateTimeZone($timezone));
        } catch (Exception $e) {
            throw ValidationException::withMessages(['start' => __('Invalid date and time.')]);
        }
        if ($to <= $from || $from->diff($to)->days > 400) {
            throw ValidationException::withMessages(['end' => __('The range must be longer than zero and at most 400 days.')]);
        }

        return [$from, $to];
    }

    private static function findVisible(string $ref, array $calendarIds): ?CalendarEvent
    {
        if (!$calendarIds) {
            return null;
        }
        $visible = function () use ($calendarIds) {
            return CalendarEvent::whereIn('calendar_id', $calendarIds);
        };
        // Permalinks: "e<id>" is a v3 event. A bare number is a v2 item id (legacy_id) and never a v3 id, because the
        // migration numbers events anew; an old link to a deleted v2 item must not open an unrelated v3 event.
        if (preg_match('/^e(\d+)\z/', $ref, $match)) {
            return $visible()->find((int) $match[1]);
        }

        return $visible()->where('legacy_id', $ref)->first()
            ?? $visible()->where('uid_hash', CalendarEvent::hashUid($ref))->first();
    }
}
