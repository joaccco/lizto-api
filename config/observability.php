<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Error Tracking Configuration
    |--------------------------------------------------------------------------
    |
    | Integrates an error tracking service (e.g. Sentry) controlled by environment
    | variables. Disabled when DSN or ENABLED is false.
    |
    */
    'error_tracker' => [
        'enabled' => env('ERROR_TRACKER_ENABLED', false),
        'dsn' => env('SENTRY_DSN', env('ERROR_TRACKER_DSN', null)),
        'environment' => env('APP_ENV', 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Correlation ID Header Name
    |--------------------------------------------------------------------------
    */
    'correlation_header' => 'X-Correlation-ID',

    /*
    |--------------------------------------------------------------------------
    | Sensitive Keys to Filter out from Logs & Error Events (Privacy Protection)
    |--------------------------------------------------------------------------
    */
    'sensitive_keys' => [
        'email',
        'phone',
        'phone_number',
        'address',
        'location_address',
        'base_address',
        'lat',
        'lng',
        'base_lat',
        'base_lng',
        'location_lat',
        'location_lng',
        'password',
        'password_confirmation',
        'token',
        'session_token',
        'device_token',
        'auth_token',
        'access_token',
        'authorization',
        'secret',
        'credentials',
        'api_key',
        'key',
        'name',
        'commercial_name',
        'user_name',
    ],
];
