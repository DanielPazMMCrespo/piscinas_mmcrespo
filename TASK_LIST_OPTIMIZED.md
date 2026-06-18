# 📋 TASK LIST — PROMPTS OTIMIZADOS

**Como usar:** Envia apenas o número (ex: "Task 1") e faço automaticamente.

---

## 🔴 CRÍTICAS — ESTA SEMANA (3 tasks)

### **TASK 1: Testar Restauração de Backup**
**Esforço:** 15 min | **Prioridade:** CRÍTICA | **Bloqueia:** Deploy

**Prompt completo:**
```
Tarefa: Validar que PostgreSQL backups funcionam e podem ser restaurados.

O que fazer:
1. Em Railway, tira um backup manual da BD PostgreSQL (botão "Backup Now")
2. Cria nova BD "piscinas_mmcrespo_test" vazia
3. Restaura o backup para a nova BD
4. Verifica:
   - Tabelas existem (SELECT COUNT(*) FROM information_schema.tables)
   - Dados estão lá (SELECT COUNT(*) FROM pools)
   - Migrations state correcta (SELECT * FROM migrations)
5. Documenta resultado em BACKUP_VALIDATION.md:
   - Data backup
   - Tamanho
   - Tempo restauração
   - Status (✅ OK ou ❌ FAILED)
   - Problemas encontrados

Não mexe em nada, só valida.
```

---

### **TASK 2: Rodar Load Tests — Baseline Performance**
**Esforço:** 10 min | **Prioridade:** CRÍTICA | **Bloqueia:** Deploy

**Prompt completo:**
```
Tarefa: Executar load tests e documentar baseline de performance.

O que fazer:
1. Localmente, em http://localhost:8000:
   bash scripts/load-test.sh
   
   Se não tiver Laravel running:
   php artisan serve (noutra aba)

2. Ou em Railway (substitui URL):
   bash scripts/load-test.sh https://seu-app.railway.app 100 5

3. Captura output inteiro e documenta em LOAD_TEST_BASELINE.md:
   - URL testada (local ou Railway)
   - Data/hora teste
   - Requisições/sec
   - Latência média, min, max
   - Percentis P95, P99
   - Failed requests (se houver)
   - Problemas encontrados
   - Recomendações

4. Se houver problemas:
   - Documenta erro exacto
   - Tira screenshot
   - Sugere causa provável

Se tiver dúvidas sobre comandos, manda: "Task 2"
```

---

### **TASK 3: Teste Sentry em Produção**
**Esforço:** 20 min | **Prioridade:** CRÍTICA | **Bloqueia:** Deploy

**Prompt completo:**
```
Tarefa: Validar que Sentry está capturando errors em produção.

O que fazer:
1. Vai a sentry.io, cria conta (free tier)
2. Cria project "Laravel", copia DSN (tipo: https://xxx@yyy.ingest.sentry.io/nnn)
3. Em Railway, configura variável de ambiente:
   SENTRY_LARAVEL_DSN = (cola DSN copiado)
4. Faz redeploy em Railway (vai restartar app)
5. Espera 30 segundos, depois acede app: https://seu-app.railway.app
6. Testa erro:
   - Vai a /admin/dashboard
   - Abre browser console (F12)
   - Coloca um erro JS propositalmente
   - Ou tenta login com email inválido (gera exceção)
7. Em Sentry, verifica:
   - Erro apareceu em Issues?
   - Tem stack trace completo?
   - Tem git commit hash no release?
   - Tem breadcrumbs (SQL queries, logs)?
8. Documenta em SENTRY_VALIDATION.md:
   - DSN configurado ✅
   - Erros são capturados ✅
   - Stack traces completos ✅
   - Release tracking ✅
   - Problemas encontrados (se houver)

Depois confirma: "Task 3 done" ou "Task 3 failed: [erro]"
```

---

## 🟡 IMPORTANTES — 1-2 SEMANAS (7 tasks)

### **TASK 4: Adicionar Structured Logging (JSON)**
**Esforço:** 2h | **Prioridade:** ALTA | **Dependência:** Task 3

