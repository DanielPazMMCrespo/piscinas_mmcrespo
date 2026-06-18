# 📚 Documentação Completa

Índice central da documentação Piscinas MMCrespo (v1.0).

---

## 🚀 Quick Links

| Necessidade | Documento |
|---|---|
| **Primeira vez?** | [Readme principal](../README.md) |
| **Deploy em produção?** | [DEPLOYMENT_CHECKLIST.md](../DEPLOYMENT_CHECKLIST.md) |
| **App não funciona?** | [TROUBLESHOOTING.md](TROUBLESHOOTING.md) |
| **Otimizar performance?** | [PERFORMANCE.md](PERFORMANCE.md) |
| **Implementar feature?** | [ARCHITECTURE.md](#arquitetura) abaixo |

---

## 📖 Documentação por Tópico

### Getting Started

| Doc | Descrição | Audience |
|---|---|---|
| **[README.md](../README.md)** | Visão geral, stack, quick start local | Todos |
| **[DEPLOYMENT_CHECKLIST.md](../DEPLOYMENT_CHECKLIST.md)** | 15-min deployment em Railway | DevOps/Admin |
| **[POSTGRESQL_MIGRATION.md](../POSTGRESQL_MIGRATION.md)** | Migrar SQLite → PostgreSQL | DevOps |
| **[RAILWAY_QUICK_START.md](../RAILWAY_QUICK_START.md)** | CLI Railway (alternativa ao dashboard) | DevOps |

### Operacional

| Doc | Descrição | Audience |
|---|---|---|
| **[API.md](API.md)** | Endpoints `/api/health`, `/api/metrics`, etc | Backend/Frontend |
| **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** | Diagnóstico: "App não inicia", "Sensor offline", etc | Ops/Support |
| **[PERFORMANCE.md](PERFORMANCE.md)** | Métricas, tuning, load testing | Backend/DevOps |
| **[SECURITY.md](SECURITY.md)** | Autenticação, headers, compliance CN 14/DA | Security/Backend |
| **[DATABASE.md](DATABASE.md)** | Schema, migrations, backup, indices | Backend/DBA |

### Architecture & Decisions

| Doc | Descrição | Audience |
|---|---|---|
| **[adr/0001-postgresql-choice.md](adr/0001-postgresql-choice.md)** | Por que PostgreSQL (não SQLite) | Architects |
| **[adr/0002-append-only-pattern.md](adr/0002-append-only-pattern.md)** | Compliance CN 14/DA via append-only | Architects/Backend |
| **[adr/0003-hanna-sensor-strategy.md](adr/0003-hanna-sensor-strategy.md)** | Integração sensores Hanna | Architects/Backend |

### Project Context

| Doc | Descrição | Audience |
|---|---|---|
| **[CLAUDE.md](../CLAUDE.md)** | Contexto projeto, strict rules, decisões | Desenvolvedores |
| **[QUALITY_SCORECARD.md](../QUALITY_SCORECARD.md)** | Métricas de qualidade (teste, docs, segurança) | PM/Leads |

---

## 🎯 Arquitetura

### Camadas

```
┌─────────────────────────────────────┐
│   Frontend (Filament + Blade)       │  ← UI, forms, charts
├─────────────────────────────────────┤
│   Controllers & Actions             │  ← Request handling
├─────────────────────────────────────┤
│   Services (AlertasService, etc)    │  ← Business logic
├─────────────────────────────────────┤
│   Models (DailyRecord, Pool, etc)   │  ← ORM, validation
├─────────────────────────────────────┤
│   Database (PostgreSQL / SQLite)    │  ← Persistence
└─────────────────────────────────────┘
```

### Fluxo Principal

```
1. Técnico acede /admin/daily-records/create
   ↓
2. CreateDailyRecord form (Filament)
   ├─ Smart defaults (pool, bomba, agua)
   ├─ Validação tempo real (pH, cloro)
   ├─ Stock validation (quantidade)
   └─ Ação corretiva (se fora limites)
   ↓
3. DailyRecord::create()
   ├─ Transação DB
   ├─ Debita stock instalação
   ├─ Cria log consumo
   ├─ Notificação admin (se fora limites)
   └─ Invalidar caches
   ↓
4. AlertasService calcula alertas
   ├─ Sem registo hoje?
   ├─ Parâmetros fora limites?
   ├─ Stock baixo?
   └─ Sensor offline?
   ↓
5. QuadroOperacionalWidget (Kanban)
   ├─ "Para tratar" atualiza
   └─ Dashboard refesh
```

### Models Relacionados

```
DailyRecord
├─ belongsTo: Pool
├─ belongsTo: User (registadoPor)
├─ hasMany: RecordPhoto (fotos)
├─ hasMany: DailyRecordAddition (químicos)
├─ hasMany: DailyRecord (correcoes)
└─ belongsTo: DailyRecord (corrigeRegisto)

Pool
├─ belongsTo: Installation
├─ hasMany: DailyRecord
├─ hasMany: Incident
├─ hasMany: SensorReading
└─ hasOne: LatestRecord

Incident
├─ belongsTo: Pool
├─ belongsTo: User (criadoPor)
├─ belongsTo: User (resolvidoPor)

StockInstallation
├─ belongsTo: Installation
├─ belongsTo: Product
└─ hasMany: StockInstallationLog
```

---

## 🔑 Decisões-Chave

### 1. PostgreSQL para Produção
**Razão:** Concorrência, backup automático, compliance  
**Tradeoff:** Custo (~$15-30/mês) vs SQLite free  
**Detalhes:** [ADR 0001](adr/0001-postgresql-choice.md)

### 2. Append-Only Pattern
**Razão:** Auditoria CN 14/DA, histórico completo  
**Implementação:** `e_correcao=true` + `corrige_registo_id`  
**Detalhes:** [ADR 0002](adr/0002-append-only-pattern.md)

### 3. Hanna Sensors (Optional)
**Razão:** Verificação manual + sensor bonus (não obrigatório)  
**Integração:** Cron `hanna:sync` a cada 15 min  
**Detalhes:** [ADR 0003](adr/0003-hanna-sensor-strategy.md)

---

## 🧪 Testes

```bash
# Unit
php artisan test --testsuite=Unit

# Feature
php artisan test --testsuite=Feature

# Tudo
php artisan test

# Coverage
php artisan test --coverage
```

**Cobertura target:** >80% (features críticas 100%)

---

## 🚀 Deploy Process

```
1. Feature complete → Push para GitHub
2. GitHub → Railway detects change
3. Railway:
   ├─ Composer install
   ├─ npm build
   ├─ Migrations
   └─ Deploy
4. Verificar: curl https://seu-dominio.com/api/health
```

**Tempo:** ~5 min do push até online

---

## 📞 Contactos & Suporte

| Tópico | Contacto | Tempo Resposta |
|--------|----------|---|
| **Bugs** | GitHub Issues | <24h |
| **Segurança** | daniel.paz@mmcrespo.pt | <2h |
| **Operacional** | Teams/Slack | <1h |
| **Feature requests** | daniel.paz@mmcrespo.pt | <48h |

---

## 🔄 Checklist Onboarding

Novo dev? Completa isto:

- [ ] Clone repo + git checkout deploy/postgresql-clean
- [ ] `composer install` + `npm install`
- [ ] `php artisan key:generate`
- [ ] `php artisan migrate --seed`
- [ ] `php artisan serve` + `npm run dev`
- [ ] Login (admin@mmcrespo.pt / password)
- [ ] Criar registo diário de teste
- [ ] Ler [README.md](../README.md) + [CLAUDE.md](../CLAUDE.md)
- [ ] Colocar dúvidas em grupo/Slack

---

## 📊 Documentation Scorecard

| Aspecto | Score | Notes |
|---------|-------|-------|
| **Setup Local** | ✅ | README + TROUBLESHOOTING cobrem |
| **Deploy Prod** | ✅ | DEPLOYMENT_CHECKLIST (15 min) |
| **API Usage** | ✅ | API.md com exemplos |
| **Performance** | ✅ | PERFORMANCE.md + índices |
| **Security** | ✅ | SECURITY.md + CN 14/DA |
| **Architecture** | ✅ | 3 ADRs explicam decisões |
| **Database** | ✅ | DATABASE.md com ERD |
| **Troubleshooting** | ✅ | 15+ cenários comuns |

**Overall Score: 9/10** ← Objetivo atingido!

---

## 🎯 Próximos Passos (Post-Launch)

- [ ] Feedback utilizadores (técnicos em Leiria)
- [ ] Performance monitoring (1-2 semanas)
- [ ] Ajustes UX baseado em observação
- [ ] Backup procedure testing
- [ ] 2FA implementation (future)
- [ ] Mobile app (Flutter, future)

---

## 📝 Versionamento

| Versão | Data | Mudanças |
|--------|------|----------|
| **1.0** | 2026-06-18 | Release inicial (PostgreSQL-ready) |
| **1.1** | TBD | Sensor Hanna completo + mobile |
| **2.0** | TBD | Multi-tenancy + BI dashboard |

---

**Última atualização:** 2026-06-18  
**Autor:** Daniel Paz  
**Licença:** MIT
