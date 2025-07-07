<?php

return [
	'name'          => 'Calendar',
	'calendar_list' => env( 'CALENDAR_LIST', '' ),
	
	// Performance optimization feature flags
	'performance' => [
		// Enable optimized event lookup using database index
		'enable_event_index' => env('CALENDAR_ENABLE_EVENT_INDEX', false),
		
		// Enable CalDAV REPORT queries for single event fetching
		'enable_caldav_reports' => env('CALENDAR_ENABLE_CALDAV_REPORTS', false),
		
		// Force legacy mode for all calendars (emergency override)
		'force_legacy_mode' => env('CALENDAR_FORCE_LEGACY_MODE', false),
		
		// Enable performance metrics tracking
		'enable_metrics' => env('CALENDAR_ENABLE_METRICS', false),
	],
];