**Prompt completo:**
```
Tarefa: Implementar structured logging (JSON format) para melhor observability.

O que fazer:
1. Criar `config/logging-structured.php`:
   - Driver: stack
   - 2 canais: single + syslog
   - Formato: JSON (Monolog JsonFormatter)
   - Inclua: timestamp, level, message, context, extra, process_id

2. Modificar `config/logging.php`:
   - Adicionar novo channel "structured"
   - Usar JsonFormatter
   - Escrever logs em storage/logs/structured.json

3. Criar `app/Services/StructuredLogService.php`:
   - Métodos: logInfo, logWarning, logError
   - Cada log inclua: user_id, request_id, ip, action, details
   - Request ID = único por request (middleware)

4. Criar middleware `app/Http/Middleware/LogRequestId.php`:
   - Gera UUID único por request
   - Coloca em request()->attributes
   - Sentry captures it

5. Registar middleware em `app/Http/Kernel.php`:
   - \App\Http\Middleware\LogRequestId::class (global)

6. Adicionar 5 log points críticos:
   - DailyRecordResource::create() - log "daily_record_created"
   - StockInstallationResource::entrada_stock() - log "stock_transferred"
   - AlertasService::calcular() - log "alerts_calculated"
   - PDFIntegrationTest - log "pdf_generated"
   - HealthController - log "health_check"

7. Documentar em STRUCTURED_LOGGING.md:
   - Arquitetura
   - Como ler logs
   - Query examples (grep JSON)
   - Troubleshooting

Não fazer commit, só criar ficheiros.
```

---

### **TASK 5: Unit Tests para Services (20+ testes)**
**Esforço:** 3h | **Prioridade:** ALTA | **Bloqueia:** Sprint 2

**Prompt completo:**
```
Tarefa: Criar unit tests para Services (AlertasService, CacheService, etc.)

O que fazer:
1. Criar `tests/Unit/Services/AlertasServiceTest.php`:
   - 8 testes:
     * testCalcularReturnsCachedValueIfHit()
     * testCalcularRecalculatesIfCacheMiss()
     * testViolacaoLegalDetectsPHOutOfRange()
     * testViolacaoTemperaturaDetectsLowTemp()
     * testViolacaoTemperaturaDetectsHighTemp()
     * testTemperaturaConforme()
     * testEmptyAlertsWhenAllCompliant()
     * testAlertsOrderedByPriority()

2. Criar `tests/Unit/Services/CacheServiceTest.php`:
   - 6 testes:
     * testCacheHit()
     * testCacheMiss()
     * testCacheExpiry()
     * testInvalidateCache()
     * testInvalidateByPattern()
     * testFallbackIfRedisDown()

3. Criar `tests/Unit/Models/DailyRecordTest.php`:
   - 5 testes:
     * testPhConforme()
     * testCloroLivreConforme()
     * testCloroCombinadoConforme()
     * testTemperaturaConforme()
     * testConformanceOverall()

4. Usar Pest ou PHPUnit (o que estiver no projeto):
   - Mocks para DB (Mockery)
   - No real DB queries em unit tests
   - Setup/teardown claro

5. Executar:
   php artisan test tests/Unit/

6. Documentar coverage em TEST_COVERAGE.md:
   - Quantos testes
   - Coverage % (alvo: 70%+)
   - Gaps identificados

Não fazer commit.
```

---

### **TASK 6: Security Tests (OWASP Top 10)**
**Esforço:** 2h | **Prioridade:** ALTA | **Dependência:** Task 5

