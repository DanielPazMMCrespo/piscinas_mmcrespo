<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PoolClosure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Centraliza a gestão de cache para operações críticas de performance:
 * gráficos, alertas e dados de piscinas.
 *
 * Estratégia:
 * - Gráficos: 30 min TTL (dados históricos, mudam lentamente)
 * - Alertas: 5 min TTL (críticos para dashboard, precisam de reactividade)
 * - Pool data: 10 min TTL (estado geral das piscinas)
 *
 * Invalidação (via events/observers):
 * - Novo registo diário → invalida gráficos + alertas + pool data
 * - Mudança de stock → invalida pool data
 * - Incidente resolvido → invalida alertas
 */
class CacheService
{
    /**
     * Cache do resultado completo de AlertasService::calcular().
     * Chave: cache_alertas_{scope} — o resultado só varia por role (ns/full),
     * não por utilizador.
     *
     * @param  int|string|null  $scope  Âmbito do cálculo (ex: 'full', 'ns')
     * @param  array{alertas: array, totalPiscinas: int, conformesHoje: int}  $data  Resultado de calcular()
     * @param  int  $ttlMinutos  Time-to-live em minutos (padrão 5 — crítico para dashboard)
     */
    public function cacheAlerts(int|string|null $scope, array $data, int $ttlMinutos = 5): void
    {
        $memoKey = (string) ($scope ?? 'guest');
        $key = "cache_alertas_{$memoKey}";

        Cache::put($key, $data, now()->addMinutes($ttlMinutos));
    }

    /**
     * Obtém alertas do cache.
     *
     * @param  int|string|null  $scope  Âmbito do cálculo (ex: 'full', 'ns')
     * @return array{alertas: array, totalPiscinas: int, conformesHoje: int}|null
     */
    public function getAlerts(int|string|null $scope): ?array
    {
        $memoKey = (string) ($scope ?? 'guest');
        $key = "cache_alertas_{$memoKey}";

        return Cache::get($key);
    }

    /**
     * Cache dos dados do painel de piscinas (valores + estado).
     * Chave: cache_painel_piscinas_{scope} — o Nadador-Salvador só vê as suas
     * piscinas, logo uma chave global cruzaria dados entre roles.
     *
     * @param  string  $scope  Âmbito da vista (ex: 'full', "ns_{userId}")
     * @param  array<string, mixed>  $data  Array de piscinas com métricas/sonda
     * @param  int  $ttlMinutos  Time-to-live em minutos (padrão 10)
     */
    public function cachePoolData(string $scope, array $data, int $ttlMinutos = 10): void
    {
        $key = "cache_painel_piscinas_{$scope}";

        Cache::put($key, $data, now()->addMinutes($ttlMinutos));
    }

    /**
     * Obtém dados do painel de piscinas do cache.
     *
     * @param  string  $scope  Âmbito da vista (ex: 'full', "ns_{userId}")
     * @return array<string, mixed>|null
     */
    public function getPoolData(string $scope): ?array
    {
        $key = "cache_painel_piscinas_{$scope}";

        return Cache::get($key);
    }

    /**
     * Invalida o cache de gráficos de uma piscina específica.
     * Chamado ao criar novo registo diário.
     *
     * @param  int  $poolId  ID da piscina
     */
    public function invalidateGraphCache(int $poolId): void
    {
        // Apaga todas as combinações eixo/período do CloroPhChartWidget desta
        // piscina (chave "cache_graph_{pool}_v3_...").
        $this->invalidateByPattern("cache_graph_{$poolId}_*");
    }

    /**
     * Invalida o cache de alertas de um utilizador.
     * Chamado ao criar novo registo ou resolver incidente.
     *
     * @param  int|string|null  $userId  ID do utilizador (ou 'guest')
     */
    public function invalidateAlerts(?int $userId): void
    {
        $memoKey = (string) ($userId ?? 'guest');
        $key = "cache_alertas_{$memoKey}";

        Cache::forget($key);
    }

