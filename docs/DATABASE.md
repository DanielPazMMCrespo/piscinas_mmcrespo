# Database Documentation

Arquitetura, schema, e estratégia de dados em Piscinas MMCrespo.

---

## 🗄️ Overview

| Aspecto | Dev | Prod |
|--------|-----|------|
| **Engine** | SQLite | PostgreSQL 16 |
| **File** | `database.sqlite` | Managed by Railway |
| **Backup** | Manual | Railway snapshots |
| **Scaling** | Single file | Connection pooling |

---

## 📊 Entity Relationship Diagram (ERD)

```
┌─────────────┐
│   users     │
├─────────────┤
│ id          │
│ name        │───────┐
│ email       │       │ (belongs_to_many)
│ password    │       │
│ role_id     │       │
└─────────────┘       │
       │              │
       └──────────────┤
                      │
┌──────────────┐      │
│    pools     │      │
├──────────────┤      │
│ id           │      │
│ installation_id│    │
│ nome         │      │
│ volume_m3    │      │
│ temp_min     │      │
│ temp_max     │      │
└──────────────┘      │
       │              │
       │ (has_many)   │
       │              │
┌──────────────────────────────┐
│   daily_records              │
├──────────────────────────────┤
│ id                           │
│ pool_id ────────────────────→│
│ ph                           │
│ cloro_total                  │
│ cloro_livre                  │
│ temperatura                  │
│ turbidez_fnu                 │
│ acao_corretiva               │
│ e_correcao                   │
│ corrige_registo_id ──┐       │
│ registado_por ───────┼──────→│
│ registado_em         │       │
│ created_at           │       │
│ updated_at           │       │
└──────────────────────┼──────┘
                       │
                       └─────── (self-ref FK)

┌─────────────────────┐
│   incidents         │
├─────────────────────┤
│ id                  │
│ pool_id ───────────→│
│ titulo              │
│ descricao           │
│ status              │
│ criado_por ────────→│
│ criado_em           │
│ resolvido_por ─────→│
│ resolvido_em        │
│ resolucao           │
└─────────────────────┘

┌──────────────────────────┐
│   sensor_readings        │
├──────────────────────────┤
│ id                       │
│ pool_id ───────────────→│
│ device_id                │
│ device_name              │
│ leitura_ph               │
│ leitura_orp_mv           │
│ leitura_temp             │
│ lido_em                  │
│ created_at               │
└──────────────────────────┘

┌──────────────────────────┐
│   stock_warehouse        │
├──────────────────────────┤
│ id                       │
│ product_id ────────────→│
│ quantidade               │
│ tipo (entrada/saida)     │
│ fornecedor               │
│ registado_por ─────────→│
│ registado_em             │
└──────────────────────────┘

┌──────────────────────────┐
│   stock_installation     │
├──────────────────────────┤
│ id                       │
│ installation_id        │
│ product_id ────────────→│
│ quantidade               │
│ last_updated             │
└──────────────────────────┘

┌──────────────────────────┐
│   products               │
├──────────────────────────┤
│ id                       │
│ nome                     │
│ unidade (kg, L, etc)     │
│ desc                     │
└──────────────────────────┘

┌──────────────────────────┐
│   activity_logs          │
├──────────────────────────┤
│ id                       │
│ description              │
│ subject_type             │
│ subject_id               │
│ user_id ───────────────→│
│ properties (JSON)        │
│ created_at               │
└──────────────────────────┘
```

---

## 🔑 Tabelas Principais

### users

```sql
CREATE TABLE users (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255),
    email VARCHAR(255) UNIQUE,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255),
    remember_token VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL  -- Soft delete
);

-- Índices:
CREATE INDEX idx_users_email ON users(email);
```

### pools

```sql
CREATE TABLE pools (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    installation_id BIGINT UNSIGNED,
    nome VARCHAR(255) NOT NULL,
    volume_m3 DECIMAL(8,2),
    temp_min DECIMAL(4,2) DEFAULT 26,
    temp_max DECIMAL(4,2) DEFAULT 30,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (installation_id) REFERENCES installations(id)
);

-- Índices:
CREATE INDEX idx_pools_installation ON pools(installation_id);
```

### daily_records

**Mais importante — conformidade CN 14/DA**

