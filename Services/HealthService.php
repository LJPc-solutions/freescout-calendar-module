<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Option;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Http\SettingsPage;

/**
 * Problems an administrator has to solve: an account that must be connected again, the Microsoft app's secret that
 * expires, a calendar that has not synced for a day. calendar:sync checks them at most every CHECK_MINUTES and mails
 * each new problem once through core's system alert (every active administrator plus Settings > Alerts). A solved
 * problem is remembered for FORGET_HOURS, so one that comes and goes is not mailed again; once it stayed away longer,
 * it is forgotten and mailed again when it comes back. The settings page and the calendar page show the problems that
 * are present now to administrators. Core's notification bell only shows conversation notifications.
 */
class HealthService
{
    public const CHECK_MINUTES = 15;
    /**
     * { "checked_at": ISO 8601 UTC, "notified": { problem key: when it was mailed }, "solved": { problem key: since when
     * it is gone } }, all times ISO 8601 UTC. Versions before FORGET_HOURS stored no "solved": every key they list was
     * present at checked_at.
     */
    public const OPTION = 'ljpccalendarmodule.health';
    /** A mailed problem is forgotten once it has been gone for more than this many hours. */
    public const FORGET_HOURS = 24;
    /** The Microsoft secret is a problem from this many days before it expires. */
    public const SECRET_DAYS = 30;
    /** A calendar fails once its last success (or its creation) is this long ago and its last sync failed. */
    public const FAILING_HOURS = 24;

    private const PROVIDERS = [
        Calendar::PROVIDER_CALDAV    => 'CalDAV',
        Calendar::PROVIDER_GOOGLE    => 'Google',
        Calendar::PROVIDER_MICROSOFT => 'Microsoft 365',
    ];

    private MigrationService $migration;

    private ConnectionService $connections;

    public function __construct(MigrationService $migration, ConnectionService $connections)
    {
        $this->migration = $migration;
        $this->connections = $connections;
    }

    /** check(), unless the last check is less than CHECK_MINUTES ago. Returns whether it checked. */
    public function checkIfDue(): bool
    {
        $checked = $this->stored()['checked_at'] ?? null;
        if (is_string($checked) && strtotime($checked) !== false && strtotime($checked) > time() - self::CHECK_MINUTES * 60) {
            return false;
        }
        $this->check();

        return true;
    }

    /** Evaluates every problem, mails new ones once, forgets those that have been gone for more than FORGET_HOURS. */
    public function check(): void
    {
        $time = time();
        $now = gmdate('Y-m-d\TH:i:s\Z', $time);
        $read = self::storedValue();
        $stored = $read === null ? [] : Option::maybeUnserialize($read);
        $stored = is_array($stored) ? $stored : [];
        $notified = is_array($stored['notified'] ?? null) ? $stored['notified'] : [];
        $solved = is_array($stored['solved'] ?? null) ? array_intersect_key($stored['solved'], $notified) : [];
        foreach ($solved as $key => $since) {
            $since = is_string($since) ? strtotime($since) : false;
            if ($since === false || $since < $time - self::FORGET_HOURS * 3600) {
                unset($notified[$key], $solved[$key]);
            }
        }

        $new = [];
        $present = [];
        foreach ($this->evaluate() as $problem) {
            if (!isset($notified[$problem['key']])) {
                $notified[$problem['key']] = $now;
                $new[] = $problem;
            }
            unset($solved[$problem['key']]);
            $present[$problem['key']] = true;
        }
        foreach (array_keys($notified) as $key) {
            if (!isset($present[$key]) && !isset($solved[$key])) {
                $solved[$key] = $now;
            }
        }
        // Remembered before the mails go out, so no problem is mailed twice, not even when queueing a mail fails, and
        // only by the check that claims the option: of two that ran at the same moment, the other one mails nothing.
        if (!self::claim($read, Option::maybeSerialize(['checked_at' => $now, 'notified' => $notified, 'solved' => $solved]))) {
            return;
        }
        foreach ($new as $problem) {
            \MailHelper::sendAlertMail(e($problem['text']) . '<br><br><a href="' . e($problem['url']) . '">' . e($problem['url']) . '</a>', $problem['title']);
        }
    }

    /** Current problems for the admin banner: [['key' => string, 'text' => string, 'url' => string]]. */
    public function problems(): array
    {
        return array_map(function (array $problem) {
            return ['key' => $problem['key'], 'text' => $problem['text'], 'url' => $problem['url']];
        }, $this->evaluate());
    }

    /** Every problem, with its mail title. Nothing syncs before the upgrade from v2 has finished, so nothing counts. */
    private function evaluate(): array
    {
        if (!$this->migration->isFinished()) {
            return [];
        }

        return array_merge($this->connectionProblems(), $this->secretProblems(), $this->calendarProblems());
    }

