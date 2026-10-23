<?php

namespace Modules\LJPcCalendarModule\Http;

use App\Mailbox;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\CalendarPicker;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Modules\LJPcCalendarModule\Services\HealthService;
use Modules\LJPcCalendarModule\Services\MigrationService;

/**
 * Settings > Calendar: one FreeScout settings section with two tabs. "Calendars" shows the calendar list, the new
 * calendar's type chooser and forms, or one calendar; "Connections" shows the connected accounts, the apps and
 * forms that connect one, or the pick screen of one account.
 */
final class SettingsPage
{
    public const PROVIDERS = [
        Calendar::PROVIDER_LOCAL     => 'FreeScout calendar',
        Calendar::PROVIDER_ICS       => 'ICS feed',
        Calendar::PROVIDER_CALDAV    => 'CalDAV',
        Calendar::PROVIDER_GOOGLE    => 'Google Calendar',
        Calendar::PROVIDER_MICROSOFT => 'Microsoft 365',
    ];

    /** v2's color swatches, offered by the native color picker. */
    public const SWATCHES = ['#3498db', '#e74c3c', '#e67e22', '#1abc9c', '#9b59b6', '#2ecc71', '#f1c40f', '#833471', '#9980fa'];

    /** The module's wiki, where the setup of each provider is explained (pages Google-Calendar, Microsoft-365, CalDAV). */
    public const WIKI_URL = 'https://github.com/LJPc-solutions/freescout-calendar-module/wiki/';

    /** The section's URL; with a calendar id (or "new") it opens that calendar's form. */
    public static function url($calendar = null): string
    {
        $url = route('settings', ['section' => 'calendar']);

        return $calendar === null ? $url : $url . '?calendar=' . rawurlencode((string) $calendar);
    }

    /**
     * The Connections tab; with a connection id, the screen where its calendars are chosen, and with an owner, the
     * calendars of that Microsoft shared mailbox.
     */
    public static function connectionsUrl(?int $connectionId = null, ?string $owner = null): string
    {
        $url = route('settings', ['section' => 'calendar']) . '?tab=connections';
        if ($connectionId === null) {
            return $url;
        }

        return $url . '&connection=' . $connectionId . '&pick=1' . ($owner !== null ? '&owner=' . rawurlencode($owner) : '');
    }

    public static function wikiUrl(string $page): string
    {
        return self::WIKI_URL . $page;
    }

    /** An account's row on the Connections tab. */
    public static function accountUrl(CalendarConnection $connection): string
    {
        return self::connectionsUrl() . '#ljpc-connection-' . $connection->id;
    }

    /** Where an administrator connects an account again: the provider's sign-in, or the CalDAV login on the Connections tab. */
    public static function reauthorizeUrl(CalendarConnection $connection): string
    {
        if (in_array($connection->provider, ConnectionService::PROVIDERS, true)) {
            return route('ljpccalendarmodule.oauth.start', ['provider' => $connection->provider, 'connection' => $connection->id]);
        }

        return self::accountUrl($connection);
    }

    /** Template variables for the settings.section_params filter. */
    public static function vars(Request $request): array
    {
        $vars = ['mode' => 'list', 'tab' => 'calendars', 'migration' => self::migration(), 'health' => app(HealthService::class)->problems()];
        if ($request->query('tab') === 'connections') {
            return self::connectionsVars($request) + ['tab' => 'connections'] + $vars;
        }
        $param = $request->query('calendar');
        $param = is_string($param) ? $param : '';
        if ($param === 'new') {
            $accounts = CalendarConnection::where('status', CalendarConnection::STATUS_OK)->orderBy('provider')->orderBy('account')->orderBy('id')->get();

            return ['mode' => 'new', 'type' => self::newType($request), 'accounts' => $accounts] + $vars;
        }
        $calendar = ctype_digit($param) ? Calendar::with('permissionRows', 'calendarConnection')->find((int) $param) : null;
        if ($calendar) {
            return ['mode' => 'edit', 'calendar' => $calendar, 'subjects' => self::subjects(), 'rights' => self::rights($calendar)] + $vars;
        }

        return ['calendars' => Calendar::with('calendarConnection')->orderBy('display_priority')->orderBy('name')->get(), 'missing' => $param !== ''] + $vars;
    }