```sql
CREATE TABLE daily_records (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    pool_id BIGINT UNSIGNED NOT NULL,
    registado_por BIGINT UNSIGNED NOT NULL,
    
    -- Parâmetros água:
    ph DECIMAL(4,2),
    cloro_total DECIMAL(4,2),
    cloro_livre DECIMAL(4,2),
    cloro_combinado DECIMAL(4,2),  -- Calculado: total - livre
    temperatura DECIMAL(4,2),
    turbidez_fnu DECIMAL(5,2),
    
    -- Observações técnico:
    bomba_status VARCHAR(50),  -- on, off, anomalia
    agua_modo VARCHAR(50),     -- on_com_agua, off, etc
    tanque_estado VARCHAR(50), -- normal, baixo, critico
    tanque_observacoes TEXT,
    observacoes_gerais TEXT,
    
    -- Ação corretiva:
    acao_corretiva TEXT,
    
    -- Fotos (evidência):
    bomba_foto VARCHAR(255),   -- storage/uploads/...
    tanque_foto VARCHAR(255),
    
    -- Append-only pattern:
    e_correcao BOOLEAN DEFAULT false,
    corrige_registo_id BIGINT UNSIGNED,
    razao_correcao TEXT,
    
    registado_em TIMESTAMP NOT NULL,  -- Data/hora do registo (não created_at!)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (pool_id) REFERENCES pools(id) ON DELETE CASCADE,
    FOREIGN KEY (registado_por) REFERENCES users(id),
    FOREIGN KEY (corrige_registo_id) REFERENCES daily_records(id) ON DELETE RESTRICT
);

-- Índices (performance crítica):
CREATE INDEX idx_daily_records_pool_date ON daily_records(pool_id, registado_em DESC);
CREATE INDEX idx_daily_records_correcao ON daily_records(e_correcao) WHERE e_correcao = false;
CREATE INDEX idx_daily_records_registado_por ON daily_records(registado_por);
```

### sensor_readings

```sql
CREATE TABLE sensor_readings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    pool_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(255),
    device_name VARCHAR(255),
    
    leitura_ph DECIMAL(4,2),
    leitura_orp_mv INT,
    leitura_temp DECIMAL(4,2),
    
    lido_em TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (pool_id) REFERENCES pools(id) ON DELETE CASCADE
);

-- Índices:
CREATE INDEX idx_sensor_readings_pool_date ON sensor_readings(pool_id, lido_em DESC);
```

### incidents

```sql
CREATE TABLE incidents (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    pool_id BIGINT UNSIGNED NOT NULL,
    titulo VARCHAR(255),
    descricao TEXT,
    status VARCHAR(50) DEFAULT 'aberto',  -- aberto, resolvido
    
    criado_por BIGINT UNSIGNED NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    resolvido_por BIGINT UNSIGNED,
    resolvido_em TIMESTAMP NULL,
    resolucao TEXT,
    
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (pool_id) REFERENCES pools(id) ON DELETE CASCADE,
    FOREIGN KEY (criado_por) REFERENCES users(id),
    FOREIGN KEY (resolvido_por) REFERENCES users(id)
);

-- Índices:
CREATE INDEX idx_incidents_pool_status ON incidents(pool_id, status);
CREATE INDEX idx_incidents_criado_em ON incidents(criado_em DESC);
```

### stock_warehouse & stock_installation

```sql
CREATE TABLE stock_warehouse (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    quantidade DECIMAL(8,2) NOT NULL,
    tipo ENUM('entrada', 'saida') NOT NULL,
    fornecedor VARCHAR(255),
    registado_por BIGINT UNSIGNED NOT NULL,
    registado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (registado_por) REFERENCES users(id)
);

CREATE TABLE stock_installation (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    installation_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantidade DECIMAL(8,2) NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (installation_id) REFERENCES installations(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    UNIQUE KEY uk_installation_product (installation_id, product_id)
);
```

---

## 🔍 Query Patterns

### Pattern 1: Últimas Leituras (sem Correções)

```sql
-- Dashboard piscina:
SELECT DISTINCT ON (pool_id)
    pool_id, ph, cloro_total, temperatura, registado_em
FROM daily_records
WHERE e_correcao = false
ORDER BY pool_id, registado_em DESC;
```

### Pattern 2: Gráfico pH (ultimos 30 dias)

```sql
SELECT registado_em, ph
FROM daily_records
WHERE pool_id = ?
  AND e_correcao = false
  AND registado_em >= NOW() - INTERVAL '30 days'
ORDER BY registado_em;
```

### Pattern 3: Conformidade CN 14/DA

```sql
SELECT COUNT(CASE WHEN ph BETWEEN 6.8 AND 8.0 THEN 1 END) as conformes,
       COUNT(CASE WHEN ph NOT BETWEEN 6.8 AND 8.0 THEN 1 END) as nao_conformes
FROM daily_records
WHERE pool_id = ? AND e_correcao = false;
```

### Pattern 4: Auditoria (Activity Log)

```sql
SELECT user_id, description, subject_type, created_at
FROM activity_logs
WHERE subject_type = 'DailyRecord'
  AND subject_id = ?
ORDER BY created_at DESC;
```

---

## 🔄 Migrations

### Estrutura Pasta

```
database/migrations/
├── 2014_10_12_000000_create_users_table.php
├── 2014_10_12_100000_create_password_reset_tokens_table.php
├── 2019_12_14_000001_create_personal_access_tokens_table.php
├── 2024_01_01_000000_create_pools_table.php
├── 2024_01_02_000000_create_daily_records_table.php
├── 2024_01_03_000000_create_incidents_table.php
├── 2024_01_04_000000_create_sensor_readings_table.php
├── 2024_01_05_000000_create_stock_warehouse_table.php
├── 2024_01_06_000000_create_stock_installation_table.php
├── 2026_06_10_000001_add_append_only_to_daily_records.php
├── 2026_06_12_000001_clean_dead_ai_columns.php
├── 2026_06_15_000001_restore_jobs_table.php  (idempotent)
└── 2026_06_15_000002_add_fotos_to_daily_records.php
```

