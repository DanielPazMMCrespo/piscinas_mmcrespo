# Load Test Guide — Piscinas MMCrespo

Guia para validar performance da aplicação antes e após deployment.

## 📋 Requisitos

### Apache Bench (ab)
Ferramenta nativa para HTTP load testing.

**Linux:**
```bash
sudo apt-get install apache2-utils
```

**macOS:**
```bash
brew install httpd
```

**Windows (Git Bash/WSL):**
```bash
# Via WSL (recommended)
wsl sudo apt-get install apache2-utils

# Or download from Apache.org
```

### Python (opcional, para script avançado)
```bash
python3 --version  # Requires 3.6+
```

## 🚀 Uso Rápido

### Bash Script (Apache Bench)

**Desenvolvimento local:**
```bash
bash scripts/load-test.sh http://localhost:8000 100 5
```

**Produção (Railway):**
```bash
bash scripts/load-test.sh https://seu-projeto.railway.app 100 5
```

**Parâmetros:**
- Argumento 1: URL da aplicação (default: http://localhost:8000)
- Argumento 2: Número de requests (default: 100)
- Argumento 3: Concorrência (default: 5)

### Python Script (Avançado)

**Instalação:** Não requer dependências externas (usa stdlib).

**Execução:**
```bash
python3 scripts/load-test-advanced.py http://localhost:8000 100 5
```

**Vantagens sobre Apache Bench:**
- Estatísticas detalhadas (desvio padrão, percentis)
- Melhor formatação de saída
- Suporta timeouts mais robustos
- Funciona em qualquer plataforma

## 📊 Entender os Resultados

### Métricas Principais

```
Requests/sec: 45.5
  ✓ >= 50:    Excelente
  ⚠️  10-49:   Aceitável
  ❌ < 10:     Precisa otimização
```

```
Time per request: 220 ms (média)
  ✓ < 500ms:    Excelente
  ⚠️  500-1000ms: Aceitável
  ❌ > 1000ms:   Precisa otimização
```

### Latência por Percentil

- **P50 (Mediana):** 50% das requisições ficam acima deste tempo
- **P95:** 95% das requisições ficam acima deste tempo (tail latency)
- **P99:** 99% das requisições ficam acima deste tempo (outliers)

**Interpretação:**
- P95 < 1000ms: usuários normais têm boa experiência
- P99 > 5000ms: alguns usuários verão delays

## 🔍 Endpoints Testados

| Endpoint | Descrição | Tipo |
|----------|-----------|------|
| `/` | Homepage | GET |
| `/admin` | Dashboard (requer auth) | GET |
| `/admin/daily-records` | Lista de registos | GET |
| `/admin/incidents` | Lista de incidentes | GET |

**Nota:** Testes requerem que o servidor responda sem autenticação ou que forneça sessão válida.

## 📈 Interpretar Problemas

### Requests/sec < 10 ❌

**Causas possíveis:**
1. **Database lenta** (queries sem índices)
2. **Cache desativado** (Redis/cache.driver = array)
3. **Processamento pesado** (relações N+1, transformações)
4. **Recursos do servidor** (CPU/RAM insuficientes)

**Soluções:**
```bash
# Verificar índices
EXPLAIN SELECT * FROM daily_records WHERE pool_id = ?;

# Ativar caching
php artisan config:cache
php artisan route:cache

# Detectar N+1
php artisan debugbar:enable

# Profiling
php -d memory_limit=-1 artisan tinker
>>> DB::enableQueryLog(); DB::listen(fn($q) => dump($q->time));
```

### Latência > 1000ms ❌

**Causas possíveis:**
1. **Consultas lentas** (sem LIMIT, JOINs complexos)
2. **Cache missing** (primeiras requisições sempre lentas)
3. **Rede** (latência entre client e servidor)
4. **Garbage collection** (PHP GC pausa aplicação)

**Soluções:**
```bash
# Verificar slow query log
tail -f storage/logs/laravel.log | grep "^\[.*\] local.DEBUG:"

# Habilitar Laravel Debugbar (dev apenas)
APP_DEBUG=true

# Usar Redis para sessions/cache
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# Profile com Blackfire (premium) ou xhprof (gratuito)
```

## 🛠️ Otimizações Comuns

### 1. Cache HTTP (Headers)

**Em Filament Resources:**
```php
protected function getHeaderActions(): array
{
    return [
        // ...
    ];
}
// Adicionar middleware no routes/web.php:
Route::middleware(['cache.headers:public;max_age=3600'])->group(function () {
    // ...
});
```

### 2. Database Indexing

```php
// Migration:
Schema::table('daily_records', function (Blueprint $table) {
    $table->index(['pool_id', 'registado_em']);
    $table->index('e_correcao');
});
```

### 3. Eager Loading (N+1 Prevention)

```php
// Em DailyRecordResource::collection():
DailyRecord::with('pool', 'instalacao', 'usuario')
    ->paginate()
```

### 4. Query Optimization

```php
// Antes (lento):
$records = DailyRecord::all();
$count = $records->where('e_correcao', false)->count();

// Depois (rápido):
$count = DailyRecord::where('e_correcao', false)->count();
```

### 5. Asset Caching (Front-end)

```bash
# Em production:
npm run build
php artisan optimize
php artisan route:cache
php artisan config:cache
```

## 📋 Checklist Pré-Deployment

- [ ] Rodar load test em staging
- [ ] Confirmar Requests/sec >= 50
- [ ] Confirmar P95 latency < 1000ms
- [ ] Revisionar slow query log
- [ ] Ativar `APP_DEBUG=false`
- [ ] Ativar `APP_ENV=production`
- [ ] Rodar `php artisan optimize`
- [ ] Confirmar caching em nginx/railway

## 📋 Checklist Pós-Deployment (Primeira Semana)

- [ ] Monitorar performance via New Relic / Sentry
- [ ] Replicar testes de load em produção (horário de baixo uso)
- [ ] Revistar relatórios de erro
- [ ] Coletar feedback de utilizadores (latência, responsividade)
- [ ] Ajustar worker pools / timeout se necessário

## 🚨 Alertas de Performance

| Métrica | Amarelo | Vermelho |
|---------|---------|----------|
| Requests/sec | 20-49 | < 20 |
| P50 Latency | 200-500ms | > 500ms |
| P95 Latency | 1-2s | > 2s |
| Failed Requests | 1-5% | > 5% |
| Error Rate | 0.5-1% | > 1% |

## 📚 Recursos

- [Apache Bench Documentation](https://httpd.apache.org/docs/current/programs/ab.html)
- [Laravel Performance](https://laravel.com/docs/optimization)
- [PostgreSQL Query Optimization](https://www.postgresql.org/docs/current/sql-explain.html)
- [Filament Performance](https://filamentphp.com/docs/3.x/admin/resources/performance)

## 💬 Troubleshooting

### Erro: "Connection refused"
```bash
# Verificar se servidor está a rodar
curl http://localhost:8000/
# Se não responder, iniciar: php artisan serve
```

### Erro: "Apache Bench not found"
```bash
# Windows (usar Python script em vez disso):
python3 scripts/load-test-advanced.py http://localhost:8000 100 5
```

### Teste lento no localhost
```bash
# Localhost é mais lento (sem cache HTTP, sem CDN)
# Testar em staging/produção para resultados reais
bash scripts/load-test.sh https://seu-projeto.railway.app 100 5
```

## 📁 Saída de Logs

Todos os testes geram logs em:
```
storage/logs/load-tests/YYYYMMDD_HHMMSS_*.txt
```

Visualizar último teste:
```bash
ls -ltr storage/logs/load-tests/ | tail -1
cat $(ls -t storage/logs/load-tests/* | head -1)
```

## 🎯 Objetivo de Performance (MMCrespo)

Para uma aplicação de gestão operacional:
- **Requests/sec:** >= 50 (piscinas com 5-10 técnicos simultâneos)
- **P95 Latency:** < 1000ms (boa UX em 4G/5G)
- **Uptime:** 99.9% (~ 45 minutos downtime/mês)
- **Throughput:** 1000+ daily records/dia

**Baseado em:** 5 instalações × 3 técnicos × 8h/dia = pico 15 utilizadores simultâneos