    /** The new-calendar types: value => [title, explanation]. */
    public static function newTypes(): array
    {
        return [
            'local'   => [__('FreeScout calendar'), __('Events live in FreeScout. Agents create and change them here.')],
            'ics'     => [__('ICS feed'), __('Shows the events of a calendar link from another app, such as Google, Outlook or a booking system. Read-only.')],
            'account' => [__('From a connected account'), __('Calendars of a Google, Microsoft 365 or CalDAV account. Changes sync both ways.')],
        ];
    }

    /**
     * The new-calendar form that was posted and failed: "ics" or "local" (also for a post without a provider); null when
     * none was. Only that form shows the posted values and errors.
     */
    public static function postedProvider(): ?string
    {
        if (!session()->hasOldInput()) {
            return null;
        }

        return old('provider') === Calendar::PROVIDER_ICS ? Calendar::PROVIDER_ICS : Calendar::PROVIDER_LOCAL;
    }

    /** The sync interval choices, minutes => label: the values of Calendar::SYNC_INTERVALS. */
    public static function syncIntervals(): array
    {
        $labels = [];
        foreach (Calendar::SYNC_INTERVALS as $minutes) {
            if ($minutes === 1) {
                $labels[$minutes] = __('Every minute');
            } elseif ($minutes < 60) {
                $labels[$minutes] = __('Every :count minutes', ['count' => $minutes]);
            } elseif ($minutes === 60) {
                $labels[$minutes] = __('Every hour');
            } elseif ($minutes < 1440) {
                $labels[$minutes] = __('Every :count hours', ['count' => intdiv($minutes, 60)]);
            } else {
                $labels[$minutes] = __('Once a day');
            }
        }

        return $labels;
    }

    /** A stored UTC time (Y-m-d H:i:s) in the administrator's time zone and clock, the way FreeScout shows dates. */
    public static function time(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        return User::dateFormat(Carbon::createFromFormat('Y-m-d H:i:s', $utc, 'UTC'), 'M j, Y H:i');
    }

    /**
     * Whether an external calendar is still busy with its first sync or with a listing that did not finish (an error
     * or a broken account is shown as such instead). The settings script keeps asking for its status while it is.
     */
    public static function isSyncing(Calendar $calendar): bool
    {
        if ($calendar->isLocal() || !$calendar->enabled || $calendar->export_token === null || trim((string) $calendar->last_error) !== '') {
            return false;
        }
        $connection = $calendar->calendarConnection;
        if ($calendar->usesConnection() && (!$connection || $connection->status !== CalendarConnection::STATUS_OK)) {
            return false;
        }

        return $calendar->last_synced_at === null || !empty($calendar->sync_state['_listing']);
    }

    /** Calendars just added (picked, or a new ICS feed): the page starts their first sync without waiting for the scheduler. */
    public static function syncStartIds(): array
    {
        return array_map('intval', (array) session('ljpccalendarmodule.sync_start', []));
    }

    public static function connectionStatus(string $status): string
    {
        return [
            CalendarConnection::STATUS_OK           => __('OK'),
            CalendarConnection::STATUS_NEEDS_REAUTH => __('Needs reauthorization'),
            CalendarConnection::STATUS_ERROR        => __('Error'),
        ][$status] ?? $status;
    }

    /** The host of a server address, shown next to a CalDAV account. */
    public static function host(?string $url): string
    {
        return (string) parse_url((string) $url, PHP_URL_HOST);
    }

    /** A title template placeholder: {{title}}, or {{field:<id>}} for a custom field. */
    public static function token($fieldId = null): string
    {
        return $fieldId === null ? '{{title}}' : '{{field:' . $fieldId . '}}';
    }

