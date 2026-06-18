<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Prometheus Metrics Configuration
    |--------------------------------------------------------------------------
    |
    | Configure metrics collection and exposure settings.
    |
    */

    'enabled' => env('PROMETHEUS_ENABLED', true),

    'namespace' => env('PROMETHEUS_NAMESPACE', 'piscinas_mmcrespo'),

    /*
    |--------------------------------------------------------------------------
    | Metrics to Collect
    |--------------------------------------------------------------------------
    |
    | Configure which metrics to collect and expose.
    |
    */

    'metrics' => [
        'request_duration' => true,
        'http_requests_total' => true,
        'db_queries' => true,
        'cache_operations' => true,
        'app_version' => true,
        'app_uptime' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Histogram Buckets (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Define buckets for request latency histogram.
    |
    */

    'request_duration_buckets' => [50, 100, 250, 500, 1000, 2000, 5000, 10000],

    /*
    |--------------------------------------------------------------------------
    | Storage Backend
    |--------------------------------------------------------------------------
    |
    | Where to store metric data (in-memory or file-based).
    |
    */

    'storage' => env('PROMETHEUS_STORAGE', 'memory'),

    'storage_path' => storage_path('metrics'),

    /*
    |--------------------------------------------------------------------------
    | Slow Query Threshold (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Log queries slower than this threshold.
    |
    */

    'slow_query_threshold' => (int) env('PROMETHEUS_SLOW_QUERY_MS', 500),

];
