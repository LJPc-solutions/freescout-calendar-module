<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class MigrationCalendarsTest extends TestCase
{
    private function v2Calendar(array $attributes): int
    {
        return DB::table('calendars')->insertGetId($attributes + [
            'name'       => 'V2 ' . uniqid(),
            'enabled'    => 1,
            'color'      => '#3498db',
            'type'       => 'normal',
            'created_at' => '2024-05-01 10:00:00',
            'updated_at' => '2024-05-01 10:00:00',
        ]);
    }

    private function messages(): array
    {
        return array_column(app(MigrationService::class)->report(), 'message');
    }

    public function test_converts_local_ics_and_caldav_calendars(): void
    {
        $local = $this->v2Calendar([
            'custom_fields'  => json_encode(['fields' => [['id' => 172604180330, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => 'Call, Visit,']], 'display_priority' => 1]),
            'title_template' => '{{title}} - {{Kind}} {{Unknown}}',
            'color'          => 'red',
        ]);
        $ics = $this->v2Calendar(['type' => 'ics', 'custom_fields' => json_encode(['url' => 'https://example.com/feed.ics', 'refresh' => '1 hour', 'last_update' => 1791200539])]);
        $caldav = $this->v2Calendar(['type' => 'caldav', 'custom_fields' => json_encode(['fields' => [], 'url' => 'https://dav.example.com:8443/remote.php/dav/calendars/lars/work/', 'username' => 'lars', 'password' => 's3cret', 'refresh' => '5 minutes'])]);
        $empty = $this->v2Calendar(['custom_fields' => null]);

        app(MigrationService::class)->convertCalendars();

        $localCalendar = Calendar::find($local);
        $this->assertSame('local', $localCalendar->provider);
        $this->assertSame(1, $localCalendar->display_priority);
        $this->assertSame([['id' => 172604180330, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']]], $localCalendar->fields);
        $this->assertSame('{{title}} - {{field:172604180330}} {{Unknown}}', $localCalendar->title_template);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $localCalendar->color);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $localCalendar->export_token);

        $icsCalendar = Calendar::find($ics);
        $this->assertSame(['ics', 'https://example.com/feed.ics', 60, 200], [$icsCalendar->provider, $icsCalendar->remote_id, $icsCalendar->sync_interval, $icsCalendar->display_priority]);

        $davCalendar = Calendar::find($caldav);
        $connection = CalendarConnection::find($davCalendar->connection_id);
        $this->assertSame(['caldav', 'lars', 'https://dav.example.com:8443'], [$connection->provider, $connection->account, $connection->server_url]);
        $this->assertSame(['username' => 'lars', 'password' => 's3cret'], $connection->credentials());
        $this->assertStringNotContainsString('s3cret', DB::table('calendar_connections')->where('id', $connection->id)->value('credentials'));

        $this->assertSame([], Calendar::find($empty)->fieldDefinitions());
        $this->assertContains('The title template of ":calendar" uses ":placeholder", which matches no field; it was left as text.', $this->messages());

        // Idempotent: a second run changes nothing and creates no second connection.
        $token = $localCalendar->export_token;
        app(MigrationService::class)->convertCalendars();
        $this->assertSame($token, Calendar::find($local)->export_token);
        $this->assertSame(1, CalendarConnection::where('account', 'lars')->count());
    }

    public function test_converts_permissions_including_teams_and_drops_deleted_users(): void
    {
        $user = $this->makeUser();
        $deleted = $this->makeUser(['status' => \App\User::STATUS_DELETED]);
        $team = $this->makeTeam([$user->id]);
        $calendar = $this->v2Calendar(['permissions' => json_encode([
            (string) $user->id    => ['showInDashboard' => true, 'showInCalendar' => true, 'createItems' => false, 'editItems' => true],
            (string) $deleted->id => ['showInDashboard' => true, 'showInCalendar' => true, 'createItems' => true, 'editItems' => true],
            (string) $team->id    => ['showInDashboard' => false, 'showInCalendar' => true, 'createItems' => true, 'editItems' => true],
            '99999999'            => ['showInDashboard' => true, 'showInCalendar' => true, 'createItems' => true, 'editItems' => true],
            (string) ($user->id + 1000000) => ['showInDashboard' => false, 'showInCalendar' => false, 'createItems' => false, 'editItems' => false],
        ])]);

        app(MigrationService::class)->convertPermissions();
        app(MigrationService::class)->convertPermissions();

        $rows = CalendarPermission::where('calendar_id', $calendar)->orderBy('subject_type')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['team', $team->id, true, true], [$rows[0]->subject_type, $rows[0]->subject_id, $rows[0]->can_create, $rows[0]->can_view]);
        $this->assertSame(['user', $user->id, true, false, true], [$rows[1]->subject_type, $rows[1]->subject_id, $rows[1]->show_in_dashboard, $rows[1]->can_create, $rows[1]->can_edit]);
        $this->assertContains('A permission on ":calendar" for a deleted user or team (#:id) was dropped.', $this->messages());
        $this->assertContains('Team ":team" now gives its members access to ":calendar". Team permissions had no effect in version 2, so check this row.', $this->messages());
    }

    private function entries(string $message): array
    {
        return array_values(array_filter(app(MigrationService::class)->report(), fn ($entry) => $entry['message'] === $message));
    }

    private function convertOne(array $attributes): int
    {
        $id = $this->v2Calendar($attributes);
        app(MigrationService::class)->convertCalendars();

        return $id;
    }

    public function test_scrubs_legacy_caldav_secrets_but_keeps_other_settings(): void
    {
        $id = $this->convertOne(['type' => 'caldav', 'custom_fields' => json_encode(['url' => 'https://dav.example.com/cal/', 'username' => 'lars', 'password' => 's3cret', 'refresh' => '5 minutes'])]);

        $raw = DB::table('calendars')->where('id', $id)->value('custom_fields');
        $this->assertStringNotContainsString('s3cret', $raw);
        $this->assertStringNotContainsString('"password"', $raw);
        $this->assertStringNotContainsString('"username"', $raw);
        $this->assertSame('https://dav.example.com/cal/', json_decode($raw, true)['url']);
        $this->assertSame('s3cret', CalendarConnection::find(Calendar::find($id)->connection_id)->credentials()['password']);
    }

    public function test_a_caldav_login_in_the_address_moves_to_the_connection(): void
    {
        $id = $this->convertOne(['name' => 'Login in URL', 'type' => 'caldav', 'custom_fields' => json_encode(['url' => 'https://lars%40example.com:s3cr%3At@dav.example.com:8443/cal/', 'refresh' => '5 minutes'])]);

        $calendar = Calendar::find($id);
        $connection = CalendarConnection::find($calendar->connection_id);
        $this->assertSame('https://dav.example.com:8443/cal/', $calendar->remote_id);
        $this->assertSame(['ok', 'lars@example.com', 'https://dav.example.com:8443'], [$connection->status, $connection->account, $connection->server_url]);
        $this->assertSame(['username' => 'lars@example.com', 'password' => 's3cr:t'], $connection->credentials());
        $this->assertSame('https://dav.example.com:8443/cal/', json_decode(DB::table('calendars')->where('id', $id)->value('custom_fields'), true)['url']);
        $this->assertSame([['calendar' => 'Login in URL']], array_column($this->entries('The address of calendar ":calendar" held its CalDAV login; it was moved to the calendar\'s connection and removed from the address.'), 'context'));
        $this->assertStringNotContainsString('s3cr', DB::table('calendars')->where('id', $id)->value('custom_fields') . json_encode(app(MigrationService::class)->report()));
    }

    public function test_a_caldav_login_set_on_the_calendar_wins_over_the_one_in_the_address(): void
    {
        $id = $this->convertOne(['name' => 'Both', 'type' => 'caldav', 'custom_fields' => json_encode(['url' => 'https://old:stale@dav.example.com/cal/', 'username' => 'lars', 'password' => 's3cret'])]);

        $calendar = Calendar::find($id);
        $this->assertSame('https://dav.example.com/cal/', $calendar->remote_id);
        $this->assertSame(['username' => 'lars', 'password' => 's3cret'], CalendarConnection::find($calendar->connection_id)->credentials());
        $this->assertSame([['calendar' => 'Both']], array_column($this->entries('The address of calendar ":calendar" held a CalDAV login; it was removed from the address, and the username and password set on the calendar are used.'), 'context'));
        $this->assertStringNotContainsString('stale', DB::table('calendars')->where('id', $id)->value('custom_fields') . json_encode(app(MigrationService::class)->report()));
    }

    public function test_a_feed_address_loses_its_login_with_a_warning_and_an_at_sign_in_a_path_stays(): void
    {
        $login = $this->v2Calendar(['name' => 'Private feed', 'type' => 'ics', 'custom_fields' => json_encode(['url' => 'https://feed-user:feed-pass@calendar.example.com/private/basic.ics', 'refresh' => '1 hour'])]);
        $path = $this->v2Calendar(['name' => 'Public feed', 'type' => 'ics', 'custom_fields' => json_encode(['url' => 'https://calendar.example.com/feeds/team@example.com/basic.ics'])]);

        app(MigrationService::class)->convertCalendars();

        $this->assertSame('https://calendar.example.com/private/basic.ics', Calendar::find($login)->remote_id);
        $this->assertSame('https://calendar.example.com/feeds/team@example.com/basic.ics', Calendar::find($path)->remote_id);
        $this->assertSame([['calendar' => 'Private feed']], array_column($this->entries('The address of feed ":calendar" held a username and password. FreeScout keeps no passwords for feeds, so they were removed; if the feed needs them, it can\'t sync until it gets an address that works without them.'), 'context'));
        $this->assertStringNotContainsString('feed-pass', DB::table('calendars')->where('id', $login)->value('custom_fields') . json_encode(app(MigrationService::class)->report()));
        $this->assertSame(0, CalendarConnection::where('account', 'feed-user')->count());
    }

    public function test_reports_unknown_field_type_coerced_to_text(): void
    {
        $id = $this->convertOne(['name' => 'Typed', 'custom_fields' => json_encode(['fields' => [['id' => 1, 'name' => 'Odd', 'type' => 'rainbow']]])]);

        $entry = $this->entries('Field ":field" on ":calendar" had an unknown type ":type" and was converted to text.')[0];
        $this->assertSame(['calendar' => 'Typed', 'field' => 'Odd', 'type' => 'rainbow'], $entry['context']);
        $this->assertSame('text', Calendar::find($id)->fields[0]['type']);
    }

    public function test_colors_with_a_trailing_newline_are_replaced(): void
    {
        $long = $this->convertOne(['name' => 'Long newline', 'color' => "#3498db\n"]);
        $short = $this->convertOne(['name' => 'Short newline', 'color' => "#abc\n"]);

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}\z/i', Calendar::find($long)->color);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}\z/i', Calendar::find($short)->color);
        $entries = $this->entries('The color ":color" of ":calendar" is not a hex color and was replaced by one from the palette.');
        $this->assertSame(['Long newline', 'Short newline'], array_column(array_column($entries, 'context'), 'calendar'));
    }

    public function test_reports_fields_dropped_for_missing_id_or_name(): void
    {
        $this->convertOne(['name' => 'Dropped', 'custom_fields' => json_encode(['fields' => [['id' => 1, 'name' => 'Ok'], ['name' => 'No id'], ['id' => 3]]])]);

        $entries = $this->entries('Custom field :position on ":calendar" has no usable id or name and was dropped.');
        $this->assertCount(2, $entries);
        $this->assertSame(['calendar' => 'Dropped', 'position' => 2, 'name' => 'No id'], $entries[0]['context']);
        $this->assertSame(['calendar' => 'Dropped', 'position' => 3], $entries[1]['context']);
    }

    public function test_reports_replaced_colors_but_not_expanded_short_hex(): void
    {
        $short = $this->convertOne(['name' => 'Short', 'color' => '#abc']);
        $null = $this->convertOne(['name' => 'Nothing', 'color' => null]);
        $named = $this->convertOne(['name' => 'Named', 'color' => 'red']);

        $this->assertSame('#aabbcc', Calendar::find($short)->color);
        $entries = $this->entries('The color ":color" of ":calendar" is not a hex color and was replaced by one from the palette.');
        $this->assertSame([['calendar' => 'Nothing', 'color' => ''], ['calendar' => 'Named', 'color' => 'red']], array_column($entries, 'context'));
        $this->assertNotNull(Calendar::find($null)->color);
        $this->assertNotNull(Calendar::find($named)->color);
    }

    public function test_reports_unknown_refresh_and_defaults_missing_refresh_to_daily(): void
    {
        $unknown = $this->convertOne(['name' => 'Odd refresh', 'type' => 'ics', 'custom_fields' => json_encode(['url' => 'https://example.com/a.ics', 'refresh' => 'sometimes'])]);
        $missing = $this->convertOne(['name' => 'No refresh', 'type' => 'ics', 'custom_fields' => json_encode(['url' => 'https://example.com/b.ics'])]);

        $this->assertSame(1440, Calendar::find($unknown)->sync_interval);
        $this->assertSame(1440, Calendar::find($missing)->sync_interval);
        $entries = $this->entries('Calendar ":calendar" had an unknown refresh interval ":refresh" and now syncs daily.');
        $this->assertSame([['calendar' => 'Odd refresh', 'refresh' => 'sometimes']], array_column($entries, 'context'));
    }

    public function test_reports_unreadable_custom_fields(): void
    {
        // The json column refuses text that is not valid JSON, so the unreadable shapes are valid JSON that is not an object.
        $id = $this->convertOne(['name' => 'Broken', 'custom_fields' => '"just text"']);
        $number = $this->convertOne(['name' => 'Number', 'custom_fields' => '42']);

        $this->assertNotNull(Calendar::find($id)->export_token);
        $this->assertNotNull(Calendar::find($number)->export_token);
        $entries = $this->entries('The custom fields and connection settings of ":calendar" could not be read and were left empty.');
        $this->assertSame(['Broken', 'Number'], array_column(array_column($entries, 'context'), 'calendar'));
    }

    public function test_reads_double_encoded_custom_fields_with_a_warning(): void
    {
        $inner = ['fields' => [['id' => 7, 'name' => 'Kind', 'type' => 'text']], 'display_priority' => 3];
        $id = $this->convertOne(['name' => 'Twice', 'custom_fields' => json_encode(json_encode($inner))]);

        $this->assertSame([['id' => 7, 'name' => 'Kind', 'type' => 'text', 'required' => false, 'options' => []]], Calendar::find($id)->fields);
        $this->assertSame(3, Calendar::find($id)->display_priority);
        $this->assertSame([['calendar' => 'Twice']], array_column($this->entries('The custom fields of ":calendar" were stored double-encoded; they were read anyway.'), 'context'));
    }

    public function test_reports_duplicate_field_names_and_binds_the_template_to_the_first(): void
    {
        $id = $this->convertOne(['name' => 'Twins', 'title_template' => '{{Kind}}', 'custom_fields' => json_encode(['fields' => [['id' => 1, 'name' => 'Kind'], ['id' => 2, 'name' => 'Kind']]])]);

        $this->assertSame('{{field:1}}', Calendar::find($id)->title_template);
        $this->assertSame([['calendar' => 'Twins', 'field' => 'Kind']], array_column($this->entries('Calendar ":calendar" has more than one field named ":field"; the title template uses the first one.'), 'context'));
    }

    public function test_caldav_without_credentials_gets_an_error_connection_and_a_warning(): void
    {
        $id = $this->convertOne(['name' => 'No login', 'type' => 'caldav', 'custom_fields' => json_encode(['url' => 'https://dav.example.com/cal/', 'username' => 'lars', 'password' => ''])]);

        $connection = CalendarConnection::find(Calendar::find($id)->connection_id);
        $this->assertSame(['error', 'Missing CalDAV username or password'], [$connection->status, $connection->last_error]);
        $this->assertSame([['calendar' => 'No login']], array_column($this->entries('Calendar ":calendar" has no CalDAV username or password; its connection was created with an error status.'), 'context'));
    }

    public function test_odd_non_scalar_values_do_not_abort_the_migration(): void
    {
        $odd = $this->v2Calendar(['name' => 'Odd', 'type' => 'caldav', 'color' => 'blue', 'custom_fields' => json_encode([
            'fields'   => [
                ['id' => 1, 'name' => 'Pick', 'type' => 'dropdown', 'options' => ['A', ['nested'], 'B']],
                ['id' => 2, 'name' => ['array name']],
                ['id' => 3, 'name' => 'Typed', 'type' => ['x'], 'required' => ['y']],
            ],
            'url'      => ['not', 'a url'],
            'username' => ['u'],
            'password' => ['p'],
            'refresh'  => ['daily'],
        ])]);
        $other = $this->v2Calendar(['name' => 'Fine']);

        app(MigrationService::class)->convertCalendars();

        $this->assertNotNull(Calendar::find($odd)->export_token);
        $this->assertNotNull(Calendar::find($other)->export_token);
        $fields = Calendar::find($odd)->fields;
        $this->assertSame(['A', 'B'], $fields[0]['options']);
        $this->assertSame(['text', false], [$fields[1]['type'], $fields[1]['required']]);
        $this->assertCount(2, $fields);
        $this->assertCount(1, $this->entries('An option of field ":field" on ":calendar" is not text and was dropped.'));
        $this->assertCount(1, $this->entries('Custom field :position on ":calendar" has no usable id or name and was dropped.'));
        $this->assertSame('non-scalar array', $this->entries('Field ":field" on ":calendar" had an unknown type ":type" and was converted to text.')[0]['context']['type']);
    }

    public function test_a_failing_calendar_is_rolled_back_reported_and_the_others_still_convert(): void
    {
        // Deterministic failure: after the CalDAV connection row is inserted (inside the calendar's transaction) a model
        // event throws, so the rollback has real work to undo. Only the connection with this account explodes.
        CalendarConnection::created(function (CalendarConnection $connection) {
            if ($connection->account === 'explode') {
                throw new \RuntimeException('Simulated failure');
            }
        });
        try {
            $bad = $this->v2Calendar(['name' => 'Exploding', 'type' => 'caldav', 'custom_fields' => json_encode(['url' => 'https://dav.example.com/', 'username' => 'explode', 'password' => 's3cret'])]);
            $good = $this->v2Calendar(['name' => 'Good']);
            $connections = CalendarConnection::count();

            app(MigrationService::class)->convertCalendars();
        } finally {
            CalendarConnection::flushEventListeners();
        }

        $this->assertNull(DB::table('calendars')->where('id', $bad)->value('export_token'));
        $this->assertNotNull(Calendar::find($good)->export_token);
        $this->assertSame($connections, CalendarConnection::count());
        $this->assertStringContainsString('s3cret', DB::table('calendars')->where('id', $bad)->value('custom_fields'));
        $entry = $this->entries('Calendar ":calendar" could not be converted and was skipped: :error')[0];
        $this->assertSame(['error', 'Exploding', 'Simulated failure'], [$entry['level'], $entry['context']['calendar'], $entry['context']['error']]);
        $this->assertContains('Calendar ":calendar" was converted (:provider).', $this->messages());
        $this->assertNotContains('Calendar ":calendar" has no URL and cannot sync until one is set.', $this->messages());
    }

    public function test_permission_flags_given_as_strings_only_grant_when_true(): void
    {
        $user = $this->makeUser();
        $calendar = $this->v2Calendar(['permissions' => json_encode([
            (string) $user->id => ['showInDashboard' => 'false', 'showInCalendar' => 'true', 'createItems' => '0', 'editItems' => 0],
        ])]);

        app(MigrationService::class)->convertPermissions();

        $row = CalendarPermission::where('calendar_id', $calendar)->first();
        $this->assertSame([false, true, false, false], [$row->show_in_dashboard, $row->can_view, $row->can_create, $row->can_edit]);
    }

    public function test_a_non_numeric_permission_subject_key_is_skipped_with_a_warning(): void
    {
        $user = $this->makeUser();
        $calendar = $this->v2Calendar(['name' => 'Keys', 'permissions' => json_encode([
            'everyone'         => ['showInCalendar' => true],
            (string) $user->id => ['showInCalendar' => true],
        ])]);

        app(MigrationService::class)->convertPermissions();

        $this->assertSame(1, CalendarPermission::where('calendar_id', $calendar)->count());
        $this->assertSame([['calendar' => 'Keys', 'subject' => 'everyone']], array_column($this->entries('A permission on ":calendar" for ":subject", which is not a user or team id, was skipped.'), 'context'));
    }

    public function test_converting_permissions_twice_does_not_duplicate_report_entries(): void
    {
        $deleted = $this->makeUser(['status' => \App\User::STATUS_DELETED]);
        $calendar = $this->v2Calendar(['permissions' => json_encode([(string) $deleted->id => ['showInCalendar' => true]])]);

        app(MigrationService::class)->convertPermissions();
        $after = app(MigrationService::class)->report();
        app(MigrationService::class)->convertPermissions();

        $this->assertCount(1, $after);
        $this->assertSame($after, app(MigrationService::class)->report());
        $this->assertNull(DB::table('calendars')->where('id', $calendar)->value('permissions'));
    }

    public function test_a_failing_permission_conversion_is_isolated_per_calendar(): void
    {
        $user = $this->makeUser();
        $rights = json_encode([(string) $user->id => ['showInCalendar' => true]]);
        $bad = $this->v2Calendar(['name' => 'Clash', 'permissions' => $rights]);
        $good = $this->v2Calendar(['name' => 'Fine', 'permissions' => $rights]);
        // A row that already exists makes the conversion hit the unique key inside the transaction.
        CalendarPermission::create(['calendar_id' => $bad, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true]);

        app(MigrationService::class)->convertPermissions();

        $this->assertNotNull(DB::table('calendars')->where('id', $bad)->value('permissions'));
        $this->assertNull(DB::table('calendars')->where('id', $good)->value('permissions'));
        $this->assertSame(1, CalendarPermission::where('calendar_id', $good)->count());
        $entry = $this->entries('The permissions of calendar ":calendar" could not be converted and were skipped: :error')[0];
        $this->assertSame(['error', 'Clash'], [$entry['level'], $entry['context']['calendar']]);
    }

    public function test_calendar_and_permission_entries_end_up_in_order(): void
    {
        $user = $this->makeUser();
        $this->v2Calendar(['name' => 'Ordered', 'color' => 'red', 'permissions' => json_encode(['x' => ['showInCalendar' => true], (string) $user->id => ['showInCalendar' => true]])]);

        app(MigrationService::class)->convertCalendars();
        app(MigrationService::class)->convertPermissions();

        $this->assertSame([
            'The color ":color" of ":calendar" is not a hex color and was replaced by one from the palette.',
            'Calendar ":calendar" was converted (:provider).',
            'A permission on ":calendar" for ":subject", which is not a user or team id, was skipped.',
        ], $this->messages());
    }

    public function test_unreadable_permissions_are_reported_with_the_raw_value_and_cleared(): void
    {
        $id = $this->v2Calendar(['name' => 'Garbled', 'permissions' => '"just text"']);

        app(MigrationService::class)->convertPermissions();
        app(MigrationService::class)->convertPermissions();

        $entries = $this->entries('The permissions of ":calendar" could not be read and were not converted; the value was cleared after this report.');
        $this->assertSame([['calendar' => 'Garbled', 'raw' => '"just text"']], array_column($entries, 'context'));
        $this->assertNull(DB::table('calendars')->where('id', $id)->value('permissions'));
    }

    public function test_a_non_array_rights_value_is_skipped_with_a_warning(): void
    {
        $this->v2Calendar(['name' => 'Scalar', 'permissions' => json_encode(['12' => true])]);

        app(MigrationService::class)->convertPermissions();

        $entries = $this->entries('The permission on ":calendar" for subject ":subject" is not a set of rights and was skipped.');
        $this->assertSame([['calendar' => 'Scalar', 'subject' => '12']], array_column($entries, 'context'));
    }
}
