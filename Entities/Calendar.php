<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Sabre\VObject\Component\VEvent;

class Calendar extends Model
{
    public const PROVIDER_LOCAL = 'local';
    public const PROVIDER_ICS = 'ics';
    public const PROVIDER_CALDAV = 'caldav';
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_MICROSOFT = 'microsoft';

    /**
     * How often an external calendar syncs, in minutes: the choices the settings offer. The keys are the refresh
     * settings v2 stored, which the upgrade turns into these values.
     */
    public const SYNC_INTERVALS = [
        '1 minute' => 1, '5 minutes' => 5, '15 minutes' => 15, '30 minutes' => 30, '1 hour' => 60,
        '2 hours' => 120, '6 hours' => 360, '12 hours' => 720, 'daily' => 1440,
    ];

    protected $table = 'calendars';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled'                  => 'boolean',
        'fields'                   => 'array',
        'sync_state'               => 'array',
        'display_priority'         => 'integer',
        'sync_interval'            => 'integer',
        'default_reminder_minutes' => 'integer',
        'connection_id'            => 'integer',
        'sync_failures'            => 'integer',
        'remote_read_only'         => 'boolean',
    ];

    protected $hidden = ['export_token', 'export_busy_token', 'sync_state', 'permissions', 'custom_fields'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Calendar $calendar) {
            $calendar->provider = $calendar->provider ?: self::PROVIDER_LOCAL;
            if ($calendar->enabled === null) {
                $calendar->enabled = true; // mirrors the column default so the unsaved-then-created model agrees with the row
            }
            $calendar->export_token = $calendar->export_token ?: self::newToken();
            $calendar->export_busy_token = $calendar->export_busy_token ?: self::newToken();
            if ($calendar->display_priority === null) {
                $calendar->display_priority = self::defaultPriority($calendar->provider);
            }
        });
    }

    /** Not named permissions(): the v2 JSON column "permissions" exists until the cleanup migration and would shadow the relation. */
    public function permissionRows()
    {
        return $this->hasMany(CalendarPermission::class);
    }

    public function events()
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function calendarConnection()
    {
        return $this->belongsTo(CalendarConnection::class, 'connection_id');
    }

    public function isLocal(): bool
    {
        return $this->provider === self::PROVIDER_LOCAL;
    }

    /** CalDAV, Google and Microsoft calendars sync through a connected account. */
    public function usesConnection(): bool
    {
        return in_array($this->provider, [self::PROVIDER_CALDAV, self::PROVIDER_GOOGLE, self::PROVIDER_MICROSOFT], true);
    }

    /**
     * Whether events can never be written here: the provider only reads (an ICS feed), or the connected account may
     * only read this calendar (remote_read_only, set when it was picked and refreshed on the pick screen).
     */
    public function isReadOnly(): bool
    {
        return (bool) $this->remote_read_only || !app(ProviderRegistry::class)->for($this)->canWrite();
    }

    /** Whether events can be written: the provider can write, the account may write this calendar, and it still works. */
    public function isWritable(): bool
    {
        if ($this->remote_read_only) {
            return false;
        }
        if ($this->usesConnection()) {
            $connection = $this->calendarConnection;
            if (!$connection || $connection->status !== CalendarConnection::STATUS_OK) {
                return false;
            }
        }

        return app(ProviderRegistry::class)->for($this)->canWrite();
    }

    /** Whether a whole-series change of this master can be written here: a single event always, a series when the provider can write its rule. */
    public function canWriteSeries(VEvent $master): bool
    {
        if (!isset($master->RRULE) && !isset($master->RDATE)) {
            return true;
        }

        return app(ProviderRegistry::class)->for($this)->writableRecurrence($master);
    }

    public function timezoneName(): string
    {
        return $this->timezone ?: (config('app.timezone') ?: 'UTC');
    }

    public function fieldDefinitions(): array
    {
        return is_array($this->fields) ? $this->fields : [];
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    public static function defaultPriority(string $provider): int
    {
        return $provider === self::PROVIDER_LOCAL ? 100 : 200;
    }
}
