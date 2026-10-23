<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Thread;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;

/**
 * Text for "added to calendar" line items. Handles v3 meta {event_id} and v2 meta {calendar_item_id, event_uid}.
 * Leaves ":person" in place; FreeScout core substitutes it.
 */
final class LineItemText
{
    /** $viewer is the user core renders the text for, when it passes one (web notifications); otherwise the logged-in user. */
    public static function render(Thread $thread, bool $escape, ?User $viewer = null): string
    {
        $meta = (array) $thread->getMetas();
        if (isset($meta['invite_action'])) {
            return self::invite($meta, $escape);
        }
        $calendar = Calendar::find((int) ($meta['calendar_id'] ?? 0));
        // A v3 event keeps its id when it moves to another calendar, so it is found by id alone.
        $event = isset($meta['event_id'])
            ? CalendarEvent::find((int) $meta['event_id'])
            : ($calendar ? self::legacyEvent($calendar, $meta) : null);
        // Named after the calendar the event is in now; the calendar it was added to only names the deleted texts.
        $calendar = $event ? $event->calendar : $calendar;
        if (!$calendar) {
            return __('Added to a calendar, but the calendar has been deleted by now.');
        }
        $name = $escape ? htmlspecialchars($calendar->name) : $calendar->name;
        if (!$event) {
            return __('Added to calendar ":calendar", but the event has been deleted by now.', ['calendar' => $name]);
        }

        // The viewer's own zone and clock, as core shows times. With nobody viewing (core's notification mails, which
        // pass the recipient only to getActionDescription) it is 24-hour in the app's zone, which it names.
        $user = $viewer ?? auth()->user();
        $timezone = new DateTimeZone(TimeInput::userTimezone($user));
        $start = $event->all_day
            ? (new DateTimeImmutable(substr($event->starts_at, 0, 10), $timezone))->format(__('d-m-Y'))
            : (new DateTimeImmutable($event->starts_at, new DateTimeZone('UTC')))->setTimezone($timezone)->format(TimeInput::userClock($user, __('d-m-Y H:i')))
                . ($user ? '' : ' (' . $timezone->getName() . ')');
        $text = __('Added to calendar ":calendar" by :person. Starts at :start.', ['calendar' => $name, 'start' => $start]);
        if (!$escape) {
            return $text;
        }
        $link = CalendarEvent::permalink($event->id);

        return $text . ' <a href="' . htmlspecialchars($link) . '" target="_blank" class="link-blue">' . htmlspecialchars(__('View event')) . '</a>';
    }

    /** An invite action (InviteLineItem); the meta keeps the words, since the event may be gone by now. */
    private static function invite(array $meta, bool $escape): string
    {
        $event = isset($meta['event_id']) ? CalendarEvent::find((int) $meta['event_id']) : null;
        $words = [
            'title'    => (string) ($meta['title'] ?? ''),
            'calendar' => $event ? $event->calendar->name : '',
            'to'       => (string) ($meta['to'] ?? ''),
            'name'     => (string) ($meta['name'] ?? ''),
        ];
        $answer = (string) ($meta['answer'] ?? '');
        switch ($meta['invite_action']) {
            case 'added':
                $text = __(':person added the invite ":title" to calendar ":calendar".', $words);
                break;
            case 'updated':
                $text = __(':person applied the update of the invite ":title".', $words);
                break;
            case 'cancelled':
                $text = __(':person applied the cancellation of the invite ":title".', $words);
                break;
            case 'answered':
                $text = [
                    'accepted'  => __(':person accepted the invite ":title".', $words),
                    'tentative' => __(':person answered maybe to the invite ":title".', $words),
                ][$answer] ?? __(':person declined the invite ":title".', $words);
                break;
            case 'sent':
                $text = __(':person sent the invite ":title" to :to.', $words);
                break;
            case 'sent_update':
                $text = __(':person sent the update of ":title" to :to.', $words);
                break;
            case 'sent_cancellation':
                $text = __(':person sent the cancellation of ":title" to :to.', $words);
                break;
            case 'attendee_answered':
                $text = [
                    'accepted'  => __(':name accepted ":title".', $words),
                    'tentative' => __(':name may attend ":title".', $words),
                ][$answer] ?? __(':name declined ":title".', $words);
                break;
            default:
                $text = __(':person handled the invite ":title".', $words);
        }
        if (!$escape) {
            return $text;
        }
        // The person's name is filled in by core afterwards, so the placeholder survives escaping. On the conversation
        // page core's name comes with whitespace around it, which is why every sentence starts with it, as core's do.
        $html = e($text);
        if ($event) {
            $html .= ' <a href="' . e(CalendarEvent::permalink($event->id)) . '" target="_blank" class="link-blue">' . e(__('View event')) . '</a>';
        }

        return $html;
    }

    private static function legacyEvent(Calendar $calendar, array $meta): ?CalendarEvent
    {
        foreach (['calendar_item_id', 'event_uid'] as $key) {
            if (!empty($meta[$key])) {
                $event = CalendarEvent::where('calendar_id', $calendar->id)->where('legacy_id', (string) $meta[$key])->first();
                if ($event) {
                    return $event;
                }
            }
        }

        if (!empty($meta['event_uid'])) {
            return CalendarEvent::findByUid($calendar->id, (string) $meta['event_uid']);
        }
        // A v2 item could move to another calendar after its line item was written. v2 item ids are unique across
        // calendars, and only the v2 migration stores them as legacy_id on local events.
        if ($calendar->isLocal() && ctype_digit((string) ($meta['calendar_item_id'] ?? ''))) {
            return CalendarEvent::where('legacy_id', (string) $meta['calendar_item_id'])
                ->whereHas('calendar', function ($query) {
                    $query->where('provider', Calendar::PROVIDER_LOCAL);
                })
                ->first();
        }

        return null;
    }
}
