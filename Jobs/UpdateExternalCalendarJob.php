<?php

namespace Modules\LJPcCalendarModule\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use ICal\Event;
use ICal\ICal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEventIndex;
use Modules\LJPcCalendarModule\Http\Helpers\DateTimeRange;

class UpdateExternalCalendarJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $calendarId;

    public function __construct( int $calendarId ) {
        $this->calendarId = $calendarId;
    }

    public function handle(): void {
        $calendar = Calendar::find( $this->calendarId );
        if ( $calendar === null ) {
            return;
        }
        if ( $calendar->type !== 'ics' && $calendar->type !== 'caldav' ) {
            return;
        }

        try {
            // Fetch latest calendar data
            $calendar->getExternalContent( true );

            // Check if indexing is enabled
            $enableEventIndex = config('ljpccalendarmodule.performance.enable_event_index', false);
            
            if ( $enableEventIndex && !$calendar->force_legacy_mode ) {
                $this->updateEventIndex( $calendar );
            }

            // Update custom fields
            $customFields                = $calendar->custom_fields;
            $customFields['last_update'] = time();
            $calendar->custom_fields     = $customFields;
            $calendar->save();
            
        } catch ( \Exception $e ) {
            Log::error('Failed to update external calendar', [
                'calendar_id' => $this->calendarId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * Update the event index for this calendar
     */
    protected function updateEventIndex( Calendar $calendar ): void {
        $startTime = microtime(true);
        
        // Increment sync version
        $calendar->increment('sync_version');
        $newSyncVersion = $calendar->sync_version;
        
        Log::info('Starting event index update', [
            'calendar_id' => $calendar->id,
            'sync_version' => $newSyncVersion
        ]);
        
        // Get the temporary file with calendar data
        $file = $calendar->getTemporaryFile();
        if ( !$file || !file_exists($file) ) {
            Log::warning('Calendar file not found for indexing', [
                'calendar_id' => $calendar->id
            ]);
            return;
        }
        
        try {
            // Parse the ICS file
            $ical = new ICal( $file, [
                'filterDaysBefore' => null,  // No date filtering for index
                'filterDaysAfter'  => null,
            ] );
            
            /** @var Event[] $events */
            $events = $ical->events();
            $totalEvents = count($events);
            $indexedCount = 0;
            $currentEventUids = [];
            
            Log::info('Found events to index', [
                'calendar_id' => $calendar->id,
                'event_count' => $totalEvents
            ]);
            
            // Process events in batches for better performance
            $batchSize = 100;
            $eventBatch = [];
            
            foreach ( $events as $event ) {
                if ( !isset($event->uid) ) {
                    continue;
                }
                
                $currentEventUids[] = $event->uid;
                
                // Parse event data
                $eventData = $this->parseEventForIndex( $event, $ical, $calendar );
                if ( $eventData ) {
                    $eventBatch[] = $eventData;
                    
                    // Process batch when it reaches the size limit
                    if ( count($eventBatch) >= $batchSize ) {
                        $this->processBatch( $calendar->id, $eventBatch, $newSyncVersion );
                        $indexedCount += count($eventBatch);
                        $eventBatch = [];
                    }
                }
                
                // Safety limit check
                if ( $indexedCount >= config('ljpccalendarmodule.performance.max_indexed_events', 50000) ) {
                    Log::warning('Event index limit reached', [
                        'calendar_id' => $calendar->id,
                        'indexed_count' => $indexedCount
                    ]);
                    break;
                }
            }
            
            // Process remaining events
            if ( !empty($eventBatch) ) {
                $this->processBatch( $calendar->id, $eventBatch, $newSyncVersion );
                $indexedCount += count($eventBatch);
            }
            
            // Remove orphaned events (those that no longer exist in the calendar)
            $deletedCount = CalendarEventIndex::removeOrphanedEvents( 
                $calendar->id, 
                $currentEventUids, 
                $newSyncVersion 
            );
            
            // Update last full sync timestamp
            $calendar->update(['last_full_sync' => now()]);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('Event index update completed', [
                'calendar_id' => $calendar->id,
                'sync_version' => $newSyncVersion,
                'events_indexed' => $indexedCount,
                'events_deleted' => $deletedCount,
                'duration_ms' => $duration
            ]);
            
        } catch ( \Exception $e ) {
            Log::error('Failed to update event index', [
                'calendar_id' => $calendar->id,
                'error' => $e->getMessage()
            ]);
            // Don't throw - allow the job to complete even if indexing fails
        }
    }
    
    /**
     * Parse an event for indexing
     */
    protected function parseEventForIndex( Event $event, ICal $ical, Calendar $calendar ): ?array {
        try {
            $start = DateTimeImmutable::createFromMutable( 
                $ical->iCalDateToDateTime( $event->dtstart_array[3] )->setTimezone( new DateTimeZone( 'UTC' ) ) 
            );
            
            if ( ! empty( $event->dtend ) ) {
                $end = DateTimeImmutable::createFromMutable( 
                    $ical->iCalDateToDateTime( $event->dtend_array[3] )->setTimezone( new DateTimeZone( 'UTC' ) ) 
                );
            } else {
                if ( ! empty( $event->duration ) ) {
                    $end = $start->add( new \DateInterval( $event->duration ) );
                } else {
                    $end = $start->add( new \DateInterval( 'PT1H' ) );
                }
            }

            $modifiedEnd = $end;
            if ( DateTimeRange::isAllDay( $start, $end ) ) {
                $modifiedEnd = $end->modify( '-1 second' );
            }

            // Build the event data array
            return [
                'id' => $event->uid,
                'uid' => $event->uid,
                'calendar_id' => $calendar->id,
                'title' => $event->summary ?? '',
                'summary' => $event->summary ?? '',
                'location' => $event->location ?? '',
                'body' => $event->description ?? '',
                'state' => $event->status ?? '',
                'start' => $start->format( 'Y-m-d H:i:s' ),
                'end' => $modifiedEnd->format( 'Y-m-d H:i:s' ),
                'is_all_day' => DateTimeRange::isAllDay( $start, $end ),
                'is_private' => false,
                'is_read_only' => $calendar->type === 'ics',
                'rrule' => $event->rrule ?? null,
                'recurrence_id' => isset($event->{'recurrence-id'}) ? $event->{'recurrence-id'} : null,
            ];
            
        } catch ( \Exception $e ) {
            Log::warning('Failed to parse event for index', [
                'calendar_id' => $calendar->id,
                'event_uid' => $event->uid ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Process a batch of events for indexing
     */
    protected function processBatch( int $calendarId, array $eventBatch, int $syncVersion ): void {
        DB::transaction(function() use ($calendarId, $eventBatch, $syncVersion) {
            foreach ( $eventBatch as $eventData ) {
                try {
                    CalendarEventIndex::updateFromEventData( $calendarId, $eventData, $syncVersion );
                } catch ( \Exception $e ) {
                    Log::warning('Failed to index individual event', [
                        'calendar_id' => $calendarId,
                        'event_uid' => $eventData['uid'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }
        });
    }
}
