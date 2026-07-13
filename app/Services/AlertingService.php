<?php declare(strict_types=1);
namespace App\Services;


use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertingService
{
    /**
     * Alert on error threshold.
     */
    public static function alertErrorThreshold(int $errorCount): void
    {
        $threshold = config('alerting.error.threshold');

        if ($errorCount < $threshold) {
            return;
        }

        if (self::isQuietHours()) {
            Log::info('Alert suppressed during quiet hours', [
                'reason' => 'quiet_hours',
                'error_count' => $errorCount,
            ]);

            return;
        }

        $message = sprintf(
            '[ERROR THRESHOLD] %d errors in the last %d minutes (threshold: %d)',
            $errorCount,
            config('alerting.error.window_minutes'),
            $threshold
        );

        self::sendAlert('error', $message, [
            'error_count' => $errorCount,
            'threshold' => $threshold,
            'severity' => 'critical',
        ]);
    }

    /**
     * Alert on slow request latency.
     */
    public static function alertSlowRequests(string $route, int $p95Ms): void
    {
        $threshold = config('alerting.latency.p95_threshold_ms');

        if ($p95Ms < $threshold) {
            return;
        }

        if (self::isQuietHours()) {
            return;
        }

        $message = sprintf(
            '[LATENCY WARNING] Route "%s" P95 latency is %dms (threshold: %dms)',
            $route,
            $p95Ms,
            $threshold
        );

        self::sendAlert('latency', $message, [
            'route' => $route,
            'p95_ms' => $p95Ms,
            'threshold' => $threshold,
            'severity' => 'warning',
        ]);
    }

    /**
     * Alert on slow database queries.
     */
    public static function alertSlowQuery(string $query, int $ms): void
    {
        $threshold = config('alerting.database.slow_query_threshold_ms');

        if ($ms < $threshold) {
            return;
        }

        if (self::isQuietHours()) {
            return;
        }

        // Truncate query for readability
        $queryPreview = substr($query, 0, 100) . (strlen($query) > 100 ? '...' : '');

        $message = sprintf(
            '[SLOW QUERY] Query took %dms (threshold: %dms): %s',
            $ms,
            $threshold,
            $queryPreview
        );

        self::sendAlert('slow_query', $message, [
            'query' => $queryPreview,
            'duration_ms' => $ms,
            'threshold' => $threshold,
            'severity' => 'warning',
        ]);
    }

    /**
     * Alert on poor cache performance.
     */
    public static function alertCacheMissRatio(float $missRatio): void
    {
        $threshold = config('alerting.cache.miss_ratio_threshold');

        if ($missRatio < $threshold) {
            return;
        }

        if (self::isQuietHours()) {
            return;
        }

        $percentage = (int) ($missRatio * 100);

        $message = sprintf(
            '[CACHE ALERT] Cache miss ratio is %d%% (threshold: %d%%)',
            $percentage,
            (int) ($threshold * 100)
        );

        self::sendAlert('cache', $message, [
            'miss_ratio' => $missRatio,
            'threshold' => $threshold,
            'severity' => 'info',
        ]);
    }

    /**
     * Send alert via configured channels.
     *
     * @param array<string, mixed> $context
     */
    public static function sendAlert(string $type, string $message, array $context = []): void
    {
        if (!config('alerting.enabled')) {
            return;
        }

        // Log locally
        Log::warning('Alert triggered', [
            'alert_type' => $type,
            'message' => $message,
            ...$context,
        ]);

        // Check deduplication (prevent spam)
        $dedupeKey = 'alert_' . $type . '_' . md5($message);
        if (Cache::has($dedupeKey)) {
            return; // Already alerted recently
        }

        // Mark as alerted (5 minute cooldown)
        Cache::put($dedupeKey, true, now()->addMinutes(5));

        // Send to Slack
        if (config('alerting.channels.slack.enabled')) {
            self::sendSlackAlert($message, $type, $context);
        }
    }

    /**
     * Send alert to Slack.
     *
     * @param array<string, mixed> $context
     */
    private static function sendSlackAlert(string $message, string $type, array $context): void
    {
        try {
            $severity = $context['severity'] ?? 'warning';
            $color = match ($severity) {
                'critical' => 'danger',
                'warning' => 'warning',
                default => 'good',
            };

            $payload = [
                'channel' => config('alerting.channels.slack.channel'),
                'attachments' => [
                    [
                        'color' => $color,
                        'title' => ucfirst($type) . ' Alert',
                        'text' => $message,
                        'fields' => self::formatContextFields($context),
                        'ts' => time(),
                    ],
                ],
            ];

            $url = config('services.slack.notifications.bot_user_oauth_token')
                ? 'https://slack.com/api/chat.postMessage'
                : '';

            if ($url) {
                Http::withToken(config('services.slack.notifications.bot_user_oauth_token'))
                    ->post($url, $payload);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send Slack alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format context fields for Slack attachment.
     *
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private static function formatContextFields(array $context): array
    {
        unset($context['severity']);

        $fields = [];
        foreach ($context as $key => $value) {
            $fields[] = [
                'title' => ucfirst(str_replace('_', ' ', $key)),
                'value' => is_numeric($value) ? (string) $value : $value,
                'short' => strlen((string) $value) < 50,
            ];
        }

        return $fields;
    }

    /**
     * Check if currently in quiet hours.
     */
    private static function isQuietHours(): bool
    {
        $now = now();
        $start = \Carbon\Carbon::parse(config('alerting.quiet_hours.start'), $now->timezone);
        $end = \Carbon\Carbon::parse(config('alerting.quiet_hours.end'), $now->timezone);

        // If start > end, it's overnight (e.g., 22:00 to 06:00)
        if ($start > $end) {
            return $now >= $start || $now < $end;
        }

        return $now >= $start && $now < $end;
    }
}