    /** Accounts whose calendars don't sync until an administrator connects them again (the sync skips them). */
    private function connectionProblems(): array
    {
        $problems = [];
        foreach (CalendarConnection::where('status', '!=', CalendarConnection::STATUS_OK)->orderBy('id')->get() as $connection) {
            $account = trim((string) $connection->account) ?: (SettingsPage::host($connection->server_url) ?: '#' . $connection->id);
            $problems[] = [
                'key'   => 'connection:' . $connection->id . ':reauth',
                'title' => __('Calendar: an account needs reauthorization'),
                'text'  => __('The :provider account ":account" needs to be connected again. Its calendars don\'t sync until then.', [
                    'provider' => self::PROVIDERS[$connection->provider] ?? $connection->provider,
                    'account'  => $account,
                ]),
                'url'   => SettingsPage::accountUrl($connection),
            ];
        }

        return $problems;
    }

    /** The Microsoft app's client secret, from SECRET_DAYS before the expiry date the administrator saved with it. */
    private function secretProblems(): array
    {
        $app = $this->connections->appCredentials('microsoft');
        $expires = $app['secret_expires_at'];
        if (!$app['has_secret'] || $expires === null) {
            return [];
        }
        // Dates without a time: today as the install sees it, like the date field the administrator filled in.
        $zone = new DateTimeZone(config('app.timezone') ?: 'UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires, $zone);
        if ($date === false) {
            return [];
        }
        $today = new DateTimeImmutable('today', $zone);
        if ($date > $today->modify('+' . self::SECRET_DAYS . ' days')) {
            return [];
        }
        $shown = User::dateFormat($expires, 'M j, Y', null, true, false);
        $expired = $date < $today;

        return [[
            'key'   => 'microsoft:secret',
            'title' => $expired ? __('Calendar: the Microsoft secret has expired') : __('Calendar: the Microsoft secret expires soon'),
            'text'  => $expired
                ? __('The client secret of the Microsoft app expired on :date. Microsoft calendars don\'t sync until a new secret is saved.', ['date' => $shown])
                : __('The client secret of the Microsoft app expires on :date. Create a new secret in Microsoft Entra and save it here with its expiry date.', ['date' => $shown]),
            'url'   => SettingsPage::connectionsUrl() . '#ljpc-app-microsoft',
        ]];
    }

    /**
     * Enabled external calendars whose last sync failed and whose last success (or creation, when they never synced) is
     * more than FAILING_HOURS ago. A calendar of an account that must be connected again is that account's problem.
     */
    private function calendarProblems(): array
    {
        $before = gmdate('Y-m-d H:i:s', time() - self::FAILING_HOURS * 3600);
        $calendars = Calendar::with('calendarConnection')
            ->where('enabled', true)
            ->where('provider', '!=', Calendar::PROVIDER_LOCAL)
            ->whereNotNull('last_error')
            ->orderBy('id')
            ->get();
        $problems = [];
        foreach ($calendars as $calendar) {
            // last_synced_at is stored in UTC; created_at in the install's zone, like every Eloquent timestamp.
            $success = $calendar->last_synced_at ?: ($calendar->created_at ? $calendar->created_at->copy()->setTimezone('UTC')->format('Y-m-d H:i:s') : '');
            if (trim((string) $calendar->last_error) === '' || $success === '' || $success >= $before) {
                continue;
            }
            if ($calendar->usesConnection() && $calendar->calendarConnection && $calendar->calendarConnection->status !== CalendarConnection::STATUS_OK) {
                continue;
            }
            $problems[] = [
                'key'   => 'calendar:' . $calendar->id . ':failing',
                'title' => __('Calendar: a calendar stopped syncing'),
                'text'  => __('The calendar ":name" has not synced for more than 24 hours, so its events may be out of date.', ['name' => $calendar->name]),
                'url'   => SettingsPage::url($calendar->id),
            ];
        }

        return $problems;
    }

    /**
     * Stores a check's result, unless another check stored one since this check read $read, so two calendar:sync runs
     * that check at the same moment never mail a problem twice (Laravel 5.5 has no Cache::lock). It is a compare-and-swap
     * in one statement: an UPDATE locks the row and tests its WHERE against the latest committed value (InnoDB, also under
     * REPEATABLE READ, and PostgreSQL), so of two runs that read the same value only the first one's update matches; for
     * the first check ever, the unique name lets only one insert through. The run that loses mails nothing, and what it
     * found that the other one missed is found by the next check. The tests can only interleave two checks in one
     * process; that the two statements can't both match is the database's guarantee.
     * A value equal to the stored one counts as not stored, which loses nothing: it holds no new problem. Two values
     * never differ only in letter case, which a case-insensitive collation would not see.
     */
    private static function claim(?string $read, string $value): bool
    {
        if ($read !== null) {
            return DB::table('options')->where('name', self::OPTION)->where('value', $read)->update(['value' => $value]) > 0;
        }
        try {
            // A transaction of its own, so a refused insert can't abort a caller's transaction on PostgreSQL.
            DB::transaction(function () use ($value) {
                DB::table('options')->insert(['name' => self::OPTION, 'value' => $value]);
            });
        } catch (QueryException $e) {
            if (self::storedValue() === null) {
                throw $e; // not a check that created the row first
            }

            return false;
        }

        return true;
    }

    /** The option as stored, or null when there is none. */
    private static function storedValue(): ?string
    {
        $value = DB::table('options')->where('name', self::OPTION)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function stored(): array
    {
        $stored = Option::get(self::OPTION, [], true, false);

        return is_array($stored) ? $stored : [];
    }
}
