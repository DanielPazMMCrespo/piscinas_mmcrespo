# Redis Cache — Otimização de Performance

## Visão Geral

O projeto implementa cache distribuído via Redis para otimizar as operações críticas de performance:
- **Gráficos**: 14 dias de histórico por piscina → 30 min TTL
- **Alertas**: Cálculo dos alertas operacionais → 5 min TTL
- **Painel de piscinas**: Valores + estado → 10 min TTL

## Configuração

### .env

```env
# Cache driver: redis (produção) ou database (desenvolvimento)
CACHE_STORE=redis

# Redis credenciais
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Migração de Database para Redis

**Desenvolvimento (SQLite):**
```env
CACHE_STORE=database
```

**Produção:**
```env
CACHE_STORE=redis
REDIS_HOST=seu-servidor-redis
REDIS_PASSWORD=sua-senha
```

O Laravel fallback automático — se Redis não está disponível, usa o cache `database` sem mudanças de código.

## Arquitetura

### CacheService (`app/Services/CacheService.php`)

Centraliza toda a gestão de cache com métodos simples e tipados:

```php
// Guardar dados de gráfico (30 min TTL por padrão)
$cacheService->cacheGraphData($poolId, $data, 30);

// Recuperar dados de gráfico
$cached = $cacheService->getGraphData($poolId, $metricsHash);

// Guardar alertas (5 min TTL — crítico)
$cacheService->cacheAlerts($userId, $data, 5);

// Invalidar cache de alertas
$cacheService->invalidateAlerts($userId);

// Invalidar todos os gráficos (mudança estrutural)
$cacheService->invalidateAllGraphs();
```

### Integrações

#### 1. AlertasService (app/Services/AlertasService.php)

**Fluxo:**
1. Verifica memo em-memória (mesmo request)
2. Verifica cache Redis/Database (5 min)
3. Se miss: calcula alertas + guarda em cache
4. Retorna resultado

**TTL:** 5 minutos (crítico — dashboard precisa de reactividade)

```php
$cacheService = app(CacheService::class);
$cached = $cacheService->getAlerts($user->id);
if ($cached !== null) {
    return $cached; // Cache hit
}

// ... calcula alertas ...
$cacheService->cacheAlerts($user->id, $resultado, 5);
return $resultado;
```

#### 2. CloroPhChartWidget (app/Filament/Widgets/CloroPhChartWidget.php)

**Fluxo (apenas para 1 piscina selecionada):**
1. Valida input
2. Se 1 piscina: tenta cache (hash de métricas)
3. Cache miss: carrega 14 dias de histórico + normaliza
4. Guarda em cache (30 min)

**TTL:** 30 minutos (dados históricos mudam lentamente)

```php
$metricsHash = md5(json_encode($metricas));
$cached = $cacheService->getGraphData($poolId, $metricsHash);
if ($cached !== null) {
    return $cached; // Cache hit
}

// ... calcula gráfico ...
$cacheService->cacheGraphData($poolId, $grafico, 30);
```

#### 3. PainelPiscinasWidget (app/Filament/Widgets/PainelPiscinasWidget.php)

**Fluxo:**
1. Tenta cache (painel global)
2. Cache miss: batch-load piscinas + registos + sondas Hanna
3. Guarda em cache (10 min)

**TTL:** 10 minutos (estado das piscinas refresca-se a 60s via polling, mas cache reduz DB hits)

```php
$cached = $cacheService->getPoolData();
if ($cached !== null) {
    return $cached; // Cache hit
}

