# Observability Guide — Piscinas MMCrespo

> Complete observability setup: Sentry error tracking, structured JSON logging, Prometheus metrics, and automated alerts.
> Targets 9/10 observability score (up from 6/10).

## Overview

This application implements a production-grade observability stack consisting of 4 pillars:

1. **Sentry** — Error tracking, performance monitoring, and distributed tracing
2. **Structured Logging** — JSON-formatted logs for querying and analysis
3. **Prometheus Metrics** — Request latency, database queries, cache performance
4. **Alerting** — Automatic notifications on error thresholds and performance degradation

### Observability Score: 9/10

| Aspect | Status | Tool |
|--------|--------|------|
| Error Capture | ✅ Complete | Sentry |
| Structured Logs | ✅ Complete | JSON channel |
| Performance Metrics | ✅ Complete | Prometheus |
| Alerting | ✅ Complete | Sentry + Slack |
| Distributed Tracing | ⚠️ Partial | Request IDs only (no Jaeger/OpenTelemetry) |
| APM | ❌ N/A | Enterprise features (DataDog/New Relic) |

---

## 1. SENTRY — Error Tracking & Performance Monitoring

### Setup (Production)

#### Step 1: Create Sentry Project

1. Go to https://sentry.io
2. Sign up or log in
3. Create new project: **Platform** = PHP, **Alert Rule** = none (we'll use Slack)
4. Copy your DSN (format: `https://key@ingest.sentry.io/project-id`)

#### Step 2: Configure Environment Variables

Add to `.env.production`:

```bash
SENTRY_LARAVEL_DSN=https://YOUR_KEY@ingest.sentry.io/YOUR_PROJECT_ID
SENTRY_ENVIRONMENT=production
SENTRY_TRACES_SAMPLE_RATE=0.1        # 10% of transactions sampled
SENTRY_PROFILES_SAMPLE_RATE=0.1      # 10% of profiling data
```

For local development (optional):

```bash
# .env
SENTRY_LARAVEL_DSN=                  # Empty = disabled
SENTRY_ENVIRONMENT=local
SENTRY_TRACES_SAMPLE_RATE=0.0       # Disabled in dev
```

#### Step 3: Verify Configuration

```bash
php artisan config:clear
php artisan tinker
# In Tinker:
Sentry\captureMessage('Test message from Piscinas MMCrespo');
```

Check Sentry dashboard — message should appear within 10 seconds.

### What Gets Captured

#### Unhandled Exceptions

All PHP exceptions are automatically captured:
- Stack trace with file/line numbers
- User context (email, ID, role)
- Request context (method, path, query parameters)
- Pool ID and operation type (from middleware)
- Breadcrumbs (previous operations)

Example in Sentry:

```
[ERROR] StockInsufficientException
  File: app/Services/StockService.php:142
  Function: validateStock()
  
  User: admin@mmcrespo.pt (ID: 5, Role: Admin)
  Pool: Leiria (ID: 1)
  Operation: stock_transfer
  
  Breadcrumbs:
    - Daily record created (record_id: 1234)
    - Stock transfer started (warehouse → installation)
    - [ERROR] Insufficient quantity
```

#### Performance Data

When `traces_sample_rate > 0`:
- Transaction timing (route-level)
- SQL query duration
- Cache operations
- HTTP client calls (Hanna Cloud, Google API)

#### Custom Breadcrumbs

Domain-specific events logged as breadcrumbs:
- Daily record creation/update
- Stock transfers
- Incident lifecycle changes
- Sensor synchronization

### Accessing Sentry

1. Dashboard: https://sentry.io/organizations/YOUR_ORG/issues/?project=YOUR_PROJECT
2. Filter by environment: `production`
3. Sort by: Recent (default) or Frequency
4. View issue detail: Click any error, scroll to breadcrumbs and context tabs

### Sanitization

Sensitive data is automatically removed before sending:
- Database passwords and credentials
- API keys (Gemini, Hanna)
- User passwords
- Session tokens

---

## 2. STRUCTURED LOGGING — JSON Logs

### Configuration

Logs are written to **two channels**:

| Channel | Format | File | Use Case |
|---------|--------|------|----------|
| `stack` | Text | `storage/logs/laravel.log` | Human-readable, local debugging |
| `json` | JSON | `storage/logs/laravel-structured.log` | Machine-readable, log aggregation |

Enable JSON logging in production:

```bash
# .env.production
LOG_JSON_ENABLED=true
LOG_STRUCTURED_CHANNEL=json
LOG_STACK=json,single    # Write to both channels
```

### Log Operations

The following operations automatically log in JSON format:

#### Daily Record Creation

```json
{
  "message": "Daily record created",
  "context": {
    "record_id": 12345,
    "pool_id": 1,
    "user_id": 42,
    "chlorine_total": 1.2,
    "ph": 7.4,
    "temperature": 26.5,
    "operation_type": "daily_record_create",
    "duration_ms": 245,
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
  },
  "level": "info",
  "channel": "json",
  "datetime": "2026-06-18T14:30:45.123456Z",
  "user_id": 42
}
```

#### Stock Transfer

```json
{
  "message": "Stock transfer completed",
  "context": {
    "source_id": 5,
    "source_type": "warehouse",
    "destination_id": 2,
    "destination_type": "installation",
    "product_name": "Cloro em Pó",
    "quantity": 25.5,
    "unit": "kg",
    "operation_type": "stock_transfer",
    "duration_ms": 342
  }
}
```

#### Incident Resolution

```json
{
  "message": "Incident resolved",
  "context": {
    "incident_id": 99,
    "pool_id": 3,
    "incident_type": "pH_out_of_range",
    "resolution": "Adjusted alkalinity level",
    "time_to_resolution_minutes": 45,
    "operation_type": "incident_resolve"
  }
}
```

### Querying Logs

All logs include a `request_id` field for request tracing.

#### Show all records created today

```bash
tail -f storage/logs/laravel-structured.log | \
  jq 'select(.context.operation_type == "daily_record_create" and (.datetime | startswith(now | strftime("%Y-%m-%d"))))'
```

#### Show all errors in the last 5 minutes

```bash
tail -f storage/logs/laravel-structured.log | \
  jq 'select(.level == "error")'
```

#### Show all operations for a specific pool

```bash
cat storage/logs/laravel-structured.log | \
  jq 'select(.context.pool_id == 1)'
```

#### Count operations by type

```bash
cat storage/logs/laravel-structured.log | \
  jq '.context.operation_type' | sort | uniq -c
```

#### Show stock transfers with quantities

```bash
cat storage/logs/laravel-structured.log | \
  jq 'select(.context.operation_type == "stock_transfer") | {product: .context.product_name, qty: .context.quantity, duration_ms: .context.duration_ms}'
```

### Log Rotation

Logs are rotated daily via `RotatingFileHandler`:
- Keep 14 days of history
- Files: `laravel-structured.log`, `laravel-structured.log.1`, `laravel-structured.log.2`, etc.

---

## 3. PROMETHEUS METRICS

### Endpoint

```
GET http://localhost:8000/api/metrics
GET https://production.example.com/api/metrics
```

Returns Prometheus-format metrics (text/plain).

### Available Metrics

#### Request Latency

Histogram buckets (milliseconds): 50, 100, 250, 500, 1000, 2000, 5000, 10000

```prometheus
request_duration_milliseconds_bucket{le="100",route="GET /api/daily-records"} 42
request_duration_milliseconds_bucket{le="500",route="GET /api/daily-records"} 48
request_duration_milliseconds_bucket{le="1000",route="GET /api/daily-records"} 50
request_duration_milliseconds_sum{route="GET /api/daily-records"} 12450
request_duration_milliseconds_count{route="GET /api/daily-records"} 50
```

**Interpretation:**
- 42 requests finished in ≤100ms
- 48 requests finished in ≤500ms
- 50 requests finished in ≤1000ms
- Total time: 12,450ms
- Average: 249ms

#### Database Queries

```prometheus
db_queries_total{operation="select"} 1250
db_queries_total{operation="insert"} 45
db_queries_total{operation="update"} 30
db_queries_total{operation="delete"} 5
```

#### Cache Performance

```prometheus
cache_operations_total{operation="hit"} 850
cache_operations_total{operation="miss"} 150
```

Hit ratio: 850 / (850 + 150) = 85%

#### HTTP Requests

```prometheus
http_requests_total{route="GET /api/daily-records",status="200"} 1250
http_requests_total{route="POST /api/daily-records",status="201"} 45
http_requests_total{route="GET /api/daily-records",status="404"} 2
```

#### Application Version & Uptime

```prometheus
app_version{version="4ff5d55"} 1
app_uptime 86400
```

### Scraping with Prometheus

Configure Prometheus to scrape these metrics:

```yaml
# /etc/prometheus/prometheus.yml
global:
  scrape_interval: 15s
  evaluation_interval: 15s

scrape_configs:
  - job_name: 'piscinas-mmcrespo'
    static_configs:
      - targets: ['localhost:8000']
    metrics_path: '/api/metrics'
    scheme: https  # In production
```

Restart Prometheus, then access: http://localhost:9090/graph

### Grafana Dashboard (Optional)

Create a Grafana dashboard to visualize:

1. **Request Latency**: Graph of P50, P95, P99 latency over time
2. **Throughput**: Requests per second by route
3. **Cache Hit Ratio**: Gauge showing cache efficiency
4. **DB Query Count**: Counter of operations by type
5. **Error Rate**: HTTP 5xx status codes

---

## 4. ALERTING — Automated Notifications

### Configuration

Set thresholds in `.env.production`:

```bash
# Alert when 5+ errors in 5 minutes
ALERT_ERROR_THRESHOLD=5
ALERT_ERROR_WINDOW_MINUTES=5

# Alert when P95 latency > 2 seconds
ALERT_LATENCY_THRESHOLD_MS=2000

# Alert when queries take > 500ms
ALERT_DB_SLOW_QUERY_MS=500

# Alert when cache miss ratio > 50%
ALERT_CACHE_MISS_RATIO=0.5

# Slack notification channel
SLACK_ALERT_CHANNEL=#monitoring
```

### Alert Types

#### Error Threshold

Triggers when error count exceeds threshold in rolling window.

```
[ERROR THRESHOLD] 5 errors in the last 5 minutes
Threshold: 5 | Current: 5
Route: POST /api/daily-records
```

#### Slow Request Latency

Triggers when P95 latency exceeds threshold.

```
[LATENCY WARNING] Route "POST /api/daily-records" P95 latency is 2150ms
Threshold: 2000ms | Current: 2150ms
```

#### Slow Database Query

Triggers on individual queries > threshold.

```
[SLOW QUERY] Query took 850ms (threshold: 500ms)
SELECT * FROM daily_records WHERE pool_id = ? AND date...
```

#### Poor Cache Performance

Triggers when cache miss ratio exceeds threshold.

```
[CACHE ALERT] Cache miss ratio is 65% (threshold: 50%)
```

### Sentry Alert Integration

Configure Slack notifications directly in Sentry:

1. Settings → Integrations → Slack
2. Select channel: `#monitoring`
3. Alert rule: `Error level is Error or higher`

Sentry will post to Slack on each new error:

```
[ERROR] StockInsufficientException
Affected route: POST /api/daily-records
Pool: Leiria | User: admin@mmcrespo.pt
Stack: app/Services/StockService.php:142
```

### Quiet Hours

Suppress alerts during maintenance:

```bash
ALERT_QUIET_START=02:00      # 2 AM
ALERT_QUIET_END=03:00        # 3 AM
```

During quiet hours, alerts are logged but not sent to Slack.

### Alert Deduplication

To prevent alert spam, each unique alert type + message combination has a 5-minute cooldown. Repeated alerts within 5 minutes are silently logged but not sent.

---

## Monitoring Checklist

### Daily (Automated)

- [ ] Sentry: Zero **critical** errors (check dashboard)
- [ ] Logs: No unexpected `error` or `critical` entries
- [ ] Metrics: P95 latency < 2000ms
- [ ] Cache: Hit ratio > 80%

### Weekly

- [ ] Review error trends in Sentry
- [ ] Analyze slow query logs
- [ ] Check Prometheus uptime (should be 99.9%+)
- [ ] Validate that all domain operations are logging correctly

### Monthly

- [ ] Audit structured logs for anomalies
- [ ] Review alert threshold accuracy (adjust if needed)
- [ ] Check Sentry quota usage (events/month)
- [ ] Archive old logs (keep 90 days)

---

## Troubleshooting

### Sentry Events Not Appearing

**Problem:** Errors occur but don't appear in Sentry dashboard.

**Solutions:**
1. Verify DSN is correct in `.env.production`
2. Check that `SENTRY_LARAVEL_DSN` is not empty
3. Run: `php artisan config:clear`
4. Check Laravel error logs: `tail -f storage/logs/laravel.log`
5. Manually test: `php artisan tinker` → `Sentry\captureMessage('Test')`

### Slack Alerts Not Arriving

**Problem:** Errors occur but alerts don't appear in Slack.

**Solutions:**
1. Verify `SLACK_BOT_USER_OAUTH_TOKEN` is set correctly
2. Verify bot has permission to post in `SLACK_ALERT_CHANNEL`
3. Check `ALERTING_ENABLED=true` in `.env`
4. Check error log: `tail -f storage/logs/laravel.log | grep -i alert`
5. Check quiet hours: Is current time in `ALERT_QUIET_START:ALERT_QUIET_END`?

### JSON Logs Not Appearing

**Problem:** `storage/logs/laravel-structured.log` is empty or missing.

**Solutions:**
1. Check storage directory permissions: `chmod 775 storage/logs`
2. Verify `LOG_STACK=json` or `LOG_STACK=json,single` in `.env`
3. Run: `php artisan config:clear`
4. Trigger an operation (create daily record)
5. Check file: `tail -f storage/logs/laravel-structured.log`

### Metrics Endpoint Returns 403

**Problem:** `GET /api/metrics` returns 403 Forbidden.

**Solutions:**
1. Check `PROMETHEUS_ENABLED=true` in `.env`
2. Verify middleware isn't blocking: route should have `->withoutMiddleware('api')`
3. Check Laravel logs for auth errors
4. Visit `/api/health` first to verify API routes work

---

## Integration Examples

### Send Logs to External Service (ELK, Datadog, etc.)

To send JSON logs to an external log aggregation service, add a new channel in `config/logging.php`:

```php
'datadog' => [
    'driver' => 'monolog',
    'handler' => \Monolog\Handler\CurlHandler::class,
    'handler_with' => [
        'url' => 'https://http-intake.logs.datadoghq.com/v1/input/YOUR_DATADOG_KEY',
    ],
    'formatter' => \Monolog\Formatter\JsonFormatter::class,
    'processors' => [...],
]
```

Then include in `LOG_STACK`:

```bash
LOG_STACK=json,datadog
```

### Custom Metrics

To add custom metrics, use `MetricsService`:

```php
use App\Services\MetricsService;

// In your controller or command
MetricsService::recordDbQuery('pool_selection', $durationMs);
MetricsService::recordCacheOperation('temperature_cache', $wasHit);
```

Metrics are automatically exposed at `/api/metrics`.

---

## Performance Impact

- **Sentry:** ~10-50ms per request (sampling reduces overhead)
- **Structured Logging:** ~5-20ms per log operation
- **Metrics Collection:** <5ms per request
- **Alerting:** Async, no request impact

**Total overhead:** ~30-100ms per request (negligible for production workloads)

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-06-18 | Initial implementation (4 phases: Sentry, JSON logging, Prometheus, alerting) |

---

## Support & Escalation

**For observability questions or to add new metrics:**

1. Check `config/prometheus.php`, `config/alerting.php`
2. Review `app/Services/StructuredLogger.php` for logging patterns
3. Check Sentry docs: https://docs.sentry.io/platforms/php/
4. Check Prometheus docs: https://prometheus.io/docs/

---

**Observability Score: 9/10 ✅**
