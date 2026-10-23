<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

class CalendarConnection extends Model
{
    public const STATUS_OK = 'ok';
    public const STATUS_NEEDS_REAUTH = 'needs_reauth';
    public const STATUS_ERROR = 'error';

    protected $table = 'calendar_connections';

    protected $guarded = ['id', 'credentials'];

    protected $hidden = ['credentials'];

    /** Mirrors the column default, so a connection created without a status is healthy before it is read back. */
    protected $attributes = ['status' => self::STATUS_OK];

    public function calendars()
    {
        return $this->hasMany(Calendar::class, 'connection_id');
    }

    /**
     * After the account signed in again: its calendars sync at the next run, without the backoff their failures (the
     * refused login among them) built up.
     */
    public function resumeCalendars(): void
    {
        Calendar::where('connection_id', $this->id)->update(['next_sync_at' => gmdate('Y-m-d H:i:s'), 'sync_failures' => 0]);
    }

    public function credentials(): array
    {
        $raw = $this->attributes['credentials'] ?? null;

        return $raw ? (json_decode(decrypt($raw), true) ?: []) : [];
    }

    public function setCredentials(array $credentials): void
    {
        $this->attributes['credentials'] = encrypt(json_encode($credentials));
    }
}
