# Slow Query Monitoring

**Status:** PostgreSQL slow query logging configured for production (Railway).

---

## Architecture

Railway PostgreSQL automatically logs queries exceeding the configured threshold. No application code changes needed.

### Configuration

**Threshold:** `log_min_duration_statement = 1000` (1 second)
- Queries taking >1s are automatically logged
- Includes query text, execution time, rows affected
- Available in Railway PostgreSQL logs

---

## Critical Queries to Monitor

These 5 queries are performance-critical for daily operation. Monitor their execution plans quarterly.

### Query 1: Dashboard Latest Records (per Pool)
**Purpose:** Fetch latest daily record for each pool on dashboard load.
**Execution:**
```sql
SELECT DISTINCT ON (pool_id)
  dr.id, dr.pool_id, dr.ph, dr.cloro_total, dr.registado_em
FROM daily_records dr
WHERE dr.e_correcao = false
ORDER BY dr.pool_id, dr.registado_em DESC;
```

**Expected Plan:** Uses index `daily_records_pool_id_registado_em_index` (Index Scan, not Seq Scan)
**Typical Duration:** <100ms
**Alert Threshold:** >500ms (indicates table bloat or missing index)

---

### Query 2: Daily Records List (Paginated)
**Purpose:** Fetch last 14 days of records for technician review (DailyRecordResource table).
**Execution:**
```sql
SELECT dr.* FROM daily_records dr
WHERE dr.pool_id = $1
  AND dr.registado_em >= NOW() - INTERVAL '14 days'
  AND dr.e_correcao = false
ORDER BY dr.registado_em DESC
LIMIT 50 OFFSET 0;
```

**Expected Plan:** Uses index `daily_records_pool_id_registado_em_index` (Index Scan)
**Typical Duration:** <150ms
**Alert Threshold:** >1s (indicates high volume or missing filter)

---

### Query 3: Stock Availability Check
**Purpose:** Verify stock levels before recording chemical additions (prevents oversell).
**Execution:**
```sql
SELECT si.quantity
FROM stock_installations si
WHERE si.installation_id = $1 AND si.product_id = $2
FOR UPDATE;  -- Lock for concurrent modifications
```

**Expected Plan:** Uses index on `stock_installations(installation_id, product_id)` (Index Scan)
**Typical Duration:** <50ms
**Alert Threshold:** >300ms (indicates lock contention, check concurrent operations)

---

### Query 4: Incident Timeline (Status Filter)
**Purpose:** Fetch unresolved incidents for Kanban board (QuadroOperacionalWidget).
**Execution:**
```sql
SELECT i.* FROM incidents i
WHERE i.status = 'aberto'
  AND i.ocorreu_em >= NOW() - INTERVAL '30 days'
ORDER BY i.ocorreu_em DESC;
```

**Expected Plan:** Uses index `incidents_status_ocorreu_em_index` (Index Scan)
**Typical Duration:** <100ms
**Alert Threshold:** >500ms (indicates resolution backlog or missing cleanup)

---

### Query 5: Sensor Readings Time-Series
**Purpose:** Fetch Hanna sensor data for pool chart rendering (SensoresHannaWidget).
**Execution:**
```sql
SELECT sr.* FROM sensor_readings sr
WHERE sr.pool_id = $1
  AND sr.created_at >= NOW() - INTERVAL '7 days'
ORDER BY sr.created_at DESC;
```

**Expected Plan:** Uses index on `sensor_readings(pool_id, created_at)` (Index Scan)
**Typical Duration:** <200ms
**Alert Threshold:** >1s (indicates sensor data volume spike)

---

## Accessing Slow Queries

### Via Railway Dashboard

1. Go to: Railway Dashboard → Your Project → PostgreSQL
2. Click "Logs" tab
3. Filter by duration (e.g., `> 1000ms`)
4. Export or view directly

### Via psql (Direct Database Access)

If you have database credentials:

```bash
# Connect to production database
psql $DATABASE_URL

# View slow queries logged in current session
SELECT query, duration, calls
FROM pg_stat_statements
WHERE duration > 1000
ORDER BY duration DESC
LIMIT 10;

# View slow queries by table
SELECT query, calls, mean_time
FROM pg_stat_statements
WHERE query LIKE '%daily_records%'
ORDER BY mean_time DESC;
```

---

## Slow Query Response Procedure

**When a slow query is detected:**

