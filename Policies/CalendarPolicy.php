<?php

namespace Modules\LJPcCalendarModule\Policies;

use App\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;

/** The only place that decides calendar access. Rights are the union of every user, team and mailbox row that applies. */
class CalendarPolicy
{
    public static function rights(User $user, Calendar $calendar): array
    {
        $rights = array_fill_keys(CalendarPermission::RIGHTS, false);
        if (!$calendar->enabled) {
            return $rights;
        }
        if ($user->isAdmin()) {
            return array_fill_keys(CalendarPermission::RIGHTS, true);
        }
        foreach ($calendar->permissionRows as $row) {
            if (!self::applies($user, $row)) {
                continue;
            }
            foreach (CalendarPermission::RIGHTS as $right) {
                $rights[$right] = $rights[$right] || (bool) $row->{$right};
            }
        }

        return $rights;
    }

    /** Enabled calendars the user can view, in display order, with their permission rows loaded. */
    public static function viewableCalendars(User $user): Collection
    {
        return Calendar::where('enabled', true)->with('permissionRows')->orderBy('display_priority')->orderBy('name')->get()
            ->filter(function (Calendar $calendar) use ($user) {
                return self::rights($user, $calendar)['can_view'];
            })
            ->values();
    }

    public static function viewableCalendarIds(User $user): array
    {
        return self::viewableCalendars($user)->pluck('id')->all();
    }

    public function view(User $user, Calendar $calendar): bool
    {
        return self::rights($user, $calendar)['can_view'];
    }

    public function create(User $user, Calendar $calendar): bool
    {
        return $calendar->isWritable() && self::rights($user, $calendar)['can_create'];
    }

    public function edit(User $user, Calendar $calendar): bool
    {
        return $calendar->isWritable() && self::rights($user, $calendar)['can_edit'];
    }

    public function dashboard(User $user, Calendar $calendar): bool
    {
        return self::rights($user, $calendar)['show_in_dashboard'];
    }

    private static function applies(User $user, CalendarPermission $row): bool
    {
        switch ($row->subject_type) {
            case CalendarPermission::SUBJECT_USER:
                return $row->subject_id === (int) $user->id;
            case CalendarPermission::SUBJECT_MAILBOX:
                return (bool) $user->hasAccessToMailbox($row->subject_id);
            case CalendarPermission::SUBJECT_TEAM:
                return in_array((int) $user->id, self::teamMemberIds($row->subject_id), true);
        }

        return false;
    }

    /** Team rows only count while the Teams module is active, because that module defines teams and their members. */
    public static function teamsActive(): bool
    {
        return class_exists('Team') && \App\Module::isActive('teams');
    }

    /** The team with this id by the Teams module's own rule (\Team::isTeam), or null. CalendarForm validates with it too. */
    public static function team(int $id): ?User
    {
        if (!self::teamsActive()) {
            return null;
        }
        $team = User::find($id);

        return $team && \Team::isTeam($team) ? $team : null;
    }

    private static function teamMemberIds(int $teamId): array
    {
        $team = self::team($teamId);

        return $team ? array_map('intval', \Team::getMemberIds($team)) : [];
    }
}
