<?php

namespace Modules\LJPcCalendarModule\Http;

use App\Conversation;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\View;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\InviteService;
use Modules\LJPcCalendarModule\Services\TimeInput;

/**
 * The card below a message with an invite (spec section 13): what the invite says, in the agent's zone and clock, and
 * the one thing it lets this agent do. Server-rendered; Public/build/invite.js posts its form and swaps the card.
 */
final class InviteCard
{
    /** @param array $card an InviteService::cards() entry */
    public static function render(array $card, User $user): string
    {
        return View::make('calendar::partials/invite_card', self::vars($card, $user))->render();
    }

    /** The script and its config, once per page. */
    public static function assets(User $user): string
    {
        if (app()->bound('ljpccalendarmodule.invite_assets')) {
            return '';
        }
        app()->instance('ljpccalendarmodule.invite_assets', true);

        return '<script type="application/json" id="ljpc-invite-config">' . FrontendConfig::json(FrontendConfig::invite($user)) . '</script>'
            . Assets::script('invite');
    }

    private static function vars(array $card, User $user): array
    {
        $message = $card['message'];
        $event = $card['event'];
        $state = $card['state'];
        $tz = new DateTimeZone(TimeInput::userTimezone($user));
        $when = TimeInput::range($message->start, $message->end, $message->allDay, $tz, $user);
        $vars = [
            'id'         => $card['invite']->id,
            'heading'    => self::heading($state, $message->method, $event),
            'title'      => $message->summary !== '' ? $message->summary : __('(no title)'),
            'when'       => $when,
            'before'     => null,
            'occurrence' => null,
            'repeats'    => $message->rrule !== null,
            'organizer'  => $message->organizerEmail === null ? null
                : ($message->organizerName && $message->organizerName !== $message->organizerEmail ? $message->organizerName . ' <' . $message->organizerEmail . '>' : $message->organizerEmail),
            'location'   => $message->location,
            'warnings'   => self::warnings($card),
            'status'     => null,
            'link'       => null,
            'calendars'  => [],
            'button'     => null,
            'answers'    => [],
            'answered'   => null,
        ];
        if ($message->recurrenceId !== null && !$message->thisAndFuture) {
            $vars['occurrence'] = __('Only the occurrence of :date.', ['date' => self::keyDate($message->recurrenceId, $tz)]);
        }
        if ($event !== null && $state !== InviteService::STATE_HIDDEN) {
            $vars['link'] = CalendarEvent::permalink($event->id, $message->recurrenceId !== null && !$message->thisAndFuture ? $message->recurrenceId : null);
            $before = self::eventWhen($event, $tz, $user);
            if ($state === InviteService::STATE_UPDATE && $message->recurrenceId === null && $before !== $when) {
                $vars['before'] = $before;
            }
        }
        $calendar = $event ? $event->calendar->name : '';
        $canEdit = $event && !$event->read_only && $user->can('edit', $event->calendar);
        switch ($state) {
            case InviteService::STATE_NEW:
                if ($message->recurrenceId !== null) {
                    $vars['status'] = __('This invite changes an event that is in no calendar yet.');
                    break;
                }
                $vars['calendars'] = CalendarPolicy::viewableCalendars($user)->filter(function ($calendar) use ($user) {
                    return $user->can('create', $calendar);
                })->values()->all();
                if ($vars['calendars']) {
                    $vars['button'] = __('Add to calendar');
                } else {
                    $vars['status'] = __('You can\'t add events to any calendar.');
                }
                break;
            case InviteService::STATE_UPDATE:
            case InviteService::STATE_CANCELLATION:
                if ($canEdit) {
                    $vars['button'] = $state === InviteService::STATE_UPDATE ? __('Apply update')
                        : ($message->recurrenceId !== null ? __('Cancel occurrence') : __('Cancel event'));
                    // The update's heading names the calendar already.
                    if ($state === InviteService::STATE_CANCELLATION) {
                        $vars['status'] = __('This event is in calendar ":calendar".', ['calendar' => $calendar]);
                    }
                } else {
                    $vars['status'] = __('You can\'t change events in calendar ":calendar".', ['calendar' => $calendar]);
                }
                break;
            case InviteService::STATE_CURRENT:
                $vars['status'] = $message->method === 'CANCEL'
                    ? __('This cancellation is applied in calendar ":calendar".', ['calendar' => $calendar])
                    : __('This version is in calendar ":calendar".', ['calendar' => $calendar]);
                break;
            case InviteService::STATE_OUTDATED:
                $vars['status'] = __('A newer version of this invite is in calendar ":calendar".', ['calendar' => $calendar]);
                break;
            case InviteService::STATE_NOTHING_TO_CANCEL:
                $vars['status'] = __('The event this cancels is in no calendar.');
                break;
            case InviteService::STATE_HIDDEN:
                $vars['status'] = __('This event is in a calendar you can\'t view.');
                break;
            case InviteService::STATE_REPLY:
                $vars['status'] = $card['invite']->status === CalendarInvite::STATUS_PROCESSED
                    ? __('This answer is recorded with the event.')
                    : __('FreeScout sent no invite that this answers.');
                break;
        }

        // Yes, Maybe, No (InviteActions::answer), with the rights InvitesController@answer checks.
        $conversation = Conversation::find($card['invite']->conversation_id);
        if ($message->method === 'REQUEST' && $message->recurrenceId === null && $conversation
            && in_array($state, [InviteService::STATE_NEW, InviteService::STATE_UPDATE, InviteService::STATE_CURRENT], true)
            && filter_var((string) $message->organizerEmail, FILTER_VALIDATE_EMAIL) && InviteService::ourAddress($message, $conversation->mailbox) !== null) {
            foreach (['accepted' => __('Yes'), 'tentative' => __('Maybe'), 'declined' => __('No')] as $answer => $label) {
                if ($state === InviteService::STATE_NEW ? ($answer === 'declined' || $vars['calendars']) : $canEdit) {
                    $vars['answers'][$answer] = $label;
                }
            }
        }
        $answered = [
            'accepted'  => __('You accepted this invite.'),
            'tentative' => __('You answered maybe to this invite.'),
            'declined'  => __('You declined this invite.'),
        ];
        $vars['answered'] = $answered[(string) $card['invite']->response] ?? null;

        return $vars;
    }

