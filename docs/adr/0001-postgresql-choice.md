# ADR 0001: PostgreSQL para Produção (não SQLite)

**Data:** 2026-06-15  
**Decisor:** Daniel Paz  
**Status:** ACCEPTED  

---

## 🎯 Contexto

Piscinas MMCrespo é uma aplicação de gestão operacional para piscinas públicas. O projeto começou com **SQLite** em desenvolvimento e agora vai para **produção em Railway**.

### Requisitos Não-Funcionais

1. **Concorrência:** 5-50 utilizadores simultâneos (técnicos + admin)
2. **Durabilidade:** Dados críticos (conformidade CN 14/DA)
3. **Backup automático:** Obrigatório legalmente
4. **Escalabilidade:** Potencial crescimento múltiplas instalações
5. **Monitoramento:** Logs e métricas de produção

### Problema com SQLite

SQLite é excelente para dev/protótipo mas inadequado para produção porque:

| Aspecto | SQLite | PostgreSQL |
|--------|--------|-----------|
| **Concorrência** | Locks por file inteiro | Row-level locks + MVCC |
| **Escrita simultânea** | 1 por vez (timeout se 2+) | N em paralelo |
| **Backup** | Manual (copiar arquivo) | Replicação automática |
| **Backup em Railway** | Não integrado | Nativo + snapshots |
| **Monitoring** | Nenhum | Logs, métricas, EXPLAIN |
| **Índices avançados** | Básicos | Compósitos, parcelares, GiST |

---

## 🏗️ Decisão

**Usar PostgreSQL 16 em produção (Railway).**

Manter SQLite para desenvolvimento local (zero setup).

### Arquitetura

```
├─ Dev (local)
│  ├─ database.sqlite (file-based)
│  └─ Setup: php artisan migrate
│
└─ Prod (Railway)
   ├─ PostgreSQL 16 (managed)
   ├─ Backup automático (Railway snapshots)
   ├─ Replicação HA (if needed)
   └─ Monitoring (logs, metrics)
```

---

## 📋 Consequências

### ✅ Positivas

1. **Segurança:** Sem perda de dados em crash
2. **Performance:** Row-level locking → sem timeout em registos concorrentes
3. **Compliance:** Auditoria nativa (pg_stat_statements)
4. **Escalabilidade:** Suporta crescimento sem refactor DB
5. **Operações:** Backup automático, ponto-de-restauração

### ⚠️ Negativas

1. **Complexity:** Requer gestão PostgreSQL (mitigado por Railway)
2. **Cost:** ~$15-30/mês em Railway (vs free SQLite local)
3. **Migration effort:** ~4h para migrar schema + data
4. **Type strictness:** PostgreSQL rejeita algumas SQLite quirks (ex: `tinyint`)

### 🔧 Mitigações

| Risco | Mitigation |
|-------|-----------|
| **Schema incompatível** | Migrations idempotentes com `if (!Schema::hasColumn)` |
| **Type errors** | Mudar `unsignedTinyInteger` → `unsignedSmallInteger` |
| **Connection issues** | Railway connection pooling automático |
| **Backup falha** | Railway snapshots diários + manual on-demand |

---

## 🚀 Implementação

### 1. Dev Setup (SQLite continua)

```bash
# Nenhuma mudança necessária
php artisan migrate
```

### 2. Production Setup (PostgreSQL)

```bash
# Railway Dashboard:
1. Create Project
2. Add PostgreSQL (Railway cria automático)
3. Set DB_CONNECTION=pgsql em .env
4. php artisan migrate
```

### 3. Schema Adjustments

Já feitas em sessão 12:
- `unsignedTinyInteger` → `unsignedSmallInteger` (PostgreSQL não suporta tinyint)
- Migrations com guards `if (!Schema::hasColumn(...))`

### 4. Data Migration (zero downtime)

```bash
# Se migrar de SQLite existente:
# 1. Dump SQLite
sqlite3 database.sqlite ".dump" > dump.sql

# 2. Converter SQL (remove SQLite specifics)
# 3. Restaurar em PostgreSQL
psql $DATABASE_URL < dump.sql

# 4. Validar integridade
php artisan migrate:status
```

---

## 📊 Comparação com Alternativas

### Alternativa 1: SQLite (rejected)
- ❌ Inadequado para produção (locks)
- ❌ Sem backup automático Railway

### Alternativa 2: MySQL (rejected)
- ✓ Concorrência OK
- ❌ Sem MVCC (InnoDB tem mais locking)
- ❌ Menos ferramentas de monitoring

### Alternativa 3: PostgreSQL (chosen)
- ✅ Melhor MVCC
- ✅ Railway native support
- ✅ Best-in-class para análise (window functions)
- ✅ Monitoring nativo (pg_stat_statements)

---

## ✅ Validação

### Pre-Deployment Checklist

- [x] Migrations rodam em SQLite (dev)
- [x] Migrations rodam em PostgreSQL (prod schema check)
- [x] Schema compatível (tinyint → smallint)
- [x] Indexes otimizados (`daily_records(pool_id, registado_em)`)
- [x] Constraints respeitadas (FK, UNIQUE)
- [x] Backup Railway testado
- [x] Connection pooling ativo

### Post-Deployment Monitoring

```bash
# Verificar:
railway shell
# Depois:
psql $DATABASE_URL -c "SELECT version();"
psql $DATABASE_URL -c "SELECT * FROM pg_stat_statements LIMIT 5;"
```

---

## 📚 Referências

- [PostgreSQL Docs](https://www.postgresql.org/docs/16/)
- [Railway PostgreSQL](https://docs.railway.app/databases/postgresql)
- [Laravel Database](https://laravel.com/docs/12/database)
- [SQLite vs PostgreSQL Benchmark](https://www.depesz.com/2020/10/26/sqlite-3-34/)

---

## 🔄 Revisão

- [ ] Validado em produção (7 dias de uptime)
- [ ] Backup testado (restore simulation)
- [ ] Queries otimizadas (<100ms p95)
- [ ] Sem problemas de concorrência relatados

---

**Decidido por:** Daniel Paz  
**Data:** 2026-06-15  
**Effective:** 2026-06-18 (deployment)
