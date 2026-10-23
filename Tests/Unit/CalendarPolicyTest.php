<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Tests\TestCase;

class CalendarPolicyTest extends TestCase
{
    private function calendar(array $attributes = []): Calendar
    {
        return Calendar::create($attributes + ['name' => 'Policy ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
    }

    private function grant(Calendar $calendar, string $type, int $id, array $rights): void
    {
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => $type, 'subject_id' => $id] + $rights);
    }

    public function test_no_rows_means_no_access_but_admins_see_everything_enabled(): void
    {
        $calendar = $this->calendar();

        $this->assertSame(['show_in_dashboard' => false, 'can_view' => false, 'can_create' => false, 'can_edit' => false], CalendarPolicy::rights($this->makeUser(), $calendar));
        $this->assertTrue($this->makeAdmin()->can('edit', $calendar));
        $this->assertFalse($this->makeAdmin()->can('view', $this->calendar(['enabled' => false])));
    }

    public function test_rights_are_the_union_of_user_and_mailbox_rows(): void
    {
        $user = $this->makeUser();
        $mailbox = $this->makeMailbox([$user->id]);
        $calendar = $this->calendar();
        $this->grant($calendar, CalendarPermission::SUBJECT_USER, $user->id, ['can_view' => true]);
        $this->grant($calendar, CalendarPermission::SUBJECT_MAILBOX, $mailbox->id, ['can_create' => true]);

        $rights = CalendarPolicy::rights($user, $calendar->fresh());
        $this->assertTrue($rights['can_view']);
        $this->assertTrue($rights['can_create']);
        $this->assertFalse($rights['can_edit']);
        $this->assertFalse(CalendarPolicy::rights($this->makeUser(), $calendar->fresh())['can_create']); // not in the mailbox
        $this->assertSame([$calendar->id], array_values(array_intersect(CalendarPolicy::viewableCalendarIds($user), [$calendar->id])));
    }

    public function test_team_rows_apply_to_team_members(): void
    {
        if (!class_exists('Team')) {
            $this->markTestSkipped('Teams module is not active.');
        }
        $member = $this->makeUser();
        $team = $this->makeTeam([$member->id]);
        $calendar = $this->calendar();
        $this->grant($calendar, CalendarPermission::SUBJECT_TEAM, $team->id, ['can_view' => true, 'can_edit' => true]);

        $this->assertTrue($member->can('edit', $calendar->fresh()));
        $this->assertFalse($this->makeUser()->can('view', $calendar->fresh()));
    }

    public function test_a_disabled_calendar_gives_non_admins_no_rights_through_any_row(): void
    {
        $user = $this->makeUser();
        $mailbox = $this->makeMailbox([$user->id]);
        $team = $this->makeTeam([$user->id]);
        $calendar = $this->calendar(['enabled' => false]);
        $all = ['show_in_dashboard' => true, 'can_view' => true, 'can_create' => true, 'can_edit' => true];
        $this->grant($calendar, CalendarPermission::SUBJECT_USER, $user->id, $all);
        $this->grant($calendar, CalendarPermission::SUBJECT_TEAM, $team->id, $all);
        $this->grant($calendar, CalendarPermission::SUBJECT_MAILBOX, $mailbox->id, $all);

        $this->assertSame(array_fill_keys(CalendarPermission::RIGHTS, false), CalendarPolicy::rights($user, $calendar->fresh()));
        $this->assertNotContains($calendar->id, CalendarPolicy::viewableCalendarIds($user));
    }

    public function test_team_rows_only_count_for_a_real_team(): void
    {
        $member = $this->makeUser();
        // Looks like a team (robot, "fsteam" email, member list) but is not deleted, so the Teams module does not treat it as one.
        $impostor = $this->makeTeam([$member->id]);
        $impostor->update(['status' => \App\User::STATUS_ACTIVE]);
        $calendar = $this->calendar();
        $this->grant($calendar, CalendarPermission::SUBJECT_TEAM, $impostor->id, ['can_view' => true, 'can_edit' => true]);

        $this->assertFalse($member->can('view', $calendar->fresh()));
        $this->assertFalse($member->can('edit', $calendar->fresh()));
    }

    public function test_team_rows_give_no_rights_while_the_teams_module_is_inactive(): void
    {
        $member = $this->makeUser();
        $team = $this->makeTeam([$member->id]);
        $calendar = $this->calendar();
        $this->grant($calendar, CalendarPermission::SUBJECT_TEAM, $team->id, ['can_view' => true, 'can_edit' => true]);

        // Deactivates Teams in core's in-memory module list only; the cache is reloaded from the database afterwards.
        \App\Module::setActive('teams', false, false);
        try {
            $this->assertSame(array_fill_keys(CalendarPermission::RIGHTS, false), CalendarPolicy::rights($member, $calendar->fresh()));
        } finally {
            \App\Module::clearModulesCache();
        }
    }

    public function test_read_only_providers_never_allow_writes(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendar(['provider' => Calendar::PROVIDER_ICS]);
        $this->grant($calendar, CalendarPermission::SUBJECT_USER, $user->id, ['can_view' => true, 'can_create' => true, 'can_edit' => true]);

        $this->assertTrue($user->can('view', $calendar->fresh()));
        $this->assertFalse($user->can('create', $calendar->fresh()));
        $this->assertFalse($user->can('edit', $calendar->fresh()));
    }
}
