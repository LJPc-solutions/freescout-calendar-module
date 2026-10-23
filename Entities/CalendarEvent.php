<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    /** Thread action type for "added to calendar" line items (unchanged since v2). */
    public const ACTION_TYPE_ADD_TO_CALENDAR = 186;

    protected $table = 'calendar_events';

    protected $guarded = ['id', 'uid_hash', 'remote_id_hash'];

    protected $casts = [
        'calendar_id'      => 'integer',
        'author_id'        => 'integer',
        'conversation_id'  => 'integer',
        'sequence'         => 'integer',
        'reminder_minutes' => 'integer',
        'all_day'          => 'boolean',
        'is_recurring'     => 'boolean',
        'is_private'       => 'boolean',
        'read_only'        => 'boolean',
        'field_values'     => 'array',
        'attendees'        => 'array',
        'sync_generation'  => 'integer',
    ];

    protected $hidden = ['ics'];

    public function setUidAttribute(string $uid): void
    {
        $this->attributes['uid'] = $uid;
        $this->attributes['uid_hash'] = self::hashUid($uid);
    }

    public static function hashUid(string $uid): string
    {
        return sha1($uid);
    }

    public static function findByUid(int $calendarId, string $uid): ?self
    {
        return self::where('calendar_id', $calendarId)->where('uid_hash', self::hashUid($uid))->first();
    }

    /** remote_id is text, which MySQL can't index, so lookups go through its sha1. An empty remote id is no remote id. */
    public function setRemoteIdAttribute(?string $remoteId): void
    {
        $remoteId = $remoteId === '' ? null : $remoteId;
        $this->attributes['remote_id'] = $remoteId;
        $this->attributes['remote_id_hash'] = $remoteId === null ? null : sha1($remoteId);
    }

    public static function findByRemoteId(int $calendarId, string $remoteId): ?self
    {
        return self::where('calendar_id', $calendarId)->where('remote_id_hash', sha1($remoteId))->first();
    }

    /** @return self[] the events of a calendar with these remote ids, by remote id */
    public static function byRemoteIds(int $calendarId, array $remoteIds): array
    {
        $events = [];
        foreach (array_chunk(array_values(array_unique($remoteIds)), 500) as $chunk) {
            foreach (self::where('calendar_id', $calendarId)->whereIn('remote_id_hash', array_map('sha1', $chunk))->get() as $event) {
                $events[(string) $event->remote_id] = $event;
            }
        }

        return $events;
    }

    /** The calendar page's permalink; an occurrence of a repeating event adds its recurrence key (#e12@20261014T080000Z). */
    public static function permalink(int $id, ?string $recurrenceId = null): string
    {
        return route('ljpccalendarmodule.index') . '#e' . $id . ($recurrenceId ? '@' . $recurrenceId : '');
    }

    public function calendar()
    {
        return $this->belongsTo(Calendar::class);
    }

    public function conversation()
    {
        return $this->belongsTo(\App\Conversation::class);
    }

    public function occurrences()
    {
        return $this->hasMany(CalendarOccurrence::class, 'event_id');
    }
}