    private static function heading(string $state, string $method, ?CalendarEvent $event): string
    {
        if ($method === 'REPLY') {
            return __('Answer to an invite');
        }
        if ($method === 'CANCEL') {
            return __('Cancellation');
        }
        switch ($state) {
            case InviteService::STATE_UPDATE:
                return __('Update to an event in calendar ":calendar"', ['calendar' => $event->calendar->name]);
            case InviteService::STATE_OUTDATED:
                return __('Outdated version');
            case InviteService::STATE_NEW:
                return __('New invite');
            default:
                return __('Invite');
        }
    }

    private static function warnings(array $card): array
    {
        $texts = [];
        foreach ($card['warnings'] as $warning) {
            if ($warning === 'sender_not_organizer') {
                $texts[] = __('This mail comes from :sender, not from the organizer :organizer. Check the invite before you use it.', [
                    'sender' => $card['invite']->sender_email, 'organizer' => $card['message']->organizerEmail,
                ]);
            } elseif ($warning === 'this_and_following') {
                $texts[] = __('This changes this occurrence and the ones after it. Applying it moves the whole series to start here, with these details.');
            }
        }

        return $texts;
    }

    /** "03-11-2026, 10:00 – 11:00"; an all-day event shows dates only, its end being the day after it. */
    private static function eventWhen(CalendarEvent $event, DateTimeZone $tz, User $user): string
    {
        $utc = new DateTimeZone('UTC');
        if ($event->all_day) {
            return TimeInput::range(new DateTimeImmutable(substr($event->starts_at, 0, 10)), new DateTimeImmutable(substr($event->ends_at, 0, 10)), true, $tz, $user);
        }

        return TimeInput::range(new DateTimeImmutable($event->starts_at, $utc), new DateTimeImmutable($event->ends_at, $utc), false, $tz, $user);
    }

    /** The date of an occurrence key (20261014T080000Z, or 20261014 when all-day), in the agent's zone. */
    private static function keyDate(string $key, DateTimeZone $tz): string
    {
        return strlen($key) === 8
            ? DateTimeImmutable::createFromFormat('!Ymd', $key)->format(__('d-m-Y'))
            : DateTimeImmutable::createFromFormat('Ymd\\THis\\Z', $key, new DateTimeZone('UTC'))->setTimezone($tz)->format(__('d-m-Y'));
    }
}