**Prompt completo:**
```
Tarefa: Criar security tests validando proteção contra OWASP Top 10.

O que fazer:
1. Criar `tests/Feature/SecurityTest.php` com 10 testes:

   a) SQL Injection:
      - Tenta: GET /admin?search="; DROP TABLE pools; --
      - Assert: Status 200 (query sanitizada) ou 400 (rejected)
      - Assert: Tabela pools ainda existe

   b) Cross-Site Scripting (XSS):
      - Tenta: POST daily_record com obs="<script>alert('xss')</script>"
      - Assert: Script é escapado no HTML (não executa)
      - Grep output: &lt;script&gt; (encoded)

   c) Authentication Bypass:
      - Tenta: GET /admin/dashboard sem login
      - Assert: Redirect para /login (402/401)

   d) CSRF Token:
      - Tenta: POST daily_record sem CSRF token
      - Assert: Status 419 (TokenMismatch)

   e) Authorization (Role):
      - Login como nadador_salvador
      - Tenta: GET /admin/daily-records/123/delete
      - Assert: Status 403 (Forbidden)

   f) Rate Limiting:
      - POSTs 50 requests em 10s
      - Assert: Status 429 (Too Many Requests) no 51º

   g) Insecure Deserialization:
      - Session: tenta injectar objeto PHP serializado
      - Assert: Session válida, sem execução

   h) Weak Cryptography:
      - Verifica SESSION_ENCRYPT=true
      - Verifica SESSION_SECURE_COOKIE=true
      - Assert: ambas true

   i) Sensitive Data Exposure:
      - Tenta: GET /admin/logs?user_id=999
      - Assert: Só vê dados do user autenticado (não 999)

   j) Misconfiguration:
      - GET /admin/.env
      - Assert: 404 (arquivo não acessível)
      - GET /admin/config/database.php
      - Assert: 404 (arquivo não acessível)

2. Rodar:
   php artisan test tests/Feature/SecurityTest.php

3. Documentar em SECURITY_TEST_RESULTS.md:
   - Teste | Status (✅/❌) | Detalhes
   - Todos devem passar

Não fazer commit.
```

---

### **TASK 7: Feature Tests — Edge Cases (15+ testes)**
**Esforço:** 2h | **Prioridade:** MÉDIA | **Dependência:** Task 5

**Prompt completo:**
```
Tarefa: Feature tests para edge cases e fluxos anómalos.

O que fazer:
1. Criar `tests/Feature/EdgeCasesTest.php`:

   a) Daily Record:
      - testCreateWithAllNullLecturas() - Assert: Salvou OK
      - testCreateWithPhBelowMin() - Assert: Alerta criado
      - testCreateWithInvalidDate() - Assert: 422 Validation Error
      - testCorrectRecordWithSameValues() - Assert: e_correcao=true
      - testCloroCombinadoNullIfTotalOrLivreNull() - Assert: Null

   b) Stock:
      - testTransferMoreThanAvailable() - Assert: 422 erro "quantidade insuficiente"
      - testTransferZeroQuantity() - Assert: 422 "must be > 0"
      - testTransferWithNegativeQuantity() - Assert: 422 rejected
      - testMultipleTransfersInQuickSuccession() - Assert: Tudo OK, sem race condition
      - testTransferCreatesLogEntry() - Assert: StockWarehouseLog + StockInstallationLog criados

   c) PDF:
      - testPDFExcludesCorrections() - Assert: e_correcao=true não aparece
      - testPDFDateRangeFilter() - Assert: Só mostra data_inicio até data_fim
      - testPDFWithZeroRecords() - Assert: PDF válido mas sem linhas
      - testPDFGenerationTakesLessThan5Seconds() - Assert: time < 5000ms

   d) Alerts:
      - testAlertResolvedWhenConditionDisappears() - Assert: Auto-resolve
      - testNoAlertsWhenAllPoolsCompliant() - Assert: Empty array

2. Rodar:
   php artisan test tests/Feature/EdgeCasesTest.php

3. Documentar em EDGE_CASES_TEST_RESULTS.md:
   - Teste | Resultado | Tempo

Não fazer commit.
```

---

### **TASK 8: Background Jobs (Laravel Horizon)**
**Esforço:** 1.5h | **Prioridade:** MÉDIA | **Bloqueia:** Sprint 2

**Prompt completo:**
```
Tarefa: Implementar background jobs para operações heavy.

O que fazer:
1. Em `app/Jobs/` criar 3 jobs:

   a) GeneratePDFJob.php:
      - Recebe: $dailyRecordIds, $startDate, $endDate
      - Faz: Gera PDF relatório
      - Enfileira em "pdfs" queue
      - Timeout: 60s
      - Retry: 2x se falhar

   b) SendNotificationJob.php:
      - Recebe: $notificationId
      - Faz: Envia notificação (email, SMS, etc.)
      - Enfileira em "notifications" queue
      - Timeout: 30s
      - Retry: 3x

   c) SyncHannaSensorJob.php:
      - Recebe: $deviceId
      - Faz: Sincroniza dados Hanna Cloud
      - Enfileira em "sensors" queue
      - Timeout: 45s
      - Retry: 5x (sensor pode estar lento)

2. Registar jobs em:
   - DailyRecordResource::create() → dispatch GeneratePDFJob
   - AlertasService → dispatch SendNotificationJob
   - console.php cron → dispatch SyncHannaSensorJob (15min)

3. Configurar queues em `config/queue.php`:
   - Driver: database (já está)
   - 3 queues: pdfs, notifications, sensors

4. Criar migrations se não existirem:
   - php artisan queue:table
   - php artisan queue:failed-jobs-table

5. Dokumentar em BACKGROUND_JOBS.md:
   - Arquitetura
   - Como monitorar jobs
   - Como rodar workers: php artisan queue:work
   - Como ver jobs falhados

Não fazer commit.
```

