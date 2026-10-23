<?php

return [
    'name' => 'Calendar',

    // OAuth endpoints and scopes of the calendar providers. The endpoints can point at a relay through .env.
    'oauth' => [
        'google'    => [
            'authorize_url' => env('CALENDAR_GOOGLE_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
            'token_url'     => env('CALENDAR_GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
            'scopes'        => 'https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events',
        ],
        'microsoft' => [
            // The authorize and token URLs are {authority}/{tenant}/oauth2/v2.0/authorize and .../token.
            'authority' => env('CALENDAR_MICROSOFT_AUTHORITY', 'https://login.microsoftonline.com'),
            'scopes'    => 'offline_access User.Read Calendars.ReadWrite Calendars.ReadWrite.Shared',
        ],
    ],
];