// ... carrega piscinas + registos ...
$cacheService->cachePoolData($viewData, 10);
```

## Invalidação Automática (Observers)

Quando dados críticos mudam, o cache é invalidado automaticamente:

### DailyRecordObserver

**Trigger:** `DailyRecord::created()` ou `updated()`

**Invalida:**
- Gráficos da piscina afetada (novos dados históricos)
- Todos os alertas (conformidade pode ter mudado)
- Painel de piscinas (valores atualizados)

### StockInstallationObserver

**Trigger:** `StockInstallation::created/updated/deleted()`

**Invalida:**
- Todos os alertas (alerta de stock baixo mudou)
- Painel de piscinas (status de stock)

### IncidentObserver

**Trigger:** `Incident::created()` ou `updated()`

**Invalida:**
- Todos os alertas (lista de incidentes ou status mudou)

## Chaves de Cache

Formato consistente (simples para debugging):

| Operação | Chave | TTL |
|----------|-------|-----|
| Gráfico piscina 1, métricas pH+Cl | `cache_graph_1_<hash>` | 30 min |
| Alertas utilizador 5 | `cache_alertas_5` | 5 min |
| Alertas guest | `cache_alertas_guest` | 5 min |
| Painel piscinas global | `cache_painel_piscinas` | 10 min |

## Monitoramento

### Redis CLI (development)

```bash
redis-cli
> KEYS cache_*           # Lista todas as chaves de cache
> GET cache_alertas_5   # Ver valor de um alerta
> TTL cache_graph_1_*   # Ver tempo de vida restante
> FLUSHDB               # Limpar todo o cache (⚠️ apenas dev!)
```

### Laravel Artisan

```bash
php artisan cache:clear           # Limpar todo o cache
php artisan tinker                # REPL interativo

# Dentro do tinker:
>>> Cache::get('cache_alertas_5')
>>> Cache::forget('cache_alertas_5')
>>> Cache::flush()
```

## Performance

### Impacto Esperado

| Operação | Sem Cache | Com Cache | Ganho |
|----------|-----------|-----------|-------|
| AlertasService::calcular() | ~150ms | ~5ms (hit) | 30x |
| CloroPhChartWidget (1 piscina) | ~300ms | ~10ms (hit) | 30x |
| PainelPiscinasWidget | ~200ms | ~20ms (hit) | 10x |

### Exemplo: Dashboard Carregamento

**Sem cache:**
- AlertasService: 150ms
- PainelPiscinasWidget: 200ms
- Diversos queries: 100ms
- **Total: ~450ms**

**Com cache:**
- AlertasService (hit): 5ms
- PainelPiscinasWidget (hit): 20ms
- **Total: ~25ms** (18x mais rápido)

## Troubleshooting

### Cache não está a funcionar

1. **Verificar driver configurado:**
   ```php
   config('cache.default'); // Deve ser 'redis'
   ```

2. **Redis não está a funcionar:**
   ```bash
   redis-cli ping     # Deve devolver PONG
   ```

3. **Fallback para database:**
   ```env
   CACHE_STORE=database
   ```

### Cache stale (desatualizado)

Se a dados parecem desatualizados:
1. Aguardar TTL expirar (5-30 min conforme a operação)
2. Ou forçar invalidação:
   ```php
   Cache::forget('cache_alertas_*');
   Cache::flush();
   ```

### Memory leak do Redis

Monitorar memória:
```bash
redis-cli INFO memory
redis-cli DBSIZE      # Número de chaves
```

Cache com TTL expira automaticamente — não deve crescer indefinidamente.

## Desenvolvimento

### Desativar Cache

```env
# Forçar cache `null` (nenhum cache, sempre recalcula)
CACHE_STORE=null
```

### Testar Invalidação

```php
// No tinker:
>>> Event::fake();
>>> $record = DailyRecord::create(...);
>>> Cache::has('cache_alertas_*'); // Deve estar vazio

>>> Event::dispatch('model.creating', $record);
>>> // Observer criado observer, deve ter invalidado cache
```

## Roadmap

- [ ] Tagging de cache (ex: `cache:alerts:user:5` para invalidação de padrão eficiente)
- [ ] Cache warming (pre-cache alertas toda a hora)
- [ ] Dashboard de métricas de cache hit/miss
- [ ] Compressão de dados grandes (gráfico com 50+ dias)
- [ ] Cache distribuído (multi-instance com Redis replication)
