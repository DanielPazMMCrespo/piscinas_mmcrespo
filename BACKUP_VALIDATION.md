# Backup Validation Strategy

**Status:** Automated backup system operational via Railway PostgreSQL.

---

## Backup Architecture

### Automated Backups (Railway)
- **Schedule:** Daily automatic backups
- **Retention:** 30-day rolling window
- **Storage:** Railway's managed PostgreSQL backup system (encrypted, geographically redundant)
- **Restore Time:** ~5-10 minutes for full production database

### Manual Backups
Command available for on-demand backup:
```bash
php artisan backup:database
# Saves to: storage/backups/backup_YYYY-MM-DD_HHmmss.dump
```

---

## Monthly Validation Procedure

### Purpose
Confirm that backups can be successfully restored before a production disaster forces a live recovery.

### Frequency
**1st of every month at 14:00 UTC** (during low-traffic window).

### Step-by-Step Validation

#### Phase 1: Create Test Database (5 min)
On your PostgreSQL server or Railway's test environment:
```bash
# Option A: Railway Test Database (recommended for production)
# In Railway Dashboard:
# 1. Create new test project
# 2. Add PostgreSQL service
# 3. Copy credentials to use below

# Option B: Local PostgreSQL (if testing locally)
# Windows PowerShell:
psql -U postgres -c "CREATE DATABASE piscinas_backup_test;"
```

#### Phase 2: Restore Latest Backup (3-5 min)
```bash
# Download latest backup from Railway
# In Railway Dashboard:
# PostgreSQL → Backups → Download (latest)

# Restore to test database
pg_restore --host=<host> --username=<username> --dbname=piscinas_backup_test \
  --password --clean --if-exists backup_YYYY-MM-DD_HHmmss.dump

# When prompted, enter PostgreSQL password
# Expected output: should see "COMMENT on EXTENSION" + restore logs
```

#### Phase 3: Validate Data Integrity (5 min)
Run these queries on the restored database:

```sql
-- Query 1: Verify all tables exist
SELECT COUNT(*) as table_count FROM information_schema.tables 
WHERE table_schema = 'public' AND table_type = 'BASE TABLE';
-- Expected: 60+ tables

-- Query 2: Sample row counts (should match production)
SELECT 
  'installations' as table_name, COUNT(*) as row_count FROM installations
UNION ALL
SELECT 'pools', COUNT(*) FROM pools
UNION ALL
SELECT 'daily_records', COUNT(*) FROM daily_records
UNION ALL
SELECT 'users', COUNT(*) FROM users
UNION ALL
SELECT 'stock_warehouses', COUNT(*) FROM stock_warehouses;

-- Query 3: Check for referential integrity violations
SELECT constraint_name, table_name FROM information_schema.table_constraints 
WHERE constraint_type = 'FOREIGN KEY' AND table_schema = 'public'
ORDER BY table_name;
-- Expected: No broken FKs (all should be valid)

-- Query 4: Verify latest records (spot-check)
SELECT id, pool_id, registado_em FROM daily_records 
ORDER BY registado_em DESC LIMIT 5;
-- Expected: Records should have recent timestamps (within last 24h)

-- Query 5: Count activities (audit trail)
SELECT COUNT(*) as activity_count FROM activity_log;
-- Expected: Should be > 0 and match production count
```

#### Phase 4: Clean Up Test Database (1 min)
```bash
# After validation passes
dropdb -U postgres piscinas_backup_test
# Confirm with: y
```

---

## Success Criteria

**Backup is considered valid when:**
- [ ] All 60+ tables restore without errors
- [ ] Row counts match production baseline (or expected delta)
- [ ] Foreign key constraints intact (no orphaned records)
- [ ] Latest daily_records timestamps are recent (< 24h old)
- [ ] Activity log restored completely (audit trail intact)

**If validation FAILS:**
1. Download second-most-recent backup and retry
2. Contact Railway support if 2+ consecutive backups fail
3. Document failure with timestamp + error message
4. Escalate to production team

---

## Baseline Row Counts (Production)

Record these during initial setup for future comparison:

| Table | Expected Rows | Date | Notes |
|-------|---------------|------|-------|
| installations | 5 | 2026-06-18 | Leiria, Maceira, Caranguejeira |
| pools | 5 | 2026-06-18 | Competição, Lazer, Infantil, Maceira, Caranguejeira |
| users | 3+ | 2026-06-18 | admin, técnico, nadador_salvador + extras |
| daily_records | ~50-100 | 2026-06-18 | Grows ~20 per pool per month |
| activity_log | ~200+ | 2026-06-18 | Grows with every action |
| stock_warehouses | 1+ | 2026-06-18 | Central warehouse + products |
| incidents | 0+ | 2026-06-18 | Growth variable |

---

## Monthly Validation Checklist

Use this at the start of each month:

```
Date: ___/___/2026
Month: ________________

[ ] Backup file identified (Railway Dashboard)
[ ] Test database created
[ ] Restore command executed successfully
[ ] All 60+ tables present (Query 1)
[ ] Row counts reasonable (Query 2)
[ ] Foreign keys valid (Query 3)
[ ] Latest records recent (Query 4)
[ ] Activity log intact (Query 5)
[ ] Test database cleaned up
[ ] Results documented in log

Issues found: _______________________________________________
Resolution: _______________________________________________
Validated by: _________________________ Date: ___/___/2026
```

---

## Automation (Future Enhancement)

When budget allows, implement:
- GitHub Actions workflow: monthly restore test (triggered 1st of month)
- Slack notification: results to #ops channel
- Metrics: restore time, row count deltas, error logs

---

## Contact & Escalation

**Monthly Validation Owner:** Daniel Paz (daniel.paz@mmcrespo.pt)
**Railway Support:** https://railway.app/support
**Escalation Path:**
1. Retry with previous backup (Day 1 of month if failed)
2. Contact Railway support (within 48 hours)
3. Declare RTO: 24 hours (full restore to new instance)

---

## Database Recovery Runbook (Crisis Procedure)

**If production fails and you need to restore from backup:**

1. Create new PostgreSQL instance on Railway (new project)
2. Download latest working backup
3. Execute restore to new instance (same steps as Phase 2-3 above)
4. Update `.env` with new `DATABASE_URL`
5. Restart application
6. Monitor for 1 hour for anomalies

---

## Documentation Maintenance

**Last Updated:** 2026-06-18
**Next Review:** 2026-07-01
**Version:** 1.0 (Initial)

Update this document when:
- Row count baselines change significantly
- Backup procedure changes
- Railway backup retention changes
- Database schema changes substantially (new tables)