1. **Identify** (from slow query log)
   - Which query is slow
   - Execution time (threshold: 1s)
   - Affected table
   - Sample query text

2. **Analyze** (with EXPLAIN ANALYZE)
   ```sql
   EXPLAIN ANALYZE <query text from log>;
   ```
   Look for:
   - `Seq Scan` (bad — full table scan instead of index)
   - `Filter` on a non-indexed column (consider adding index)
   - High `Actual Rows` (data volume spike)

3. **Remediate** (choose one)
   - **Missing Index:** Create index on filtered column (file migration)
   - **Poor Query:** Rewrite query to use existing indexes
   - **Data Volume:** Archive old records (monthly `archive:daily-records` command)
   - **Lock Contention:** Reduce transaction duration (check stock operations)

4. **Verify**
   - Re-run EXPLAIN ANALYZE after fix
   - Confirm `Index Scan` in plan (not `Seq Scan`)
   - Monitor for 1 week

5. **Document**
   - Add to SLOW_QUERY_LOG.txt with date, query, root cause, fix
   - Update this document if pattern emerges

---

## Baseline Metrics

Record these during initial production setup:

| Query | Typical Duration | Threshold | Last Checked |
|-------|------------------|-----------|--------------|
| Dashboard latest | <100ms | >500ms | 2026-06-18 |
| Daily records list | <150ms | >1s | 2026-06-18 |
| Stock check | <50ms | >300ms | 2026-06-18 |
| Incident timeline | <100ms | >500ms | 2026-06-18 |
| Sensor readings | <200ms | >1s | 2026-06-18 |

---

## Quarterly Review Checklist

**1st of every quarter (Jan, Apr, Jul, Oct):**

- [ ] Check Railway logs for slow queries in last 3 months
- [ ] Run EXPLAIN ANALYZE on 5 critical queries
- [ ] Verify all indexes are being used (no full table scans)
- [ ] Compare durations to baseline above
- [ ] Archive records older than 365 days (if not automated)
- [ ] Note any new slow queries in SLOW_QUERY_LOG.txt
- [ ] Update baseline metrics above if significant changes

---

## PostgreSQL Configuration (Railway Default)

```postgresql
-- Already configured on Railway (no action needed)
log_min_duration_statement = 1000          -- Log queries > 1 second
log_statement = 'none'                     -- Don't log all statements (too noisy)
log_connections = on                       -- Log connections
log_disconnections = on                    -- Log disconnections
log_lock_waits = on                        -- Log lock contention
max_connections = 100                      -- Sufficient for production
shared_buffers = 262MB                     -- Railway default
work_mem = 4MB                             -- Per-operation memory
```

If Railway config changes, contact Railway support for query log extraction.

---

## Common Slow Query Patterns

### Pattern 1: Missing Index
**Symptom:** Seq Scan on large table
**Fix:** Create composite index on WHERE clause columns
```sql
CREATE INDEX idx_table_filter ON table_name(column1, column2);
```

### Pattern 2: N+1 Queries
**Symptom:** Thousands of short queries from loop
**Fix:** Use Eloquent eager loading (`with()`) or single query with JOIN
```php
// Bad
foreach ($pools as $pool) {
    $records = $pool->dailyRecords; // Query per loop iteration
}

// Good
$pools->load('dailyRecords'); // Single query with JOIN
```

### Pattern 3: Unbounded Table
**Symptom:** Query on large table without date filter
**Fix:** Add DATE filter or archive old records
```sql
-- Slow
SELECT * FROM daily_records WHERE pool_id = $1;

-- Fast
SELECT * FROM daily_records 
WHERE pool_id = $1 
  AND registado_em >= NOW() - INTERVAL '90 days';
```

### Pattern 4: Lock Contention
**Symptom:** Query waits long time in log_lock_waits
**Fix:** Reduce transaction duration or add index to lock column
```sql
-- Use SELECT ... FOR UPDATE on indexed column only
SELECT * FROM stock_installations 
WHERE id = $1 FOR UPDATE;
```

---

## Documentation Maintenance

**Last Updated:** 2026-06-18
**Next Review:** 2026-09-18 (Q3 quarterly review)
**Version:** 1.0 (Initial)

Update when:
- New slow queries emerge (pattern documents)
- Index changes (add Query section)
- Alert threshold changes (update baseline)
- Quarterly review detects drift (update metrics)
