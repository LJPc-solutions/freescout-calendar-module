<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\Jobs\SendAlert;
use App\Option;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\ConnectionService;
use Modules\LJPcCalendarModule\Services\HealthService;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** Connection health: problems that need an administrator, mailed once each through core's system alert. */
class HealthServiceTest extends TestCase
{
    private HealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->health = app(HealthService::class);
    }

    /** The alert mails dispatched so far, as [title, text]. */
    private function mails(): array
    {
        return Bus::dispatched(SendAlert::class)->map(function (SendAlert $job) {
            return [$job->title, $job->text];
        })->values()->all();
    }

    private function connection(array $attributes = []): CalendarConnection
    {
        return CalendarConnection::create($attributes + ['provider' => Calendar::PROVIDER_GOOGLE, 'account' => 'team@example.com']);
    }

    private function hoursAgo(int $hours): string
    {
        return gmdate('Y-m-d H:i:s', time() - $hours * 3600);
    }

    private function failingFeed(array $attributes = []): Calendar
    {
        return Calendar::create($attributes + [
            'name'           => 'Holidays',
            'provider'       => Calendar::PROVIDER_ICS,
            'remote_id'      => 'https://calendar.example.com/holidays.ics',
            'last_error'     => 'The feed answered with an error. (HTTP 500)',
            'last_synced_at' => $this->hoursAgo(25),
        ]);
    }

    /** A date a number of days from today, in the install's time zone, as the Microsoft app form stores it. */
    private static function day(int $days): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone(config('app.timezone') ?: 'UTC')))->modify(($days < 0 ? '' : '+') . $days . ' days')->format('Y-m-d');
    }

    private function microsoftApp(?string $expires, string $secret = 'test-secret'): void
    {
        app(ConnectionService::class)->saveAppCredentials('microsoft', [
            'tenant' => 'contoso.example.com', 'client_id' => 'test-client', 'client_secret' => $secret, 'secret_expires_at' => $expires,
        ]);
    }

    private function keys(): array
    {
        return array_column($this->health->problems(), 'key');
    }

    /**
     * Lets this many hours pass for the health check: every time it stored moves back by them. (Carbon's test clock
     * can't be used: Carbon 1 breaks Eloquent's timestamps with it on PHP 8.3.)
     */
    private function travel(int $hours): void
    {
        $shift = function ($value) use (&$shift, $hours) {
            if (is_array($value)) {
                return array_map($shift, $value);
            }

            return is_string($value) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $value) ? gmdate('Y-m-d\TH:i:s\Z', strtotime($value) - $hours * 3600) : $value;
        };
        Option::set(HealthService::OPTION, $shift(Option::get(HealthService::OPTION, [], true, false)));
    }

    public function test_each_problem_is_mailed_once_with_the_name_and_the_settings_url(): void
    {
        $connection = $this->connection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $feed = $this->failingFeed();
        $this->microsoftApp(self::day(10));

        $this->health->check();

        $date = \App\User::dateFormat(self::day(10), 'M j, Y', null, true, false);
        $this->assertSame([
            [
                'Calendar: an account needs reauthorization',
                'The Google account &quot;team@example.com&quot; needs to be connected again. Its calendars don&#039;t sync until then.<br><br><a href="' . SettingsPage::accountUrl($connection) . '">' . SettingsPage::accountUrl($connection) . '</a>',
            ],
            [
                'Calendar: the Microsoft secret expires soon',
                'The client secret of the Microsoft app expires on ' . $date . '. Create a new secret in Microsoft Entra and save it here with its expiry date.<br><br><a href="' . SettingsPage::connectionsUrl() . '#ljpc-app-microsoft">' . SettingsPage::connectionsUrl() . '#ljpc-app-microsoft</a>',
            ],
            [
                'Calendar: a calendar stopped syncing',
                'The calendar &quot;Holidays&quot; has not synced for more than 24 hours, so its events may be out of date.<br><br><a href="' . e(SettingsPage::url($feed->id)) . '">' . e(SettingsPage::url($feed->id)) . '</a>',
            ],
        ], $this->mails());
        $this->assertSame([
            ['key' => 'connection:' . $connection->id . ':reauth', 'text' => 'The Google account "team@example.com" needs to be connected again. Its calendars don\'t sync until then.', 'url' => SettingsPage::accountUrl($connection)],
            ['key' => 'microsoft:secret', 'text' => 'The client secret of the Microsoft app expires on ' . $date . '. Create a new secret in Microsoft Entra and save it here with its expiry date.', 'url' => SettingsPage::connectionsUrl() . '#ljpc-app-microsoft'],
            ['key' => 'calendar:' . $feed->id . ':failing', 'text' => 'The calendar "Holidays" has not synced for more than 24 hours, so its events may be out of date.', 'url' => SettingsPage::url($feed->id)],
        ], $this->health->problems());
        $stored = Option::get(HealthService::OPTION, [], true, false);
        $this->assertSame(['connection:' . $connection->id . ':reauth', 'microsoft:secret', 'calendar:' . $feed->id . ':failing'], array_keys($stored['notified']));
        $this->assertNotNull($stored['checked_at']);
    }

    public function test_a_problem_that_persists_is_not_mailed_again(): void
    {
        $this->failingFeed();

        $this->health->check();
        $this->health->check();

        $this->assertCount(1, $this->mails());
    }

    public function test_a_new_problem_is_mailed_while_an_older_one_persists(): void
    {
        $this->failingFeed(['name' => 'First']);
        $this->health->check();

        $this->failingFeed(['name' => 'Second']);
        $this->health->check();

        $this->assertCount(2, $this->mails());
        $this->assertStringContainsString('&quot;First&quot;', $this->mails()[0][1]);
        $this->assertStringContainsString('&quot;Second&quot;', $this->mails()[1][1]);
    }

    public function test_a_problem_that_comes_back_within_a_day_is_not_mailed_again(): void
    {
        $connection = $this->connection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->health->check();

        $connection->update(['status' => CalendarConnection::STATUS_OK]);
        $this->health->check();
        $this->assertSame([], $this->keys());

        $this->travel(1);
        $connection->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->health->check();
        $this->assertSame(['connection:' . $connection->id . ':reauth'], $this->keys());

        // Back for good: the day it was gone no longer counts.
        $this->travel(24);
        $this->health->check();

        $this->assertCount(1, $this->mails());
    }

    public function test_a_problem_that_stayed_away_for_more_than_a_day_is_forgotten_and_mailed_again(): void
    {
        $connection = $this->connection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->health->check();
        $connection->update(['status' => CalendarConnection::STATUS_OK]);
        $this->health->check();

        $this->travel(23);
        $connection->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->health->check();
        $this->assertCount(1, $this->mails());

        // The day starts again when it is solved again.
        $connection->update(['status' => CalendarConnection::STATUS_OK]);
        $this->health->check();
        $this->travel(25);
        $connection->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->health->check();

        $this->assertSame(['Calendar: an account needs reauthorization', 'Calendar: an account needs reauthorization'], array_column($this->mails(), 0));
    }

    public function test_a_check_more_than_a_day_after_the_solution_forgets_the_problem(): void
    {
        $connection = $this->connection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->health->check();
        $connection->update(['status' => CalendarConnection::STATUS_OK]);
        $this->health->check();

        $this->travel(25);
        $this->health->check();

        $stored = Option::get(HealthService::OPTION, [], true, false);
        $this->assertSame([[], []], [$stored['notified'], $stored['solved']]);
    }

    public function test_what_an_earlier_version_stored_keeps_working(): void
    {
        // Before the 24-hour rule the option held only checked_at and the notified keys, all present at checked_at.
        $present = $this->connection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $solved = $this->connection(['account' => 'solved@example.com', 'status' => CalendarConnection::STATUS_OK]);
        $earlier = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        Option::set(HealthService::OPTION, ['checked_at' => $earlier, 'notified' => [
            'connection:' . $present->id . ':reauth' => $earlier,
            'connection:' . $solved->id . ':reauth'  => $earlier,
        ]]);

        $this->health->check();
        $this->travel(1);
        $solved->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->health->check();

        $this->assertSame([], $this->mails(), 'both count as mailed; the solved one came back within a day');
    }

    public function test_a_connection_that_lost_its_login_counts_like_one_that_needs_reauthorization(): void
    {
        // The upgrade from v2 creates a CalDAV connection without a username or password with the status "error".
        $connection = $this->connection(['provider' => Calendar::PROVIDER_CALDAV, 'account' => null, 'server_url' => 'https://dav.example.com/calendars/', 'status' => CalendarConnection::STATUS_ERROR]);
        $this->connection(['account' => 'fine@example.com']);

        $this->assertSame([['key' => 'connection:' . $connection->id . ':reauth', 'text' => 'The CalDAV account "dav.example.com" needs to be connected again. Its calendars don\'t sync until then.', 'url' => SettingsPage::accountUrl($connection)]], $this->health->problems());
    }

    public function test_a_calendar_fails_after_24_hours_without_a_successful_sync(): void
    {
        $failing = $this->failingFeed(['name' => 'Long ago']);
        $this->failingFeed(['name' => 'Recent', 'last_synced_at' => $this->hoursAgo(23)]);
        // created_at is in the install's zone (Eloquent), last_synced_at in UTC.
        $never = $this->failingFeed(['name' => 'Never synced', 'last_synced_at' => null, 'created_at' => Carbon::now()->subHours(25)]);
        $this->failingFeed(['name' => 'New', 'last_synced_at' => null, 'created_at' => Carbon::now()->subHours(23)]);
        $this->failingFeed(['name' => 'Off', 'enabled' => false]);
        $this->failingFeed(['name' => 'Fine', 'last_error' => null]);
        $this->failingFeed(['name' => 'Blank error', 'last_error' => ' ']);
        $this->failingFeed(['name' => 'Local', 'provider' => Calendar::PROVIDER_LOCAL, 'remote_id' => null]);
        // Its account needs reauthorization: that problem covers the calendar, which doesn't sync until then.
        $broken = $this->connection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->failingFeed(['name' => 'Account', 'provider' => Calendar::PROVIDER_GOOGLE, 'connection_id' => $broken->id, 'remote_id' => 'team@example.com']);

        $this->assertSame(['connection:' . $broken->id . ':reauth', 'calendar:' . $failing->id . ':failing', 'calendar:' . $never->id . ':failing'], $this->keys());
    }

    public function test_the_microsoft_secret_is_a_problem_from_30_days_before_it_expires(): void
    {
        $this->microsoftApp(self::day(31));
        $this->assertSame([], $this->keys());

        $this->microsoftApp(self::day(30));
        $this->assertSame(['microsoft:secret'], $this->keys());

        $this->microsoftApp(self::day(-1));
        $date = \App\User::dateFormat(self::day(-1), 'M j, Y', null, true, false);
        $this->assertSame('The client secret of the Microsoft app expired on ' . $date . '. Microsoft calendars don\'t sync until a new secret is saved.', $this->health->problems()[0]['text']);
        $this->health->check();
        $this->assertSame('Calendar: the Microsoft secret has expired', $this->mails()[0][0]);

        $this->microsoftApp(null);
        $this->assertSame([], $this->keys());
    }

    public function test_a_secret_that_expires_while_it_is_a_problem_is_not_mailed_again(): void
    {
        $this->microsoftApp(self::day(10));
        $this->health->check();
        $this->microsoftApp(self::day(-1));
        $this->health->check();

        // One key for both: the expiry was mailed already, and the banner shows the new text.
        $this->assertSame(['Calendar: the Microsoft secret expires soon'], array_column($this->mails(), 0));
        $this->assertStringStartsWith('The client secret of the Microsoft app expired on ', $this->health->problems()[0]['text']);
    }

    public function test_an_expiry_date_without_a_saved_secret_is_no_problem(): void
    {
        $this->microsoftApp(self::day(3), '');

        $this->assertSame([], $this->keys());
    }

    public function test_names_are_escaped_in_the_mail(): void
    {
        $this->failingFeed(['name' => '<b>Team</b> & co']);

        $this->health->check();

        $this->assertStringContainsString('The calendar &quot;&lt;b&gt;Team&lt;/b&gt; &amp; co&quot; has not synced', $this->mails()[0][1]);
        $this->assertSame('The calendar "<b>Team</b> & co" has not synced for more than 24 hours, so its events may be out of date.', $this->health->problems()[0]['text']);
    }

    public function test_nothing_counts_before_the_upgrade_from_version_2_has_finished(): void
    {
        // The upgrade creates connections and calendars step by step; none of them syncs before it has finished.
        Option::set(MigrationService::STATE_OPTION, 'pending');
        $this->connection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->failingFeed();

        $this->assertSame([], $this->health->problems());
    }

    /**
     * Runs a second check in the middle of the next one: after it read the option, while it evaluates the problems
     * (its first query of the connections), as a second calendar:sync run checking at the same moment would.
     */
    private function checkMeanwhile(?callable $before = null): void
    {
        $started = false;
        DB::listen(function ($query) use (&$started, $before) {
            if (!$started && strpos($query->sql, 'calendar_connections') !== false) {
                $started = true;
                if ($before) {
                    $before();
                }
                app(HealthService::class)->check();
            }
        });
    }

    public function test_two_checks_at_the_same_moment_mail_a_new_problem_once(): void
    {
        $this->connection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        $this->assertNull(Option::where('name', HealthService::OPTION)->first(), 'the first check ever creates the option');
        $this->checkMeanwhile();

        $this->health->check();

        $this->assertCount(1, $this->mails());
    }

    public function test_two_later_checks_at_the_same_moment_mail_a_new_problem_once(): void
    {
        $this->health->check();
        $feed = $this->failingFeed();
        // The second check also finds an account problem that came up after the first one had read the connections.
        $connection = null;
        $this->checkMeanwhile(function () use (&$connection) {
            $connection = $this->connection(['status' => CalendarConnection::STATUS_NEEDS_REAUTH]);
        });

        $this->health->check();

        $this->assertSame(['Calendar: an account needs reauthorization', 'Calendar: a calendar stopped syncing'], array_column($this->mails(), 0));
        $this->assertSame(['connection:' . $connection->id . ':reauth', 'calendar:' . $feed->id . ':failing'], array_keys(Option::get(HealthService::OPTION, [], true, false)['notified']), 'what the second check stored stays');
    }

    public function test_the_check_runs_at_most_every_15_minutes(): void
    {
        $this->assertTrue($this->health->checkIfDue());
        $this->failingFeed();

        $this->assertFalse($this->health->checkIfDue());
        $this->assertSame([], $this->mails());

        $stored = Option::get(HealthService::OPTION, [], true, false);
        Option::set(HealthService::OPTION, ['checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 14 * 60)] + $stored);
        $this->assertFalse($this->health->checkIfDue());

        Option::set(HealthService::OPTION, ['checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - HealthService::CHECK_MINUTES * 60)] + $stored);
        $this->assertTrue($this->health->checkIfDue());
        $this->assertCount(1, $this->mails());
    }
}
