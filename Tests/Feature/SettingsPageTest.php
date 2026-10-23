<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Option;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class SettingsPageTest extends TestCase
{
    private function html(string $url): string
    {
        return $this->actingAs($this->makeAdmin())->get($url)->assertStatus(200)->getContent();
    }

    private function updateAction(Calendar $calendar): string
    {
        return 'action="' . route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]) . '"';
    }

    public function test_only_admins_see_the_settings(): void
    {
        $this->actingAs($this->makeUser())->get(SettingsPage::url())->assertStatus(403);
        $this->actingAs($this->makeUser())->get(SettingsPage::url('new'))->assertStatus(403);
    }

    public function test_list_shows_calendars_escaped_with_their_status(): void
    {
        $xss = Calendar::create(['name' => '<img src=x onerror=alert(1)> ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $stuck = Calendar::create(['name' => 'Stuck ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        DB::table('calendars')->where('id', $stuck->id)->update(['export_token' => null, 'export_busy_token' => null]);

        $html = $this->html(SettingsPage::url());

        $this->assertStringContainsString('&lt;img src=x', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('href="' . e(SettingsPage::url($xss->id)) . '"', $html);
        $this->assertStringContainsString('href="' . e(SettingsPage::url('new')) . '"', $html);
        $this->assertStringContainsString(e(__('Not upgraded yet')), $html);
    }

    public function test_new_calendar_form_posts_to_the_store_action(): void
    {
        $html = $this->html(SettingsPage::url('new'));

        $this->assertStringContainsString('action="' . route('ljpccalendarmodule.settings.calendars.store') . '"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="color"', $html);
    }

    public function test_edit_form_shows_settings_fields_permissions_and_links(): void
    {
        $agent = $this->makeUser();
        $mailbox = $this->makeMailbox();
        $calendar = Calendar::create(['name' => 'Edit ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'fields' => [
            ['id' => 172604180330, 'name' => 'Kind <b>', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']],
        ]]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $agent->id, 'can_view' => true]);

        $html = $this->html(SettingsPage::url($calendar->id));

        $this->assertStringContainsString($this->updateAction($calendar), $html);
        $this->assertStringContainsString('name="fields[f172604180330][name]" value="Kind &lt;b&gt;"', $html);
        $this->assertMatchesRegularExpression('/name="permissions\[user\]\[' . $agent->id . '\]\[can_view\]" value="1"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="permissions\[user\]\[' . $agent->id . '\]\[can_edit\]" value="1"[^>]*checked/', $html);
        $this->assertStringContainsString('name="permissions[mailbox][' . $mailbox->id . '][can_view]"', $html);
        $this->assertStringContainsString(e(route('ljpccalendarmodule.export', ['id' => $calendar->id, 'token' => $calendar->export_token])), $html);
        $this->assertStringContainsString('data-ljpc-insert="{{title}}"', $html);
        $this->assertStringContainsString('data-ljpc-insert="{{field:172604180330}}"', $html);

        // The completion marker is the form's last field, so a form cut off by max_input_vars misses it.
        $form = substr($html, strpos($html, $this->updateAction($calendar)));
        $form = substr($form, 0, strpos($form, '</form>'));
        $this->assertMatchesRegularExpression('/name="form_complete" value="1">\s*$/', $form);
    }

    public function test_a_failed_save_shows_the_errors_and_keeps_what_was_typed(): void
    {
        $admin = $this->makeAdmin();
        $calendar = Calendar::create(['name' => 'Edit ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $suffix = uniqid();

        $this->actingAs($admin)->from(SettingsPage::url($calendar->id))->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]), [
            'name' => 'Kept ' . $suffix, 'color' => '#000000', 'enabled' => '1', 'display_priority' => '100',
            'title_template' => '{{field:999999}}', 'fields' => ['new7' => ['id' => '', 'name' => 'Typed field', 'type' => 'text']],
            'form_complete' => '1',
        ]);
        $html = $this->actingAs($admin)->get(SettingsPage::url($calendar->id))->getContent();

        $this->assertStringContainsString(e(__('The title template uses a field that does not exist.')), $html);
        $this->assertStringContainsString('value="Kept ' . $suffix . '"', $html);
        $this->assertStringContainsString('name="fields[new7][name]" value="Typed field"', $html);
    }

    public function test_a_calendar_the_upgrade_has_not_converted_shows_a_notice_and_only_delete(): void
    {
        $stuck = Calendar::create(['name' => 'Stuck ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        DB::table('calendars')->where('id', $stuck->id)->update(['export_token' => null, 'export_busy_token' => null]);

        $html = $this->html(SettingsPage::url($stuck->id));

        $this->assertStringNotContainsString($this->updateAction($stuck), $html);
        $this->assertStringContainsString('action="' . route('ljpccalendarmodule.settings.calendars.delete', ['id' => $stuck->id]) . '"', $html);
        $this->assertStringContainsString(e(__('This calendar has not been upgraded to version 3 yet, so it can\'t be changed. The upgrade report above shows why. You can still delete it.')), $html);
    }

    public function test_an_unfinished_upgrade_shows_the_report_and_the_pending_items(): void
    {
        Option::set(MigrationService::STATE_OPTION, 'running');
        $calendar = Calendar::create(['name' => 'Cal ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $id = DB::table('calendar_items')->insertGetId([
            'calendar_id' => $calendar->id, 'author_id' => 1, 'is_all_day' => 0, 'is_private' => 0, 'is_read_only' => 0,
            'title' => '<b>Stuck</b> item', 'body' => '', 'state' => 'published', 'location' => null,
            'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00', 'created_at' => '2025-01-01 10:00:00', 'updated_at' => '2025-01-01 10:00:00',
        ]);

        $html = $this->html(SettingsPage::url());

        $this->assertStringContainsString(e(__('The upgrade to version 3 has not finished')), $html);
        $this->assertStringContainsString('&lt;b&gt;Stuck&lt;/b&gt; item', $html);
        $this->assertStringContainsString('action="' . route('ljpccalendarmodule.settings.migration.skip', ['id' => $id]) . '"', $html);
        $this->assertStringContainsString('href="' . route('system.tools') . '"', $html);
    }

    public function test_a_finished_upgrade_shows_the_report_only_when_it_has_warnings_or_errors(): void
    {
        Option::set(MigrationService::STATE_OPTION, 'complete');
        Option::set(MigrationService::REPORT_OPTION, [['level' => 'info', 'message' => 'The upgrade to version :version is complete.', 'context' => ['version' => '3'], 'at' => '2026-10-06 10:00:00']]);
        $this->assertStringNotContainsString(e(__('Upgrade report')), $this->html(SettingsPage::url()));

        Option::set(MigrationService::REPORT_OPTION, [['level' => 'warning', 'message' => 'Event ":title" (#:id) was skipped by :user and not converted; it is listed here with its data.', 'context' => ['id' => 7, 'title' => '<i>Old</i>', 'user' => 'Lars', 'body' => 'Notes'], 'at' => '2026-10-06 10:00:00']]);
        $html = $this->html(SettingsPage::url());
        $this->assertStringContainsString(e(__('Upgrade report')), $html);
        $this->assertStringContainsString('&lt;i&gt;Old&lt;/i&gt;', $html);
        $this->assertStringContainsString('body: Notes', $html);
    }

    public function test_connection_secrets_never_reach_the_page(): void
    {
        $connection = new CalendarConnection(['provider' => 'caldav', 'account' => 'lars']);
        $connection->setCredentials(['password' => 'hunter2']);
        $connection->save();
        $calendar = Calendar::create(['name' => 'Remote ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $connection->id]);

        $this->assertStringNotContainsString('hunter2', $this->html(SettingsPage::url()));
        $this->assertStringNotContainsString('hunter2', $this->html(SettingsPage::url($calendar->id)));
    }

    public function test_field_errors_are_shown_above_the_fields_and_under_the_type(): void
    {
        $admin = $this->makeAdmin();
        $calendar = Calendar::create(['name' => 'Edit ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $rows = [];
        for ($n = 0; $n < 51; $n++) {
            $rows['f' . (1000 + $n)] = ['id' => (string) (1000 + $n), 'name' => 'Field ' . $n, 'type' => 'text'];
        }
        $rows['f1000']['type'] = 'bogus';
        $post = ['name' => 'Many', 'color' => '#000000', 'enabled' => '1', 'display_priority' => '100', 'fields' => $rows, 'form_complete' => '1'];

        $this->actingAs($admin)->from(SettingsPage::url($calendar->id))->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]), $post);
        $html = $this->actingAs($admin)->get(SettingsPage::url($calendar->id))->getContent();
        $this->assertMatchesRegularExpression('/<td class="has-error">\s*<select[^>]*fields\[f1000\]\[type\].*?<strong>[^<]+<\/strong>/s', $html);

        $rows['f1000']['type'] = 'text';
        $this->actingAs($admin)->from(SettingsPage::url($calendar->id))->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]), ['fields' => $rows] + $post);
        $html = $this->actingAs($admin)->get(SettingsPage::url($calendar->id))->getContent();
        $this->assertStringContainsString(e(__('A calendar can have at most 50 custom fields.')), $html);
    }

    public function test_array_values_in_the_query_show_the_list(): void
    {
        $html = $this->actingAs($this->makeAdmin())->get(SettingsPage::url() . '?calendar[]=1')->assertStatus(200)->getContent();
        $this->assertStringContainsString('class="ljpc-settings"', $html);
    }

    public function test_array_values_in_a_posted_form_come_back_as_validation_errors(): void
    {
        $admin = $this->makeAdmin();
        $calendar = Calendar::create(['name' => 'Edit ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $edit = SettingsPage::url($calendar->id);
        $update = route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]);
        $post = ['name' => 'Fine', 'color' => '#000000', 'enabled' => '1', 'display_priority' => '100', 'form_complete' => '1'];

        foreach ([['name', ['name' => ['x']]], ['color', ['color' => ['x']]], ['fields', ['fields' => ['k' => ['id' => ['x'], 'name' => 'A', 'type' => 'text']]]]] as [$key, $override]) {
            $this->actingAs($admin)->from($edit)->post($update, $override + $post)->assertRedirect($edit)->assertSessionHasErrors();
            $this->actingAs($admin)->get($edit)->assertStatus(200);
        }
        foreach ([['name', ['name' => ['x'], 'color' => '#000000']], ['color', ['name' => 'Fine', 'color' => ['x']]]] as [$key, $post]) {
            $this->actingAs($admin)->from(SettingsPage::url('new'))->post(route('ljpccalendarmodule.settings.calendars.store'), $post)->assertSessionHasErrors([$key]);
            $this->actingAs($admin)->get(SettingsPage::url('new'))->assertStatus(200);
        }
    }

    public function test_the_matrix_lists_only_teams_that_the_teams_module_accepts(): void
    {
        if (!class_exists('Team')) {
            $this->markTestSkipped('The Teams module is not installed.');
        }
        $member = $this->makeUser();
        $real = $this->makeTeam([$member->id]);
        // Looks like a team (robot, "fsteam" email, member list) but is not deleted, so \Team::isTeam() rejects it.
        $impostor = $this->makeTeam([$member->id]);
        $impostor->update(['status' => \App\User::STATUS_ACTIVE]);
        $calendar = Calendar::create(['name' => 'Edit ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);

        $html = $this->html(SettingsPage::url($calendar->id));

        $this->assertStringContainsString('name="permissions[team][' . $real->id . '][can_view]"', $html);
        $this->assertStringNotContainsString('name="permissions[team][' . $impostor->id . ']', $html);
    }

    public function test_a_deleted_calendar_shows_that_it_no_longer_exists(): void
    {
        $calendar = Calendar::create(['name' => 'Gone ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $id = $calendar->id;
        $calendar->delete();

        $this->assertStringContainsString(e(__('This calendar no longer exists.')), $this->html(SettingsPage::url($id)));
    }

    public function test_a_failed_save_keeps_the_posted_permission_boxes(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeUser();
        $calendar = Calendar::create(['name' => 'Edit ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $agent->id, 'can_view' => true, 'can_edit' => true]);
        $edit = SettingsPage::url($calendar->id);

        // The admin unticks Edit, ticks Create, and the save fails on the name.
        $this->actingAs($admin)->from($edit)->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]), [
            'name' => '', 'color' => '#000000', 'enabled' => '1', 'display_priority' => '100', 'form_complete' => '1',
            'permissions' => ['user' => [$agent->id => ['can_view' => '1', 'can_create' => '1']]],
        ])->assertSessionHasErrors(['name']);
        $html = $this->actingAs($admin)->get($edit)->getContent();

        $box = function (string $right) use ($agent): string {
            return '/name="permissions\[user\]\[' . $agent->id . '\]\[' . $right . '\]" value="1"[^>]*checked/';
        };
        $this->assertMatchesRegularExpression($box('can_view'), $html);
        $this->assertMatchesRegularExpression($box('can_create'), $html);
        $this->assertDoesNotMatchRegularExpression($box('can_edit'), $html);
    }
}
