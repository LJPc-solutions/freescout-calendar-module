<?php

namespace Modules\LJPcCalendarModule\Http;

use App\Conversation;
use App\User;
use Modules\LJPcCalendarModule\Services\HealthService;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\TimeInput;

/** Server data for the frontend bundles, passed as JSON in a non-executed script tag (never Blade inside JavaScript). */
final class FrontendConfig
{
    public static function page(User $user): array
    {
        return self::base($user) + [
            'user'               => ['id' => $user->id, 'is_admin' => $user->isAdmin()],
            'routes'             => [
                'calendars' => route('ljpccalendarmodule.api.calendars'),
                'events'    => route('ljpccalendarmodule.api.events'),
                'event'     => route('ljpccalendarmodule.api.events.show', ['ref' => '__ID__']),
                'settings'  => $user->isAdmin() ? SettingsPage::url() : null,
                'sync'      => route('ljpccalendarmodule.api.sync'),
            ],
            'migration_complete' => app(MigrationService::class)->isFinished(),
            // Problems an administrator has to solve (text and settings link); agents get nothing extra.
            'health'             => $user->isAdmin() ? app(HealthService::class)->problems() : [],
        ];
    }

    public static function conversation(User $user, Conversation $conversation): array
    {
        return self::base($user) + [
            'routes'       => [
                'calendars'           => route('ljpccalendarmodule.api.calendars'),
                'conversation_events' => route('ljpccalendarmodule.api.conversations.events.store', ['id' => $conversation->id]),
                'page'                => route('ljpccalendarmodule.index'),
            ],
            'conversation' => [
                'id'             => $conversation->id,
                'subject'        => (string) $conversation->getSubject(),
                'customer_email' => $conversation->customer_email ?: null,
                'assignee'       => $conversation->user ? $conversation->user->getFullName() : null,
            ],
        ];
    }

    public static function invite(User $user): array
    {
        return self::base($user);
    }

    public static function json(array $config): string
    {
        return json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** What every bundle needs: the agent's own language (not the install's), timezone and clock, and the strings. */
    private static function base(User $user): array
    {
        return [
            'locale'   => app()->getLocale(),
            'rtl'      => \Helper::isLocaleRtl(),
            'timezone' => TimeInput::userTimezone($user),
            'hour12'   => (int) $user->time_format === User::TIME_FORMAT_12,
            'csrf'     => csrf_token(),
            'strings'  => FrontendStrings::all(),
        ];
    }
}
