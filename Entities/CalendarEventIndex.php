<?php

namespace Modules\LJPcCalendarModule\Entities;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Class CalendarEventIndex
 * 
 * Indexes external calendar events for fast lookups
 *
 * @package Modules\LJPcCalendarModule\Entities
 * @property int $id
 * @property int $calendar_id
 * @property string $event_uid
 * @property string|null $event_summary
 * @property \DateTime|null $event_start
 * @property \DateTime|null $event_end
 * @property bool $is_all_day
 * @property array $event_data
 * @property string|null $event_location
 * @property int $sync_version
 * @property \DateTime|null $last_synced_at
 * @property bool $is_recurring
 * @property string|null $recurrence_id
 * @property int $access_count
 * @property \DateTime|null $last_accessed_at
 * @property-read Calendar $calendar
 */
class CalendarEventIndex extends Model
{
    protected $table = 'calendar_event_index';
    
    protected $fillable = [
        'calendar_id',
        'event_uid',
        'event_summary',
        'event_start',
        'event_end',
        'is_all_day',
        'event_data',
        'event_location',
        'sync_version',
        'last_synced_at',
        'is_recurring',
        'recurrence_id',
        'access_count',
        'last_accessed_at',
    ];
    
    protected $casts = [
        'event_data' => 'array',
        'is_all_day' => 'boolean',
        'is_recurring' => 'boolean',
        'event_start' => 'datetime',
        'event_end' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];
    
    /**
     * Calendar relationship
     */
    public function calendar()
    {
        return $this->belongsTo(Calendar::class);
    }
    
    /**
     * Check if the cached event data is still fresh based on refresh interval
     * 
     * @param int $refreshIntervalSeconds
     * @return bool
     */
    public function isFresh(int $refreshIntervalSeconds = 3600): bool
    {
        if (!$this->last_synced_at) {
            return false;
        }
        
        $expiresAt = $this->last_synced_at->addSeconds($refreshIntervalSeconds);
        return $expiresAt->isFuture();
    }
    
    /**
     * Increment access count and update last accessed timestamp
     */
    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }
    
    /**
     * Create or update index entry from event data
     * 
     * @param int $calendarId
     * @param array $eventData
     * @param int $syncVersion
     * @return static
     */
    public static function updateFromEventData(int $calendarId, array $eventData, int $syncVersion): self
    {
        $uid = $eventData['uid'] ?? $eventData['id'] ?? null;
        if (!$uid) {
            throw new \InvalidArgumentException('Event must have uid or id');
        }
        
        $indexData = [
            'calendar_id' => $calendarId,
            'event_uid' => $uid,
            'event_summary' => $eventData['title'] ?? $eventData['summary'] ?? '',
            'event_location' => $eventData['location'] ?? null,
            'is_all_day' => $eventData['is_all_day'] ?? false,
            'event_data' => $eventData,
            'sync_version' => $syncVersion,
            'last_synced_at' => now(),
            'is_recurring' => isset($eventData['rrule']) || isset($eventData['recurrence']),
            'recurrence_id' => $eventData['recurrence_id'] ?? null,
        ];
        
        // Parse dates
        if (isset($eventData['start'])) {
            try {
                $indexData['event_start'] = new DateTimeImmutable($eventData['start']);
            } catch (\Exception $e) {
                Log::warning('Failed to parse event start date', ['uid' => $uid, 'start' => $eventData['start']]);
            }
        }
        
        if (isset($eventData['end'])) {
            try {
                $indexData['event_end'] = new DateTimeImmutable($eventData['end']);
            } catch (\Exception $e) {
                Log::warning('Failed to parse event end date', ['uid' => $uid, 'end' => $eventData['end']]);
            }
        }
        
        return static::updateOrCreate(
            [
                'calendar_id' => $calendarId,
                'event_uid' => $uid,
            ],
            $indexData
        );
    }
    
    /**
     * Remove events that no longer exist in the calendar
     * 
     * @param int $calendarId
     * @param array $currentEventUids
     * @param int $syncVersion
     * @return int Number of deleted events
     */
    public static function removeOrphanedEvents(int $calendarId, array $currentEventUids, int $syncVersion): int
    {
        return static::where('calendar_id', $calendarId)
            ->where('sync_version', '<', $syncVersion)
            ->whereNotIn('event_uid', $currentEventUids)
            ->delete();
    }
    
    /**
     * Get popular events for pre-warming cache
     * 
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPopularEvents(int $limit = 100)
    {
        return static::orderBy('access_count', 'desc')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Convert to CalendarItem format for compatibility
     * 
     * @return array
     */
    public function toCalendarItemArray(): array
    {
        $data = $this->event_data;
        
        // Ensure required fields are present
        $data['id'] = $data['id'] ?? $this->event_uid;
        $data['uid'] = $this->event_uid;
        $data['calendar_id'] = $this->calendar_id;
        $data['start'] = $this->event_start ? $this->event_start->format('Y-m-d H:i:s') : null;
        $data['end'] = $this->event_end ? $this->event_end->format('Y-m-d H:i:s') : null;
        $data['is_all_day'] = $this->is_all_day;
        $data['title'] = $this->event_summary;
        $data['location'] = $this->event_location;
        
        return $data;
    }
}