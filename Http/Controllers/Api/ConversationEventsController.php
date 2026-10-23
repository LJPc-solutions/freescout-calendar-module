<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Api;

use App\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\Http\EventPresenter;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\InviteSender;
use Modules\LJPcCalendarModule\Services\TimeInput;

class ConversationEventsController extends Controller
{
    public function store(Request $request, EventService $events, InviteSender $invites, $id)
    {
        $user = $request->user();
        $conversation = Conversation::find($id);
        abort_unless($conversation && $user->can('view', $conversation), 404);

        $data = EventsController::validateEvent($request, true);
        $calendar = EventsController::calendarFor($user, $data['calendar_id'], 'create');
        $data['attendees'] = InviteSender::attendees((array) ($data['attendees'] ?? []), $conversation);
        $data['organizer'] = InviteSender::organizer($conversation);

        try {
            $event = $events->create($calendar, $user, $data, $conversation);
        } catch (ProviderException $e) {
            return $e->toResponse(); // nothing was saved
        }
        if ($event->attendees) {
            $invites->send($event, $user, 'REQUEST', $event->attendees, (string) ($data['message'] ?? ''), 'sent');
        }

        return response()->json(EventsController::withWarning(EventPresenter::event($event->fresh(), $user, TimeInput::userTimezone($user)), $events), 201);
    }
}
