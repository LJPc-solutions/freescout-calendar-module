<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

class CalendarPermission extends Model
{
    public const SUBJECT_USER = 'user';
    public const SUBJECT_TEAM = 'team';
    public const SUBJECT_MAILBOX = 'mailbox';

    public const RIGHTS = ['show_in_dashboard', 'can_view', 'can_create', 'can_edit'];

    public $timestamps = false;

    protected $table = 'calendar_permissions';

    protected $guarded = ['id'];

    protected $casts = [
        'calendar_id'       => 'integer',
        'subject_id'        => 'integer',
        'show_in_dashboard' => 'boolean',
        'can_view'          => 'boolean',
        'can_create'        => 'boolean',
        'can_edit'          => 'boolean',
    ];
}
