<?php declare(strict_types=1);
namespace App\Services;


use Illuminate\Support\Collection;

class MetricsService
{
    /**
     * @var array<string, int|float>
     */
    private static array $metrics = [
        'request_count' => 0,
        'request_latency_sum' => 0,
        'request_latency_count' => 0,
        'db_query_count' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'sentry_events' => 0,
    ];

    /**
     * @var array<string, array<string, int|float>>
     */
    private static array $histograms = [
        'request_latency' => [],
        'db_query_duration' => [],
    ];

    /**
     * @var array<string, array<string, int>>
     */
    private static array $counters = [
        'http_requests_total' => [],
        'http_status_codes' => [],
        'db_operations' => [],
    ];

    /**
     * Record request latency.
     */
    public static function recordRequestLatency(string $route, int $milliseconds, int $statusCode): void
    {
        self::$metrics['request_count']++;
        self::$metrics['request_latency_sum'] += $milliseconds;
        self::$metrics['request_latency_count']++;

        $key = $route . '_' . $statusCode;
        if (!isset(self::$histograms['request_latency'][$key])) {
            self::$histograms['request_latency'][$key] = [];
        }
        self::$histograms['request_latency'][$key][] = $milliseconds;

        $counterKey = $route . '_' . $statusCode;
        self::$counters['http_requests_total'][$counterKey] = (self::$counters['http_requests_total'][$counterKey] ?? 0) + 1;
    }

    /**
     * Record database query.
     */
    public static function recordDbQuery(string $operation, int $milliseconds): void
    {
        self::$metrics['db_query_count']++;

        if (!isset(self::$histograms['db_query_duration'][$operation])) {
            self::$histograms['db_query_duration'][$operation] = [];
        }
        self::$histograms['db_query_duration'][$operation][] = $milliseconds;

        self::$counters['db_operations'][$operation] = (self::$counters['db_operations'][$operation] ?? 0) + 1;
    }

    /**
     * Record cache operation.
     */
    public static function recordCacheOperation(string $operation, bool $hit): void
    {
        if ($hit) {
            self::$metrics['cache_hits']++;
        } else {
            self::$metrics['cache_misses']++;
        }
    }

    /**
     * Record Sentry event.
     */
    public static function recordSentryEvent(string $level): void
    {
        self::$metrics['sentry_events']++;
    }

    /**
     * Calculate average latency.
     */
    public static function getAverageLatency(): float
    {
        if (self::$metrics['request_latency_count'] === 0) {
            return 0;
        }

        return self::$metrics['request_latency_sum'] / self::$metrics['request_latency_count'];
    }

    /**
     * Calculate percentile latency.
     */
    public static function getPercentileLatency(float $percentile = 95): int
    {
        if (empty(self::$histograms['request_latency'])) {
            return 0;
        }

        $all = [];
        foreach (self::$histograms['request_latency'] as $values) {
            $all = array_merge($all, $values);
        }

        if (empty($all)) {
            return 0;
        }

        sort($all);
        $position = (int) (count($all) * ($percentile / 100));

        return $all[$position] ?? 0;
    }

    /**
     * Get cache hit ratio.
     */
    public static function getCacheHitRatio(): float
    {
        $total = self::$metrics['cache_hits'] + self::$metrics['cache_misses'];

        if ($total === 0) {
            return 0;
        }

        return self::$metrics['cache_hits'] / $total;
    }

    /**
     * Expose metrics in Prometheus text format.
     */
    public static function expose(): string
    {
        $output = "# HELP request_duration_milliseconds HTTP request latency\n";
        $output .= "# TYPE request_duration_milliseconds histogram\n";

        // Request latency histogram buckets
        foreach (self::$histograms['request_latency'] as $key => $values) {
            $buckets = [50, 100, 250, 500, 1000, 2000, 5000, 10000, \INF];

            foreach ($buckets as $bucket) {
                $count = count(array_filter($values, fn ($v) => $v <= $bucket));
                $output .= "request_duration_milliseconds_bucket{le=\"{$bucket}\",route=\"{$key}\"} {$count}\n";
            }

            $output .= "request_duration_milliseconds_sum{route=\"{$key}\"} " . array_sum($values) . "\n";
            $output .= "request_duration_milliseconds_count{route=\"{$key}\"} " . count($values) . "\n";
        }

        $output .= "\n# HELP http_requests_total HTTP request count\n";
        $output .= "# TYPE http_requests_total counter\n";

        foreach (self::$counters['http_requests_total'] as $key => $count) {
            $output .= "http_requests_total{route=\"{$key}\"} {$count}\n";
        }

        $output .= "\n# HELP db_queries_total Database query count\n";
        $output .= "# TYPE db_queries_total counter\n";

        foreach (self::$counters['db_operations'] as $operation => $count) {
            $output .= "db_queries_total{operation=\"{$operation}\"} {$count}\n";
        }

        $output .= "\n# HELP cache_operations_total Cache operations\n";
        $output .= "# TYPE cache_operations_total counter\n";
        $output .= "cache_operations_total{operation=\"hit\"} " . self::$metrics['cache_hits'] . "\n";
        $output .= "cache_operations_total{operation=\"miss\"} " . self::$metrics['cache_misses'] . "\n";

        $output .= "\n# HELP app_version Application version (git commit)\n";
        $output .= "# TYPE app_version gauge\n";
        $version = $this->getAppVersion();
        $output .= "app_version{version=\"{$version}\"} 1\n";

        $output .= "\n# HELP app_uptime Application uptime in seconds\n";
        $output .= "# TYPE app_uptime gauge\n";
        $output .= "app_uptime " . intval(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) . "\n";

        return $output;
    }

    /**
     * Get application version from git commit hash.
     */
    private static function getAppVersion(): string
    {
        try {
            $hash = trim(shell_exec('git rev-parse --short HEAD') ?? '');

            return $hash ?: 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
