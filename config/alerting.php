<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Alerting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure alert thresholds and notification channels.
    |
    */

    'enabled' => env('ALERTING_ENABLED', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | Alert Channels
    |--------------------------------------------------------------------------
    |
    | Where to send alerts (slack, email, etc).
    |
    */

    'channels' => [
        'slack' => [
            'enabled' => env('SLACK_BOT_USER_OAUTH_TOKEN') !== null,
            'channel' => env('SLACK_ALERT_CHANNEL', '#monitoring'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Thresholds
    |--------------------------------------------------------------------------
    |
    | Alert when error count exceeds threshold in given window.
    |
    */

    'error' => [
        'threshold' => (int) env('ALERT_ERROR_THRESHOLD', 5),
        'window_minutes' => (int) env('ALERT_ERROR_WINDOW_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Latency Thresholds
    |--------------------------------------------------------------------------
    |
    | Alert when P95 request latency exceeds threshold.
    |
    */

    'latency' => [
        'p95_threshold_ms' => (int) env('ALERT_LATENCY_THRESHOLD_MS', 2000),
        'p99_threshold_ms' => (int) env('ALERT_LATENCY_THRESHOLD_MS', 3000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Query Thresholds
    |--------------------------------------------------------------------------
    |
    | Alert on slow queries.
    |
    */

    'database' => [
        'slow_query_threshold_ms' => (int) env('ALERT_DB_SLOW_QUERY_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Thresholds
    |--------------------------------------------------------------------------
    |
    | Alert on poor cache performance.
    |
    */

    'cache' => [
        'miss_ratio_threshold' => (float) env('ALERT_CACHE_MISS_RATIO', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quiet Hours
    |--------------------------------------------------------------------------
    |
    | Don't alert during maintenance windows (HH:MM format).
    |
    */

    'quiet_hours' => [
        'start' => env('ALERT_QUIET_START', '02:00'),
        'end' => env('ALERT_QUIET_END', '03:00'),
    ],

];
