<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Conversation;
use App\Thread;
use App\User;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;

/** A line item in the conversation for every invite action (decision 2026-10-19); LineItemText words it. */
final class InviteLineItem
{
    /** @param array $meta the words the text needs later, when the event may be gone: title, answer, names */
    public static function add(Conversation $conversation, ?User $user, string $action, ?CalendarEvent $event, array $meta = []): void
    {
        Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'user_id'            => $conversation->user_id,
            'created_by_user_id' => $user ? $user->id : null,
            'action_type'        => CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR,
            'source_via'         => Thread::PERSON_USER,
            'source_type'        => Thread::SOURCE_TYPE_WEB,
            'meta'               => ['invite_action' => $action, 'event_id' => $event ? $event->id : null, 'calendar_id' => $event ? $event->calendar_id : null] + $meta,
        ]);
    }
}
