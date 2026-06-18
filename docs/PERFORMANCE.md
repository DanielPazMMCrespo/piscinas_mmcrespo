# Performance Tuning Guide

Métricas, diagnóstico e otimização de performance da Piscinas MMCrespo.

---

## 📊 Métricas-Chave

### Response Time Targets

| Endpoint | Target | Atual |
|----------|--------|-------|
| Dashboard (GET /admin) | <2s | ✅ ~1.2s |
| Daily Record List (GET /admin/daily-records) | <1.5s | ⚠️ ~2.5s (sem cache) |
| Create Daily Record (POST) | <500ms | ✅ ~300ms |
| Relatório PDF (GET /admin/relatorios) | <5s | ⚠️ ~8s (20k+ registos) |
| Health Check (GET /api/health) | <100ms | ✅ ~45ms |
| Hanna Sync (php artisan hanna:sync) | <30s | ✅ ~10s |

### Memory Targets

- **Request typical:** <50MB
- **Peak (PDF generation):** <200MB
- **Sustained (cron jobs):** <150MB

---

## 🔍 Diagnosticar Queries Lentas

### 1. Ativar Query Log

```php
// Em routes/web.php ou tinker:
DB::listen(function ($query) {
    if ($query->time > 1000) {  // Queries > 1s
        Log::warning('Slow query', [
            'sql' => $query->sql,
            'bindings' => $query->bindings,
            'time' => $query->time,
        ]);
    }
});
```

### 2. Usar Laravel Debugbar (dev)

```bash
composer require --dev barryvdh/laravel-debugbar
```

Aceder `?debugbar=1` em qualquer página. Ver:
- **Queries:** SQL + tempo
- **Timeline:** fases da request
- **Memory:** heap usage

### 3. Análise Manual

```bash
php artisan tinker

# Ver queries executadas:
> DB::enableQueryLog()
> $records = DailyRecord::with('pool', 'registadoPor')->get()
> dd(DB::getQueryLog())

# Procurar padrões N+1:
# ❌ Má: 1 query para DailyRecords + N queries para pool cada
# ✅ Boa: 1 query com JOIN ou eager load
```

---

## ⚡ Otimizações Rápidas

### 1. Eager Loading

```php
// ❌ N+1 queries:
foreach (DailyRecord::all() as $record) {
    echo $record->pool->nome;  // Query por record
}

// ✅ 2 queries:
foreach (DailyRecord::with('pool')->get() as $record) {
    echo $record->nome;  // Dados em memória
}
```

### 2. Select Apenas Colunas Necessárias

```php
// ❌ SELECT * (carrega dados não necessários):
$records = DailyRecord::get();

// ✅ SELECT apenas o que precisa:
$records = DailyRecord::select(['id', 'ph', 'pool_id', 'registado_em'])
                       ->get();
```

### 3. Índices Database

```bash
# Criar índice em migração:
Schema::table('daily_records', function (Blueprint $table) {
    $table->index(['pool_id', 'registado_em']);  # Composite
    $table->index('status');
});

# Verificar índices existentes:
php artisan tinker
> DB::table('information_schema.statistics')
>    ->where('table_name', 'daily_records')
>    ->get(['column_name', 'seq_in_index'])
```

### 4. Pagination vs. Get()

```php
// ❌ Carrega TUDO em memória:
$records = DailyRecord::get();  # 10k registos = 50MB

// ✅ Pagina (20 por página):
$records = DailyRecord::paginate(20);  # ~500KB por página
```

### 5. Caching

```php
// Cache result 1 hora:
$pools = Cache::remember('pools_list', 3600, function () {
    return Pool::all();
});

// Invalidar quando atualizar:
Pool::create(...);
Cache::forget('pools_list');
```

---

## 🚀 Caching Strategy

### Current Config

```bash
# Em .env:
CACHE_STORE=database  # Melhor para Railway
# ou
CACHE_STORE=redis     # Se tiver Redis
```

### Cache Layers

#### 1. Config Cache (Laravel)

```bash
# Prod: cachear config:
php artisan config:cache
# Depois, mudanças em .env requerem:
php artisan config:clear

# Dev: não usar
php artisan config:clear
```

#### 2. Route Cache

```bash
php artisan route:cache
# Depois, novas rotas requerem:
php artisan route:clear
```

#### 3. View Cache

```php
// Em resources/views/dashboard.blade.php:
@php
  $pools = Cache::remember('pools_with_status', 600, function () {
      return Pool::with('latestRecord')->get();
  });
@endphp
```

#### 4. Query Cache

```php
// Em Service ou Resource:
public function getAlerts()
{
    return Cache::remember('alerts_open', 120, function () {
        return Alert::where('status', 'aberto')->get();
    });
}
```

### Cache Invalidation

```php
// Quando criar/atualizar registo:
public function store(StoreRequest $request)
{
    $daily = DailyRecord::create($request->validated());
    
    // Invalidar caches afetados:
    Cache::forget("alerts_open");
    Cache::forget("pool_{$daily->pool_id}_status");
    
    return redirect(...);
}
```

---

## 📈 Otimizações por Zona

### Dashboard (PainelPiscinasWidget)

**Problema:** 5 queries (1 por pool) mesmo com 1 pool selecionado.

**Solução:**

```php
// Em PainelPiscinasWidget:
protected function getData(): array
{
    // ❌ Antes (N+1):
    foreach ($pools as $pool) {
        $record = $pool->latestRecord;  // Query por pool
    }
    
    // ✅ Depois:
    $records = DailyRecord::whereIn('pool_id', $pool_ids)
                           ->selectRaw('DISTINCT ON (pool_id) *')
                           ->latest('registado_em')
                           ->get()
                           ->keyBy('pool_id');
}
```

