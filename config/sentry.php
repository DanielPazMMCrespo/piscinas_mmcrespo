<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sentry DSN
    |--------------------------------------------------------------------------
    |
    | The Data Source Name (DSN) identifies your application in Sentry.
    | Set this in your .env file as SENTRY_LARAVEL_DSN.
    |
    */

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Set this to the application environment (development, staging, production).
    | Defaults to APP_ENV.
    |
    */

    'environment' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | The release version of the application.
    | Automatically uses the git commit hash.
    |
    */

    'release' => env('APP_VERSION', 'unknown'),

    /*
    |--------------------------------------------------------------------------
    | Traces Sample Rate
    |--------------------------------------------------------------------------
    |
    | Set the sample rate for performance monitoring (0.0 to 1.0).
    | 0.1 = 10% of transactions sampled.
    |
    */

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Profiles Sample Rate
    |--------------------------------------------------------------------------
    |
    | Set the sample rate for profiling (0.0 to 1.0).
    | Requires traces_sample_rate > 0.
    | 0.1 = 10% of transactions profiled.
    |
    */

    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    |
    | Record breadcrumbs for better debugging context.
    |
    */

    'breadcrumbs' => [
        'logs' => true,
        'sql_queries' => true,
        'cache' => true,
        'http_client_requests' => true,
        'queue_info' => true,
    ],

];