---

## 🟢 NICE-TO-HAVE — DEPOIS DE 1 MÊS (4 tasks)

### **TASK 9: Circuit Breaker para APIs Externas**
**Esforço:** 1.5h | **Prioridade:** BAIXA

```
Tarefa: Implementar circuit breaker pattern para Hanna API.

Cria: app/Services/CircuitBreaker/
- HannaCircuitBreaker.php (deteta falhas, abre circuito)
- cache key: "hanna_circuit_status"
- threshold: 5 falhas em 5 min → open
- fallback: retorna último valor cached

Integra em: HannaCloud::fetchReadings()
```

---

### **TASK 10: Database Archival Strategy**
**Esforço:** 1h | **Prioridade:** BAIXA

```
Tarefa: Arquivar registos antigos (> 1 ano) para performance.

Cria: app/Console/Commands/ArchiveDailyRecords.php
- Queries: registos com created_at < 1 ano atrás
- Move: para tabela "daily_records_archive"
- Migrações: criar tabela archive
- Cron: rodar mensal
```

---

### **TASK 11: Rate Limiting Robusto**
**Esforço:** 45 min | **Prioridade:** BAIXA

```
Tarefa: Implementar rate limiting por user + IP.

Cria: app/Http/Middleware/RateLimitMiddleware.php
- Por user: 100 requests/min
- Por IP: 500 requests/min
- Exceções: /health (sem limite)
- Redis backing: rate_limit:{user}:{minute}
```

---

### **TASK 12: Prometheus Metrics**
**Esforço:** 2h | **Prioridade:** BAIXA

```
Tarefa: Exportar métricas para Prometheus.

Cria: app/Http/Controllers/MetricsController.php
- GET /metrics (formato Prometheus)
- Métricas: request latency, DB query count, cache hit rate
- Integra com: Sentry + Grafana (opcional)
```

---

## 📊 RESUMO

| # | Task | Esforço | Prioridade | Status |
|---|------|---------|-----------|--------|
| **1** | Backup Validation | 15 min | 🔴 CRÍTICA | ❌ TODO |
| **2** | Load Test Baseline | 10 min | 🔴 CRÍTICA | ❌ TODO |
| **3** | Sentry Validation | 20 min | 🔴 CRÍTICA | ❌ TODO |
| **4** | Structured Logging | 2h | 🟡 ALTA | ❌ TODO |
| **5** | Unit Tests | 3h | 🟡 ALTA | ❌ TODO |
| **6** | Security Tests | 2h | 🟡 ALTA | ❌ TODO |
| **7** | Edge Case Tests | 2h | 🟡 MÉDIA | ❌ TODO |
| **8** | Background Jobs | 1.5h | 🟡 MÉDIA | ❌ TODO |
| **9** | Circuit Breaker | 1.5h | 🟢 BAIXA | ❌ TODO |
| **10** | DB Archival | 1h | 🟢 BAIXA | ❌ TODO |
| **11** | Rate Limiting | 45 min | 🟢 BAIXA | ❌ TODO |
| **12** | Prometheus | 2h | 🟢 BAIXA | ❌ TODO |

**Total:** ~19 horas de trabalho (distribuído)

---

## 🚀 COMO USAR

Envia uma mensagem com o número, ex:
```
Task 1
```

E faço tudo automaticamente. Se tiver dúvida sobre a task, manda:
```
Task 1 - duvida: [pergunta]
```

---

**Pronto! Usa quantas quiseres. Manda o número e eu faço! 🚀**