### Daily Records Table

**Problema:** Carrega 10k registos, UI demora 5s renderizar.

**Solução:**

1. **Paginate:**
   ```php
   // Em DailyRecordResource:
   return $query->paginate(50);
   ```

2. **Adicionar índices:**
   ```bash
   # Index: (pool_id, registado_em DESC) para filter + sort
   ```

3. **Cache filtros populares:**
   ```php
   protected function getFilters()
   {
       return Cache::remember('daily_record_filters', 3600, function () {
           return [
               'pools' => Pool::all()->pluck('nome', 'id'),
           ];
       });
   }
   ```

### Relatório PDF

**Problema:** 30k registos, PDF demora 15s, memory = 400MB.

**Solução:**

1. **Lazy load:**
   ```php
   // Em RelatorioPdfResource:
   $records = DailyRecord::where('pool_id', $poolId)
                          ->whereBetween('registado_em', [$start, $end])
                          ->lazy(500);  # Processa 500 por vez
   
   foreach ($records as $record) {
       // Renderizar PDF
   }
   ```

2. **Limitar range:**
   ```html
   <!-- Em form PDF -->
   <p class="text-sm text-gray-600">
       Máximo: 90 dias. Para períodos maiores, gerar 2 relatórios.
   </p>
   ```

3. **Compression:**
   ```php
   // Em config/dompdf.php:
   'options' => [
       'compress' => true,
       'chroot' => realpath(base_path()),
   ],
   ```

---

## 🗄️ Database Optimization

### PostgreSQL Specific

#### 1. EXPLAIN ANALYZE

```bash
# Ver plano de execução:
EXPLAIN ANALYZE
SELECT * FROM daily_records
WHERE pool_id = 1
AND registado_em > '2026-06-01'
ORDER BY registado_em DESC;

# Procurar:
# - "Seq Scan" (ruim) vs "Index Scan" (bom)
# - "Rows: 10000" (muitas linhas processadas = índice fraco)
```

#### 2. Index Strategy

```sql
-- Índice composto para queries típicas:
CREATE INDEX idx_daily_records_pool_date 
ON daily_records(pool_id, registado_em DESC);

-- Índice parcial (apenas registos não-corrigidos):
CREATE INDEX idx_daily_records_active
ON daily_records(pool_id, registado_em DESC)
WHERE corrige_registo_id IS NULL;
```

#### 3. Vacuum & Analyze

```bash
# Em Railway, automático. Forçar se necessário:
psql $DATABASE_URL -c "VACUUM ANALYZE;"
```

#### 4. Connection Pooling

```bash
# Em .env (prod):
# Railway cria automático. Se usar manualmente:
DB_HOST=localhost
DB_PORT=5432

# Limitar conexões:
DB_MAX_CONNECTIONS=20
```

---

## 🚦 Load Testing

### Ferramentas

```bash
# Apache Bench (GET simples)
ab -n 1000 -c 10 https://seu-dominio.com/admin

# Siege (mais realista)
brew install siege
siege -u https://seu-dominio.com/admin -r 10 -c 10

# Locust (Python, mais poderoso)
pip install locust
```

### Exemplo Teste

```python
# locustfile.py
from locust import HttpUser, task, between

class DailyRecordUser(HttpUser):
    wait_time = between(1, 3)

    @task
    def list_records(self):
        self.client.get("/admin/daily-records")

    @task
    def view_dashboard(self):
        self.client.get("/admin")

# Correr:
# locust -f locustfile.py -u 50 -r 5
```

### Targets

- **50 utilizadores simultâneos:** <2s response 95th percentile
- **100 utilizadores:** <5s response 95th percentile
- **Error rate:** <0.1%

---

## 📊 Monitoring em Produção

### Railway Metrics

```bash
# Via Railway CLI:
railway status  # Uptime, memory, CPU

# Via Dashboard:
# Railway.app → Project → Metrics
# - CPU usage
# - Memory usage
# - Request count
# - Error rate
```

### Logging Performance

```php
// Em config/logging.php:
'channels' => [
    'performance' => [
        'driver' => 'daily',
        'path' => storage_path('logs/performance.log'),
        'level' => 'info',
        'days' => 7,
    ],
],

// Use em Services:
Log::channel('performance')->info('Query slow', [
    'query' => $query,
    'time_ms' => $time,
]);
```

### Custom Metrics

```php
// Em routes/api.php:
Route::get('/metrics', function () {
    return response()->json([
        'requests_per_minute' => Cache::get('rpm', 0),
        'avg_response_time_ms' => Cache::get('avg_response_time', 0),
        'error_rate' => Cache::get('error_rate', 0),
        'memory_usage_mb' => memory_get_usage() / 1024 / 1024,
    ]);
});
```

---

## 🎯 Checklist Otimização

- [ ] Queries verificadas com EXPLAIN (sem Seq Scans)
- [ ] Eager loading em todos os N+1
- [ ] Caching ativo (CACHE_STORE=database em prod)
- [ ] Índices criados (pool_id, registado_em)
- [ ] Pagination implementado (não get() tudo)
- [ ] Select() colunas necessárias (não SELECT *)
- [ ] Config cached (php artisan config:cache)
- [ ] Routes cached (php artisan route:cache)
- [ ] Asset hashing ativo (npm run build)
- [ ] Database connection pooling ativo
- [ ] Logs rotacionam (não crescem infinitamente)
- [ ] Memory limits respeitados (<200MB por request)

---

**Última atualização:** 2026-06-18
