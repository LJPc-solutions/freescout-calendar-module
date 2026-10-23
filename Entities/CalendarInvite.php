<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

/** One iTIP message found in a conversation's attachment (InviteService). */
class CalendarInvite extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_OUTDATED = 'outdated';
    /** A REPLY to one of our own invites, applied automatically. */
    public const STATUS_PROCESSED = 'processed';

    protected $table = 'calendar_invites';

    protected $guarded = ['id', 'uid_hash'];

    protected $casts = [
        'conversation_id' => 'integer',
        'thread_id'       => 'integer',
        'attachment_id'   => 'integer',
        'sequence'        => 'integer',
        'event_id'        => 'integer',
        'applied_by'      => 'integer',
    ];

    public function setUidAttribute(string $uid): void
    {
        $this->attributes['uid'] = $uid;
        $this->attributes['uid_hash'] = CalendarEvent::hashUid($uid);
    }

    public function event()
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }
}
