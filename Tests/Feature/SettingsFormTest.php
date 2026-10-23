<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

class SettingsFormTest extends TestCase
{
    /** What the edit form posts for a calendar, with one new dropdown field and no permissions. */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name'                     => 'Team agenda ' . uniqid(),
            'color'                    => '#3498db',
            'enabled'                  => '1',
            'display_priority'         => '100',
            'timezone'                 => '',
            'default_reminder_minutes' => '',
            'title_template'           => '',
            'fields'                   => ['new0' => ['id' => '', 'name' => 'Kind', 'type' => 'dropdown', 'options' => "Call\nVisit", 'required' => '1']],
            'form_complete'            => '1',
        ];
    }

    private function local(array $attributes = []): Calendar
    {
        return Calendar::create($attributes + ['name' => 'Cal ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
    }

    private function update(Calendar $calendar): string
    {
        return route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]);
    }

    public function test_agents_cannot_use_the_settings_actions(): void
    {
        $user = $this->makeUser();
        $calendar = $this->local();
        $id = ['id' => $calendar->id];

        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.calendars.store'), ['name' => 'X ' . uniqid(), 'color' => '#000000'])->assertStatus(403);
        $this->actingAs($user)->post($this->update($calendar), $this->form(['name' => 'Hacked']))->assertStatus(403);
        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.calendars.tokens', $id), ['which' => 'full'])->assertStatus(403);
        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.calendars.delete', $id))->assertStatus(403);
        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.migration.skip', ['id' => 1]))->assertStatus(403);

        $this->assertSame($calendar->name, $calendar->fresh()->name);
    }

    public function test_creates_a_local_calendar_and_opens_its_settings(): void
    {
        $suffix = uniqid();

        $response = $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.calendars.store'), ['name' => '<b>Sales</b> ' . $suffix, 'color' => '#e74c3c']);

        $calendar = Calendar::where('name', 'Sales ' . $suffix)->firstOrFail();
        $response->assertRedirect(SettingsPage::url($calendar->id));
        $this->assertSame([Calendar::PROVIDER_LOCAL, '#e74c3c'], [$calendar->provider, $calendar->color]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $calendar->export_token);
    }

    public function test_store_rejects_taken_empty_and_unsafe_values(): void
    {
        $admin = $this->makeAdmin();
        $this->local(['name' => 'Taken X']);
        $before = Calendar::count();

        foreach ([
            ['name', ['name' => '<b>Taken X</b>', 'color' => '#000000']],
            ['name', ['name' => '<i></i>  ', 'color' => '#000000']],
            ['color', ['name' => 'Fine ' . uniqid(), 'color' => 'red; background:url(x)']],
        ] as [$key, $input]) {
            $this->actingAs($admin)->from(SettingsPage::url('new'))->post(route('ljpccalendarmodule.settings.calendars.store'), $input)
                ->assertRedirect(SettingsPage::url('new'))
                ->assertSessionHasErrors([$key]);
        }
        $this->assertSame($before, Calendar::count());
    }

    public function test_update_saves_settings_fields_and_permissions(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeUser();
        $mailbox = $this->makeMailbox();
        $calendar = $this->local(['fields' => [
            ['id' => 172604180330, 'name' => 'Kind', 'type' => 'text', 'required' => false, 'options' => []],
            ['id' => 172604180331, 'name' => 'Old', 'type' => 'text', 'required' => false, 'options' => []],
        ]]);

        $this->actingAs($admin)->from(SettingsPage::url($calendar->id))->post($this->update($calendar), $this->form([
            'enabled'                  => '0',
            'display_priority'         => '5',
            'timezone'                 => 'Europe/Amsterdam',
            'default_reminder_minutes' => '30',
            'title_template'           => '{{title}} ({{field:172604180330}})',
            'fields'                   => [
                'f172604180330' => ['id' => '172604180330', 'name' => 'Type', 'type' => 'dropdown', 'options' => "Call\n<b>Visit</b>\nCall\n"],
                'f172604180331' => ['id' => '172604180331', 'name' => 'Old', 'type' => 'text', 'remove' => '1'],
                'new0'          => ['id' => '', 'name' => 'Paid', 'type' => 'boolean', 'required' => '1'],
                'new1'          => ['id' => '', 'name' => '', 'type' => 'text', 'options' => ''],
            ],
            'permissions'              => [
                'user'    => [$agent->id => ['can_view' => '1', 'can_create' => '1']],
                'mailbox' => [$mailbox->id => ['show_in_dashboard' => '1']],
            ],
        ]))->assertRedirect(SettingsPage::url($calendar->id))->assertSessionMissing('errors');

        $calendar = $calendar->fresh();
        $this->assertSame([false, 5, 'Europe/Amsterdam', 30], [$calendar->enabled, $calendar->display_priority, $calendar->timezone, $calendar->default_reminder_minutes]);
        $this->assertSame('{{title}} ({{field:172604180330}})', $calendar->title_template);
        $this->assertCount(2, $calendar->fields);
        $this->assertSame([172604180330, 'Type', 'dropdown', ['Call', 'Visit']], [$calendar->fields[0]['id'], $calendar->fields[0]['name'], $calendar->fields[0]['type'], $calendar->fields[0]['options']]);
        $this->assertSame(['Paid', 'boolean', true, []], [$calendar->fields[1]['name'], $calendar->fields[1]['type'], $calendar->fields[1]['required'], $calendar->fields[1]['options']]);
        $rows = CalendarPermission::where('calendar_id', $calendar->id)->orderBy('subject_type')->get()->map(function (CalendarPermission $row) {
            return [$row->subject_type, (int) $row->subject_id, (bool) $row->show_in_dashboard, (bool) $row->can_view, (bool) $row->can_create];
        })->all();
        $this->assertSame([['mailbox', $mailbox->id, true, false, false], ['user', $agent->id, false, true, true]], $rows);
    }

    public function test_update_validation_reports_each_problem_and_saves_nothing(): void
    {
        $admin = $this->makeAdmin();
        $deleted = $this->makeUser(['status' => \App\User::STATUS_DELETED]);
        $agent = $this->makeUser();
        $calendar = $this->local(['fields' => [['id' => 172604180330, 'name' => 'Kind', 'type' => 'text', 'required' => false, 'options' => []]]]);

        foreach ([
            'name'                            => ['name' => '<b></b>'],
            'color'                           => ['color' => '#12345g'],
            'timezone'                        => ['timezone' => 'Mars/Olympus'],
            'display_priority'                => ['display_priority' => '-1'],
            'title_template'                  => ['title_template' => '{{field:999999}}'],
            'fields.k.options'                => ['fields' => ['k' => ['id' => '', 'name' => 'Tags', 'type' => 'multiselect', 'options' => '']]],
            'fields.k.name'                   => ['fields' => ['k' => ['id' => '172604180330', 'name' => '<i></i>', 'type' => 'text']]],
            'permissions.user.' . $deleted->id => ['permissions' => ['user' => [$deleted->id => ['can_view' => '1']]]],
            'permissions.team.' . $agent->id  => ['permissions' => ['team' => [$agent->id => ['can_view' => '1']]]],
        ] as $key => $overrides) {
            $this->actingAs($admin)->from(SettingsPage::url($calendar->id))->post($this->update($calendar), $this->form($overrides))
                ->assertRedirect(SettingsPage::url($calendar->id))
                ->assertSessionHasErrors([$key]);
        }

        $fresh = $calendar->fresh();
        $this->assertSame([$calendar->name, 'Kind'], [$fresh->name, $fresh->fields[0]['name']]);
        $this->assertSame(0, CalendarPermission::where('calendar_id', $calendar->id)->count());
    }

    public function test_a_form_cut_off_by_max_input_vars_is_refused(): void
    {
        $calendar = $this->local();
        $form = $this->form(['name' => 'Changed ' . uniqid()]);
        unset($form['form_complete']);

        $this->actingAs($this->makeAdmin())->from(SettingsPage::url($calendar->id))->post($this->update($calendar), $form)->assertSessionHasErrors(['form']);

        $this->assertSame($calendar->name, $calendar->fresh()->name);
    }

    public function test_new_duplicate_small_zero_padded_and_malformed_field_ids_get_unique_ids(): void
    {
        $calendar = $this->local();

        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['fields' => [
            'a' => ['id' => '5', 'name' => 'A', 'type' => 'text'],
            'b' => ['id' => '172604180330', 'name' => 'B', 'type' => 'text'],
            'c' => ['id' => '0172604180330', 'name' => 'C', 'type' => 'text'],
            'd' => ['id' => '1234567890123456789012', 'name' => 'D', 'type' => 'text'],
            'e' => ['id' => 'bad id!', 'name' => 'E', 'type' => 'text'],
        ]]))->assertSessionMissing('errors');

        $ids = array_column($calendar->fresh()->fields, 'id');
        $this->assertCount(5, array_unique($ids));
        $this->assertSame(172604180330, $ids[1]);
        foreach ($ids as $id) {
            $this->assertIsInt($id);
            $this->assertGreaterThanOrEqual(1000, $id);
        }
    }

    public function test_stored_field_ids_that_break_the_id_rule_are_kept(): void
    {
        // Ids an older version or the 2.7.x fork may have stored; a new field still gets an id by the rule.
        $calendar = $this->local([
            'fields'         => [
                ['id' => 'abc.1', 'name' => 'Room', 'type' => 'text', 'required' => false, 'options' => []],
                ['id' => 12, 'name' => 'Seats', 'type' => 'number', 'required' => false, 'options' => []],
            ],
            'title_template' => '{{title}} in {{field:abc.1}}',
        ]);

        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form([
            'title_template' => '{{title}} in {{field:abc.1}} ({{field:12}})',
            'fields'         => [
                'fabc.1' => ['id' => 'abc.1', 'name' => 'Room', 'type' => 'text'],
                'f12'    => ['id' => '12', 'name' => 'Seats', 'type' => 'number'],
                'new0'   => ['id' => '7', 'name' => 'New', 'type' => 'text'],
            ],
        ]))->assertSessionMissing('errors');

        $ids = array_column($calendar->fresh()->fields, 'id');
        $this->assertSame(['abc.1', 12], array_slice($ids, 0, 2));
        $this->assertGreaterThanOrEqual(1000, $ids[2]);
        $this->assertSame('{{title}} in {{field:abc.1}} ({{field:12}})', $calendar->fresh()->title_template);
    }

    public function test_editing_an_external_calendar_keeps_its_provider(): void
    {
        $calendar = $this->local(['provider' => Calendar::PROVIDER_CALDAV]);

        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['provider' => Calendar::PROVIDER_LOCAL]))->assertSessionMissing('errors');

        $this->assertSame(Calendar::PROVIDER_CALDAV, $calendar->fresh()->provider);
    }

    public function test_regenerating_a_token_invalidates_the_old_url(): void
    {
        $calendar = $this->local();
        $old = '/calendar/' . $calendar->id . '/export/' . $calendar->export_token . '.ics';
        $this->get($old)->assertStatus(200);

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.calendars.tokens', ['id' => $calendar->id]), ['which' => 'full'])
            ->assertRedirect(SettingsPage::url($calendar->id));

        $this->get($old)->assertStatus(404);
        $this->get('/calendar/' . $calendar->id . '/export/' . $calendar->fresh()->export_token . '.ics')->assertStatus(200);
    }

    public function test_a_calendar_the_upgrade_has_not_converted_can_only_be_deleted(): void
    {
        $admin = $this->makeAdmin();
        $stuck = $this->local(['name' => 'Stuck ' . uniqid()]);
        // export_token is the conversion's done-marker: setting it would stop the upgrade from retrying this calendar.
        DB::table('calendars')->where('id', $stuck->id)->update(['export_token' => null, 'export_busy_token' => null]);

        foreach (['full', 'busy'] as $which) {
            $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.tokens', ['id' => $stuck->id]), ['which' => $which])->assertSessionHasErrors(['calendar']);
        }
        $this->actingAs($admin)->post($this->update($stuck), $this->form(['name' => 'Renamed ' . uniqid()]))->assertSessionHasErrors(['calendar']);
        $row = DB::table('calendars')->where('id', $stuck->id)->first();
        $this->assertSame([null, null, $stuck->name], [$row->export_token, $row->export_busy_token, $row->name]);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.delete', ['id' => $stuck->id]))->assertRedirect(SettingsPage::url());
        $this->assertNull(Calendar::find($stuck->id));
    }

    public function test_deleting_a_calendar_removes_its_events_and_occurrences(): void
    {
        $admin = $this->makeAdmin();
        $calendar = $this->local();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        app(EventService::class)->create($calendar, $admin, ['title' => 'Gone', 'start' => '2026-10-20T10:00']);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.delete', ['id' => $calendar->id]))->assertRedirect(SettingsPage::url());

        $this->assertSame(0, DB::table('calendar_events')->where('calendar_id', $calendar->id)->count());
        $this->assertSame(0, DB::table('calendar_occurrences')->where('calendar_id', $calendar->id)->count());
    }

    public function test_team_rows_need_a_real_team(): void
    {
        if (!class_exists('Team')) {
            $this->markTestSkipped('The Teams module is not active.');
        }
        $admin = $this->makeAdmin();
        $team = $this->makeTeam([$admin->id]);
        $calendar = $this->local();

        $this->actingAs($admin)->post($this->update($calendar), $this->form(['permissions' => ['user' => [$team->id => ['can_view' => '1']]]]))
            ->assertSessionHasErrors(['permissions.user.' . $team->id]);
        $this->actingAs($admin)->post($this->update($calendar), $this->form(['permissions' => ['team' => [$team->id => ['can_view' => '1']]]]))
            ->assertSessionMissing('errors');

        $this->assertSame([['team', $team->id]], CalendarPermission::where('calendar_id', $calendar->id)->get()->map(function (CalendarPermission $row) {
            return [$row->subject_type, (int) $row->subject_id];
        })->all());
    }

    public function test_an_admin_skips_a_pending_v2_item_from_the_report(): void
    {
        $admin = $this->makeAdmin();
        $calendar = $this->local();
        $id = DB::table('calendar_items')->insertGetId([
            'calendar_id' => $calendar->id, 'author_id' => $admin->id, 'is_all_day' => 0, 'is_private' => 0, 'is_read_only' => 0,
            'title' => 'Stuck item', 'body' => '', 'state' => 'published', 'location' => null,
            'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00', 'created_at' => '2025-01-01 10:00:00', 'updated_at' => '2025-01-01 10:00:00',
        ]);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.migration.skip', ['id' => $id]))
            ->assertRedirect(SettingsPage::url())->assertSessionMissing('errors');
        $this->assertContains($id, app(MigrationService::class)->skippedItemIds());

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.migration.skip', ['id' => $id]))->assertSessionHasErrors(['item']);
    }

    public function test_saving_keeps_team_rows_while_the_teams_module_is_inactive(): void
    {
        if (!class_exists('Team')) {
            $this->markTestSkipped('The Teams module is not installed.');
        }
        $team = $this->makeTeam([$this->makeUser()->id]);
        $calendar = $this->local();
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'team', 'subject_id' => $team->id, 'can_view' => true]);

        \App\Module::setActive('teams', false, false);
        try {
            $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['name' => 'Renamed ' . uniqid(), 'fields' => []]))->assertSessionMissing('errors');
        } finally {
            \App\Module::clearModulesCache();
        }

        $this->assertTrue(CalendarPermission::where('calendar_id', $calendar->id)->where('subject_type', 'team')->where('subject_id', $team->id)->exists());
    }

    /** @return array posted rows for $count text fields, keyed like the edit form does */
    private function fieldRows(int $count, int $firstId = 1000): array
    {
        $rows = [];
        for ($n = 0; $n < $count; $n++) {
            $rows['f' . ($firstId + $n)] = ['id' => (string) ($firstId + $n), 'name' => 'Field ' . $n, 'type' => 'text'];
        }

        return $rows;
    }

    public function test_a_calendar_with_fifty_fields_can_still_be_saved(): void
    {
        $stored = [];
        foreach ($this->fieldRows(50) as $row) {
            $stored[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'type' => 'text', 'required' => false, 'options' => []];
        }
        $calendar = $this->local(['fields' => $stored]);
        $rows = $this->fieldRows(50) + ['new0' => ['id' => '', 'name' => '', 'type' => 'text']];

        // The stored fields plus the empty row make 51 posted rows.
        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['fields' => $rows]))->assertSessionMissing('errors');
        $this->assertCount(50, $calendar->fresh()->fieldDefinitions());

        // A ticked "Remove" row does not count either.
        $rows['f1000']['remove'] = '1';
        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['fields' => $rows]))->assertSessionMissing('errors');
        $this->assertCount(49, $calendar->fresh()->fieldDefinitions());
    }

    public function test_more_than_fifty_kept_fields_are_refused(): void
    {
        $calendar = $this->local();

        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['fields' => $this->fieldRows(51)]))
            ->assertSessionHasErrors(['fields']);
        $this->assertCount(0, $calendar->fresh()->fieldDefinitions());
    }

    private function occurrenceStarts(CalendarEvent $event): array
    {
        return CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->pluck('starts_at')->all();
    }

    public function test_a_new_calendar_zone_moves_its_floating_events(): void
    {
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $calendar = $this->local(['timezone' => 'Europe/Amsterdam']);
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $events = app(EventService::class);
        $floating = $events->create($calendar, $user, ['title' => 'Floating', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00', 'recurrence' => ['freq' => 'DAILY', 'count' => 2]]);
        // Another app wrote it without a zone: 10:00 wherever the calendar is.
        $floating->ics = str_replace(['DTSTART;TZID=Europe/Amsterdam:', 'DTEND;TZID=Europe/Amsterdam:'], ['DTSTART:', 'DTEND:'], $floating->ics);
        $floating->fill(EventIcs::derive(Reader::read($floating->ics), $floating->uid, 'Europe/Amsterdam'));
        $floating->save();
        app(OccurrenceService::class)->refresh($floating);
        $zoned = $events->create($calendar, $user, ['title' => 'Zoned', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00']);
        $allDay = $events->create($calendar, $user, ['title' => 'Away', 'all_day' => true, 'start' => '2026-10-20', 'end' => '2026-10-21']);
        $this->assertSame(['2026-10-20 08:00:00', '2026-10-21 08:00:00'], $this->occurrenceStarts($floating));

        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['name' => $calendar->name, 'timezone' => 'America/New_York']))
            ->assertRedirect(SettingsPage::url($calendar->id))->assertSessionMissing('errors');

        // 10:00 in New York (EDT) is 14:00 UTC.
        $floating = $floating->fresh();
        $this->assertSame(['2026-10-20 14:00:00', '2026-10-20 15:00:00', 'America/New_York'], [$floating->starts_at, $floating->ends_at, $floating->timezone]);
        $this->assertSame(['2026-10-20 14:00:00', '2026-10-21 14:00:00'], $this->occurrenceStarts($floating));
        // An event with its own zone and an all-day event don't depend on the calendar's zone.
        $this->assertSame(['2026-10-20 08:00:00', 'Europe/Amsterdam'], [$zoned->fresh()->starts_at, $zoned->fresh()->timezone]);
        $this->assertSame(['2026-10-20 08:00:00'], $this->occurrenceStarts($zoned));
        $this->assertSame(['2026-10-20 00:00:00'], $this->occurrenceStarts($allDay));
    }

    public function test_a_floating_event_read_in_an_older_zone_follows_the_new_one_too(): void
    {
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $calendar = $this->local(['timezone' => 'Europe/Amsterdam']);
        $floating = app(EventService::class)->create($calendar, $this->makeUser(['timezone' => 'Europe/Amsterdam']), ['title' => 'Floating', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00']);
        // Derived while the calendar followed another zone (an earlier change of FreeScout's time zone moved nothing).
        $floating->ics = str_replace(['DTSTART;TZID=Europe/Amsterdam:', 'DTEND;TZID=Europe/Amsterdam:'], ['DTSTART:', 'DTEND:'], $floating->ics);
        $floating->fill(EventIcs::derive(Reader::read($floating->ics), $floating->uid, 'Europe/London'));
        $floating->save();
        $this->assertSame(['2026-10-20 09:00:00', 'Europe/London'], [$floating->starts_at, $floating->timezone]);

        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['name' => $calendar->name, 'timezone' => 'America/New_York']))
            ->assertSessionMissing('errors');

        $this->assertSame(['2026-10-20 14:00:00', 'America/New_York'], [$floating->fresh()->starts_at, $floating->fresh()->timezone]);
    }
}