    /**
     * Invalida o cache do painel de piscinas (todos os scopes).
     * Chamado ao criar novo registo ou mudar stock.
     */
    public function invalidatePoolData(): void
    {
        if (config('cache.default') === 'file') {
            Cache::flush();

            return;
        }
        $this->invalidateByPattern('cache_painel_piscinas_*');
    }

    /**
     * Invalida o cache de IDs de stock baixo.
     */
    public function invalidateLowStockCache(): void
    {
        Cache::forget('cache_low_stock_ids');
    }

    /**
     * Chave única do mapa de encerramentos de piscinas. Chave fixa (sem
     * wildcard) de propósito: o Cache::forget() funciona em todos os drivers,
     * incluindo 'file' em dev, onde a invalidação por padrão não funciona.
     */
    public const CLOSURES_KEY = 'cache_pool_closures_v1';

    /**
     * Encerramentos vigentes hoje, indexados por pool_id. São 5 piscinas e
     * poucos períodos por ano — cabe todo em memória e evita uma query em cada
     * pedido do dashboard, dos alertas e dos formulários.
     *
     * @return array<int, PoolClosure>|null
     */
    public function getClosureMap(): ?array
    {
        return Cache::get(self::CLOSURES_KEY);
    }

    /**
     * @param  array<int, PoolClosure>  $map
     */
    public function cacheClosureMap(array $map, int $ttlMinutos = 60): void
    {
        Cache::put(self::CLOSURES_KEY, $map, now()->addMinutes($ttlMinutos));
    }

    public function invalidateClosures(): void
    {
        Cache::forget(self::CLOSURES_KEY);
    }

    /**
     * Invalida todos os alertas (para todos os utilizadores).
     * Útil em operações críticas (resolução de incidente, reset de BD).
     */
    public function invalidateAllAlerts(): void
    {
        // Estratégia: flush todas as chaves cache_alertas_*
        // Se usar tagging em Redis, seria mais eficiente.
        // Por agora, padrão simples.
        $this->invalidateByPattern('cache_alertas_*');
    }

    /**
     * Utilitário: remove chaves matching a um padrão wildcard.
     * Compatível com Redis (KEYS + DEL) e database store.
     *
     * @param  string  $pattern  Ex: "cache_graph_1_*"
     * @return int Número de chaves removidas
     */
    private function invalidateByPattern(string $pattern): int
    {
        $driver = config('cache.default');

        if ($driver === 'redis') {
            return $this->invalidateRedisPattern($pattern);
        }

        if ($driver === 'database') {
            return $this->invalidateDatabasePattern($pattern);
        }

        // Para file store: sem suporte a wildcard nativo.
        // Fallback seguro: não invalida (evita erros, cache expira naturalmente).
        // Se necessário, usar tagging ou flush completo.
        return 0;
    }

    /**
     * Invalida padrão Redis via KEYS + DEL.
     *
     * @param  string  $pattern  Ex: "cache_*"
     * @return int Número de chaves removidas
     */
    private function invalidateDatabasePattern(string $pattern): int
    {
        $connection = config('cache.stores.database.connection');
        $table = config('cache.stores.database.table', 'cache');
        $prefix = config('cache.prefix') ?: '';
        $like = $prefix.str_replace('*', '%', $pattern);

        return DB::connection($connection)
            ->table($table)
            ->where('key', 'like', $like)
            ->delete();
    }

    /**
     * Invalida padrao Redis via KEYS + DEL.
     */
    private function invalidateRedisPattern(string $pattern): int
    {
        $redis = Cache::store('redis')->connection();
        $prefix = config('cache.prefix') ?: '';
        $fullPattern = $prefix ? "{$prefix}:{$pattern}" : $pattern;

        $keys = $redis->keys($fullPattern);
        if (empty($keys)) {
            return 0;
        }

        return $redis->del(...$keys);
    }
}
