<?php

/*
|--------------------------------------------------------------------------
| Whatify (BYO-BSP WhatsApp) configuration
|--------------------------------------------------------------------------
| The merchant pastes their own Whatify API key inside the app (Settings).
| Nothing here is a shared secret — this file only holds endpoint wiring.
| Docs: https://whatify.in/api-docs  (External API, X-API-Key header)
*/

return [

    'base_url' => env('WHATIFY_BASE_URL', 'https://whatify.in/api/v1/external'),

    // Whatify itself waits up to ~8s for Meta delivery confirmation.
    'timeout' => (int) env('WHATIFY_TIMEOUT', 15),

    // Used when a staff phone is typed without a country code (Indian D2C default).
    'default_country_code' => env('WHATIFY_DEFAULT_COUNTRY_CODE', '91'),

    /*
    |--------------------------------------------------------------------------
    | Suggested Meta templates
    |--------------------------------------------------------------------------
    | The merchant must create + get these approved in their own Whatify
    | dashboard (exact body copy is shown in-app on the Settings screen and
    | in README.md). If a template is not configured for an event, the app
    | falls back to a plain session text message (only delivers when the
    | staff member is inside Meta's 24h service window — e.g. they replied
    | to your number once). Both paths are logged.
    */
    'template_keys' => ['otp', 'task_assigned', 'task_reminder', 'digest'],

    'otp_ttl_minutes' => 10,
];