    /** Whether a color is #rrggbb, the only form the settings store and print. */
    public static function isColor($color): bool
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}\z/', $color) === 1;
    }

    /** A stored color for a style attribute; anything that isn't #rrggbb falls back to the default blue. */
    public static function color($color): string
    {
        return self::isColor($color) ? $color : '#3498db';
    }

    /** Old input as text; crafted input that isn't a string (name[]=x) falls back to the default. */
    public static function oldString(string $key, $default = null): string
    {
        $old = old($key, $default);

        return is_string($old) || is_int($old) ? (string) $old : (is_string($default) || is_int($default) ? (string) $default : '');
    }

    /** Whether the form shows posted values: after a failed save Laravel flashes the input, marker included. */
    public static function posted(): bool
    {
        return old('form_complete') !== null;
    }

    /** Field rows to render, keyed by row key: the posted rows after a failed save, else the fields plus one empty row. */
    public static function fieldRows(Calendar $calendar): array
    {
        $rows = [];
        if (self::posted()) {
            foreach ((array) old('fields', []) as $key => $row) {
                $row = (array) $row;
                $rows[(string) $key] = [
                    'id'       => self::text($row['id'] ?? ''),
                    'name'     => self::text($row['name'] ?? ''),
                    'type'     => self::text($row['type'] ?? 'text'),
                    'required' => !empty($row['required']),
                    'options'  => self::text($row['options'] ?? ''),
                    'remove'   => !empty($row['remove']),
                ];
            }

            return $rows;
        }
        foreach ($calendar->fieldDefinitions() as $field) {
            $rows['f' . $field['id']] = [
                'id'       => (string) $field['id'],
                'name'     => (string) $field['name'],
                'type'     => (string) $field['type'],
                'required' => !empty($field['required']),
                'options'  => implode("\n", (array) ($field['options'] ?? [])),
                'remove'   => false,
            ];
        }
        $rows['new0'] = ['id' => '', 'name' => '', 'type' => 'text', 'required' => false, 'options' => '', 'remove' => false];

        return $rows;
    }

    private static function text($value): string
    {
        return is_array($value) || is_object($value) ? '' : (string) $value;
    }

    /** Whether a permission box is ticked: the posted form after a failed save, else the stored rows. */
    public static function ticked(array $rights, string $type, int $id, string $right): bool
    {
        if (self::posted()) {
            return (bool) old("permissions.$type.$id.$right");
        }

        return !empty($rights["$type:$id"][$right]);
    }

    public static function fieldTypes(): array
    {
        return [
            'text'        => __('Text'),
            'number'      => __('Number'),
            'dropdown'    => __('Dropdown'),
            'multiselect' => __('Multiple choice'),
            'boolean'     => __('Yes or no'),
            'date'        => __('Date'),
            'email'       => __('Email address'),
            'source'      => __('Source'),
        ];
    }

    /** The same choices as the event form's reminder (Resources/frontend/src/shared/format.ts). */
    public static function reminderOptions(): array
    {
        return [
            5     => __('5 minutes before'),
            10    => __('10 minutes before'),
            15    => __('15 minutes before'),
            30    => __('30 minutes before'),
            60    => __('1 hour before'),
            120   => __('2 hours before'),
            1440  => __('1 day before'),
            2880  => __('2 days before'),
            10080 => __('1 week before'),
        ];
    }

    /** The rights the permission matrix offers; a read-only calendar (Calendar::isReadOnly()) only offers viewing. */
    public static function rightLabels(bool $readOnly = false): array
    {
        $labels = [
            'show_in_dashboard' => __('Dashboard'),
            'can_view'          => __('View'),
            'can_create'        => __('Create'),
            'can_edit'          => __('Edit'),
        ];

        return $readOnly ? array_diff_key($labels, array_flip(CalendarForm::WRITE_RIGHTS)) : $labels;
    }

    /** The confirmation of "Delete" on the Connections tab, naming the calendars that go with the connection. */
    public static function deleteQuestion(CalendarConnection $connection): string
    {
        $names = $connection->calendars->pluck('name')->map(function ($name) {
            return '"' . $name . '"';
        })->all();
        if (!$names) {
            return __('Delete this connection? No calendar uses it.');
        }
        $provider = [
            Calendar::PROVIDER_GOOGLE    => 'Google',
            Calendar::PROVIDER_MICROSOFT => 'Microsoft 365',
        ][$connection->provider] ?? __('the CalDAV server');

        return __('Delete this connection and its calendars :names? Their events are removed from FreeScout, not from :provider.', ['names' => implode(', ', $names), 'provider' => $provider]);
    }

    /** The Connections tab, or the pick screen of ?connection=<id>&pick=1 (with &owner=<address> for a shared mailbox). */
    private static function connectionsVars(Request $request): array
    {
        $vars = ['mode' => 'connections', 'missing' => false, 'apps' => self::apps(), 'connections' => CalendarConnection::with(['calendars' => function ($query) {
            $query->orderBy('name')->select(['id', 'connection_id', 'name']);
        }])->withCount('calendars')->orderBy('provider')->orderBy('account')->orderBy('id')->get()];
        $id = $request->query('connection');
        if ($request->query('pick') !== '1' || !is_string($id) || !ctype_digit($id)) {
            return $vars;
        }
        $connection = CalendarConnection::find((int) $id);
        if ($connection === null) {
            return ['missing' => true] + $vars;
        }

        return ['mode' => 'pick', 'pick' => self::pick($connection, $request->query('owner'))] + $vars;
    }

    /** The Google and Microsoft apps as the forms show them: never the secret, only whether one is saved. */
    private static function apps(): array
    {
        $service = app(ConnectionService::class);
        $apps = [];
        foreach (ConnectionService::PROVIDERS as $provider) {
            $app = $service->appCredentials($provider) + ['redirect_url' => $service->redirectUrl($provider)];
            $app['ready'] = $app['client_id'] !== '' && $app['has_secret'] && ($provider !== 'microsoft' || $app['tenant'] !== '');
            $apps[$provider] = $app;
        }

        return $apps;
    }

    /** The pick screen: the account's calendars, or why they can't be listed. Only Microsoft has shared mailboxes. */
    private static function pick(CalendarConnection $connection, $owner): array
    {
        $owner = $connection->provider === Calendar::PROVIDER_MICROSOFT && is_string($owner) && trim($owner) !== '' ? mb_strtolower(trim($owner)) : null;
        $pick = ['connection' => $connection, 'owner' => $owner, 'calendars' => [], 'error' => null];
        if ($owner !== null && filter_var($owner, FILTER_VALIDATE_EMAIL) === false) {
            $pick['error'] = __('Enter the email address of the shared mailbox.');

            return $pick;
        }
        try {
            $pick['calendars'] = app(CalendarPicker::class)->list($connection, $owner);
        } catch (ProviderException $e) {
            $pick['error'] = $e->userMessage();
        }

        return $pick;
    }

    /** The type whose form the new-calendar page shows: the form that failed, else ?type=, else a FreeScout calendar. */
    private static function newType(Request $request): string
    {
        $posted = self::postedProvider();
        if ($posted !== null) {
            return $posted === Calendar::PROVIDER_ICS ? 'ics' : 'local';
        }
        $type = $request->query('type');

        return is_string($type) && array_key_exists($type, self::newTypes()) ? $type : 'local';
    }

    private static function migration(): array
    {
        $service = app(MigrationService::class);
        $entries = array_map(function (array $entry) {
            $level = (string) ($entry['level'] ?? 'info');
            $context = (array) ($entry['context'] ?? []);

            return [
                'level' => $level,
                'text'  => __((string) ($entry['message'] ?? ''), array_map('strval', array_filter($context, 'is_scalar'))),
                // Warnings and errors carry the item's data, so an admin can recreate a skipped event by hand.
                'data'  => $level === 'info' ? [] : array_map(function ($value) {
                    return is_scalar($value) ? (string) $value : (string) json_encode($value);
                }, $context),
            ];
        }, $service->report());
        $finished = $service->isFinished();

        return [
            'finished' => $finished,
            'entries'  => $entries,
            'problems' => count(array_filter($entries, function (array $entry) {
                return $entry['level'] !== 'info';
            })),
            'pending'  => $finished ? [] : $service->pendingItems(),
        ];
    }

    private static function subjects(): array
    {
        $teams = CalendarPolicy::teamsActive()
            ? User::where('email', 'like', 'fsteam%')->orderBy('first_name')->get()->filter(function (User $team) {
                return \Team::isTeam($team);
            })
            : collect();

        return [
            CalendarPermission::SUBJECT_USER    => ['title' => __('Users'), 'items' => User::where('type', User::TYPE_USER)
                ->where('status', '!=', User::STATUS_DELETED)->orderBy('first_name')->get()
                ->map(function (User $user) {
                    return ['id' => (int) $user->id, 'name' => $user->getFullName()];
                })->all()],
            CalendarPermission::SUBJECT_TEAM    => ['title' => __('Teams'), 'items' => $teams->map(function (User $team) {
                return ['id' => (int) $team->id, 'name' => (string) $team->first_name];
            })->values()->all()],
            CalendarPermission::SUBJECT_MAILBOX => ['title' => __('Mailboxes'), 'items' => Mailbox::orderBy('name')->get(['id', 'name'])
                ->map(function (Mailbox $mailbox) {
                    return ['id' => (int) $mailbox->id, 'name' => (string) $mailbox->name];
                })->all()],
        ];
    }

    /** The stored rows as "type:id" => rights. */
    private static function rights(Calendar $calendar): array
    {
        $rights = [];
        foreach ($calendar->permissionRows as $row) {
            $rights[$row->subject_type . ':' . $row->subject_id] = $row->only(CalendarPermission::RIGHTS);
        }

        return $rights;
    }
}