### Executar

```bash
# Criar/atualizar:
php artisan migrate

# Ver status:
php artisan migrate:status

# Rollback último lote:
php artisan migrate:rollback

# Rollback tudo (⚠️ perde dados):
php artisan migrate:reset

# Fresh (drop + migrate):
php artisan migrate:fresh --seed
```

---

## 📈 Data Volume & Retention

### Estimativa

| Tabela | Registos/mês | Tamanho |
|--------|---|---|
| `daily_records` | ~1,000 | ~10MB |
| `sensor_readings` | ~40,000 | ~8MB |
| `activity_logs` | ~5,000 | ~5MB |
| `incidents` | ~50 | ~1MB |

**Total anual:** ~300MB (crescimento linear)

### Política de Retenção

```
daily_records      → Indefinido (compliance CN 14/DA)
sensor_readings    → 90 dias (after: delete old via cron)
activity_logs      → 1 ano (after: archive)
incidents          → Indefinido
```

### Cleanup Cron Job

```php
// In routes/console.php:
Schedule::call(function () {
    SensorReading::where('created_at', '<', now()->subDays(90))
        ->delete();
    
    ActivityLog::where('created_at', '<', now()->subYear())
        ->delete();
})->daily()->at('02:00');  # 2AM daily
```

---

## 🔐 Backup & Recovery

### Railway Automático

```
Railway → Project → Settings → Backups
├─ Daily snapshots (automático)
├─ Retention: 30 dias
├─ Restore: 1-click via dashboard
└─ Download: SQL export
```

### Manual (Local)

```bash
# SQLite:
cp database.sqlite database.sqlite.backup.$(date +%Y%m%d)

# PostgreSQL (via Heroku/Railway):
pg_dump $DATABASE_URL > backup.sql
# Restore:
psql $DATABASE_URL < backup.sql
```

### Teste Recovery

```bash
# 1. Criar nova instância (staging)
# 2. Restaurar backup
# 3. Validar integridade:
php artisan migrate:status
php artisan tinker
> DailyRecord::count()
> Pool::count()
```

---

## 📊 Performance Tuning

### Índices Críticos

```sql
-- JÁ CRIADOS:
CREATE INDEX idx_daily_records_pool_date 
  ON daily_records(pool_id, registado_em DESC);

CREATE INDEX idx_daily_records_correcao 
  ON daily_records(e_correcao) 
  WHERE e_correcao = false;  -- Partial index

-- TODO (se performance degrade):
CREATE INDEX idx_sensor_readings_pool_lido
  ON sensor_readings(pool_id, lido_em DESC);

CREATE INDEX idx_incidents_pool_status
  ON incidents(pool_id, status);
```

### Verificar Índices (PostgreSQL)

```bash
psql $DATABASE_URL -c "\d+ daily_records"  # Ver índices
psql $DATABASE_URL -c "SELECT * FROM pg_stat_user_indexes;"  # Uso
```

### Vacuuming (PostgreSQL)

```bash
# Manual (Railway automático):
psql $DATABASE_URL -c "VACUUM ANALYZE;"
```

---

## 📋 Constraints & Referential Integrity

### Foreign Keys

```sql
-- soft delete protection:
ALTER TABLE daily_records
ADD CONSTRAINT fk_corrige_registo
FOREIGN KEY (corrige_registo_id)
REFERENCES daily_records(id)
ON DELETE RESTRICT;  -- Impede apagar se tem correções

-- cascade delete (incidents):
ALTER TABLE incidents
ADD CONSTRAINT fk_incidents_pool
FOREIGN KEY (pool_id)
REFERENCES pools(id)
ON DELETE CASCADE;  -- Apagar piscina → apaga incidentes
```

### Unique Constraints

```sql
CREATE UNIQUE INDEX uk_users_email ON users(email);
CREATE UNIQUE INDEX uk_stock_installation ON stock_installation(installation_id, product_id);
```

---

## 🔄 Replicação & High Availability

### Current (Railway Single Node)

```
┌─────────────────┐
│   PostgreSQL    │
│   (railway)     │
│   Primary       │
└─────────────────┘
```

### Future (HA Setup)

```
┌──────────────┐     ┌──────────────┐
│   Primary    │────→│   Standby    │
│   (write)    │     │   (read)     │
└──────────────┘     └──────────────┘
                            ↓
                      Auto-failover
```

---

## 📚 Referências

- [PostgreSQL Docs](https://www.postgresql.org/docs/16/)
- [Laravel Migrations](https://laravel.com/docs/12/migrations)
- [Railway Backup](https://docs.railway.app/databases/postgresql)

---

**Última atualização:** 2026-06-18
