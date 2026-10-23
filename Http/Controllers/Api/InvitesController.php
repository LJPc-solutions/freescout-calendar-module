<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Api;

use App\Conversation;
use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Http\InviteCard;
use Modules\LJPcCalendarModule\Services\InviteActions;
use Modules\LJPcCalendarModule\Services\InviteService;
use Modules\LJPcCalendarModule\Services\SeriesNotWritable;

/** The clicks on an invite card (spec section 13), with the rights of section 10. */
class InvitesController extends Controller
{
    public function apply(Request $request, InviteActions $actions, $id)
    {
        $user = $request->user();
        $invite = self::find($user, $id);
        $card = $actions->card($invite, $user);
        $calendar = null;
        if ($card['state'] === InviteService::STATE_NEW && $request->filled('calendar_id')) {
            $calendar = EventsController::calendarFor($user, $request->input('calendar_id'), 'create');
        } elseif (in_array($card['state'], [InviteService::STATE_UPDATE, InviteService::STATE_CANCELLATION], true)) {
            EventsController::findEditable($user, $card['event']->id);
        }
        // Any other state is refused by InviteActions, with the reason.
        return self::respond($actions, $invite, $user, function () use ($actions, $invite, $user, $calendar, $card) {
            $event = $actions->apply($invite, $user, $calendar);
            switch ($card['state']) {
                case InviteService::STATE_NEW:
                    return __('Added to calendar ":calendar".', ['calendar' => $event->calendar->name]);
                case InviteService::STATE_UPDATE:
                    return __('The update is applied.');
                default:
                    return __('The event is cancelled.');
            }
        });
    }

    /** Yes, Maybe or No: Yes and Maybe add or update the event like apply; an event in a calendar records the answer. */
    public function answer(Request $request, InviteActions $actions, $id)
    {
        $user = $request->user();
        $invite = self::find($user, $id);
        $card = $actions->card($invite, $user);
        $response = (string) $request->input('response');
        $calendar = null;
        if ($card['state'] === InviteService::STATE_NEW) {
            if ($response !== 'declined' && $request->filled('calendar_id')) {
                $calendar = EventsController::calendarFor($user, $request->input('calendar_id'), 'create');
            }
        } elseif ($card['event'] !== null && in_array($card['state'], [InviteService::STATE_UPDATE, InviteService::STATE_CURRENT], true)) {
            EventsController::findEditable($user, $card['event']->id);
        }

        return self::respond($actions, $invite, $user, function () use ($actions, $invite, $user, $calendar, $response) {
            $actions->answer($invite, $user, $response, $calendar);

            return [
                'accepted'  => __('You accepted the invite. The organizer gets your answer.'),
                'tentative' => __('You answered maybe. The organizer gets your answer.'),
                'declined'  => __('You declined the invite. The organizer gets your answer.'),
            ][$response];
        });
    }

    private static function find(User $user, $id): CalendarInvite
    {
        $invite = CalendarInvite::find($id);
        $conversation = $invite ? Conversation::find($invite->conversation_id) : null;
        abort_unless($conversation && $user->can('view', $conversation), 404);

        return $invite;
    }

    /** Runs the click and answers with its message and the card as it is now. */
    private static function respond(InviteActions $actions, CalendarInvite $invite, User $user, callable $click)
    {
        try {
            $message = $click();
        } catch (SeriesNotWritable $e) {
            return response()->json(['code' => 'series_not_writable', 'message' => $e->getMessage()], 403);
        } catch (ProviderException $e) {
            return $e->toResponse(); // nothing was saved
        }

        return response()->json(['message' => $message, 'html' => InviteCard::render($actions->card($invite->fresh(), $user), $user)]);
    }
}
