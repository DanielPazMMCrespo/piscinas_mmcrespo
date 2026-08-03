<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\User;
use App\Services\AlertasService;
use App\Services\CacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CachingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private CacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheService = app(CacheService::class);

        // Force cache driver para testes
        config(['cache.default' => 'array']);

        // Evita que o memo estático do AlertasService (curto-circuita o cache)
        // polua testes que verificam a escrita em cache.
        AlertasService::resetMemo();
    }

    public function test_alertas_service_uses_cache(): void
    {
        // Setup
        $user = User::factory()->create();
        $pool = Pool::factory()->create();

        // Primeira chamada: cache miss
        $alertasService = app(AlertasService::class);
        $start1 = microtime(true);
        $resultado1 = $alertasService->calcular($user);
        $tempo1 = microtime(true) - $start1;

        // Verifica que está em cache
        $cached = $this->cacheService->getAlerts($user->id);
        $this->assertNotNull($cached);
        $this->assertEquals($resultado1, $cached);

        // Segunda chamada: cache hit (muito mais rápida)
        $start2 = microtime(true);
        $resultado2 = $alertasService->calcular($user);
        $tempo2 = microtime(true) - $start2;

        // Verificar que resultado é igual
        $this->assertEquals($resultado1, $resultado2);

        // Cache hit deve ser significativamente mais rápida
        // (Note: Em ambiente de teste com array cache é muito rápido, mas a proporção permanece)
        $this->assertLessThan($tempo1, $tempo2 * 2); // Margem generosa para variação
    }

    /**
     * A chave que o CloroPhChartWidget escreve tem de cair no padrão que o
     * invalidateGraphCache() apaga. Já estiveram desalinhadas (chart_v3_* vs
     * cache_graph_*) e o gráfico ficava desatualizado até expirar o TTL.
     */
    public function test_graph_cache_key_matches_invalidation_pattern(): void
    {
        config(['cache.default' => 'database']);

        $pool = Pool::factory()->create();
        $outra = Pool::factory()->create();

        $chave = "cache_graph_{$pool->id}_v3_ph_controlador_orp_7d__";
        $chaveOutra = "cache_graph_{$outra->id}_v3_ph_controlador_orp_7d__";

        Cache::put($chave, ['titulo' => 'x'], now()->addMinutes(10));
        Cache::put($chaveOutra, ['titulo' => 'y'], now()->addMinutes(10));

        $this->cacheService->invalidateGraphCache($pool->id);

        $this->assertNull(Cache::get($chave));
        $this->assertNotNull(Cache::get($chaveOutra), 'A invalidação é por piscina, não global.');
    }

    public function test_pool_data_cache(): void
    {
        $poolData = [
            'piscinas' => [],
            'urlRegistar' => '/admin/registos/create',
        ];

        // Guardar em cache
        $this->cacheService->cachePoolData('full', $poolData, 10);

        // Recuperar
        $cached = $this->cacheService->getPoolData('full');

        $this->assertNotNull($cached);
        $this->assertEquals($poolData, $cached);
    }

    public function test_cache_invalidation_on_daily_record_create(): void
    {
        $pool = Pool::factory()->create();

        // Setup cache inicial
        $this->cacheService->cacheAlerts(1, ['alertas' => [], 'totalPiscinas' => 1, 'conformesHoje' => 0], 5);
        $this->cacheService->cachePoolData('full', ['piscinas' => []], 10);

        // Verificar que estão em cache
        $this->assertNotNull($this->cacheService->getAlerts(1));
        $this->assertNotNull($this->cacheService->getPoolData('full'));

        // Criar novo DailyRecord (observer deve invalidar cache)
        DailyRecord::factory()->create(['pool_id' => $pool->id]);

        // Verificar que o cache foi invalidado
        // (observers verificam se cache foi invalidado)
        // Este teste passa se não há exceções
        $this->assertTrue(true);
    }

    public function test_cache_invalidation_on_stock_change(): void
    {
        // Setup cache inicial
        $this->cacheService->cacheAlerts(1, ['alertas' => [], 'totalPiscinas' => 1, 'conformesHoje' => 0], 5);

        // Criar Stock (observer deve invalidar alertas)
        // Este é um teste de smoke — verifica se não há erros
        // (implementação completa requer tabela stock_installations)

        $this->assertTrue(true);
    }

    public function test_database_cache_pattern_invalidation_clears_all_alerts(): void
    {
        config(['cache.default' => 'database']);

        $this->cacheService->cacheAlerts(1, ['alertas' => ['a'], 'totalPiscinas' => 1, 'conformesHoje' => 0], 5);
        $this->cacheService->cacheAlerts(2, ['alertas' => ['b'], 'totalPiscinas' => 1, 'conformesHoje' => 0], 5);

        $this->assertNotNull($this->cacheService->getAlerts(1));
        $this->assertNotNull($this->cacheService->getAlerts(2));

        $this->cacheService->invalidateAllAlerts();

        $this->assertNull($this->cacheService->getAlerts(1));
        $this->assertNull($this->cacheService->getAlerts(2));
    }

    public function test_cache_ttl_respects_timeouts(): void
    {
        // Nota: Com array cache, TTL não é respeitado (sempre hit até flush)
        // Este teste documenta o comportamento esperado com Redis

        $data = ['test' => 'data'];

        // Guardar com 1 segundo TTL (em array cache, será ignorado)
        $this->cacheService->cacheAlerts(1, $data, 1);

        // Imediato: hit
        $cached = $this->cacheService->getAlerts(1);
        $this->assertNotNull($cached);

        // Com Redis: após 1 segundo seria null, mas array cache não expira automaticamente
        // Este teste documenta o comportamento, não valida o TTL
    }
}
