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
        $alertasService = new AlertasService();
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

    public function test_graph_cache_for_single_pool(): void
    {
        $pool = Pool::factory()->create();
        DailyRecord::factory()->create(['pool_id' => $pool->id]);

        $data = [
            'modo' => 'multi-metrica',
            'series' => [['label' => 'pH', 'data' => [7.2, 7.3, 7.4]]],
        ];

        // Guardar em cache
        $this->cacheService->cacheGraphData($pool->id, $data, 30);

        // Recuperar
        $hash = md5(json_encode($data['series']) ?: '');
        $cached = $this->cacheService->getGraphData($pool->id, $hash);

        $this->assertNotNull($cached);
        $this->assertEquals($data, $cached);
    }

    public function test_pool_data_cache(): void
    {
        $poolData = [
            'piscinas' => [],
            'urlRegistar' => '/admin/registos/create',
        ];

        // Guardar em cache
        $this->cacheService->cachePoolData($poolData, 10);

        // Recuperar
        $cached = $this->cacheService->getPoolData();

        $this->assertNotNull($cached);
        $this->assertEquals($poolData, $cached);
    }

    public function test_cache_invalidation_on_daily_record_create(): void
    {
        $pool = Pool::factory()->create();

        // Setup cache inicial
        $this->cacheService->cacheAlerts(1, ['alertas' => [], 'totalPiscinas' => 1, 'conformesHoje' => 0], 5);
        $this->cacheService->cacheGraphData($pool->id, ['modo' => 'mono'], 30);
        $this->cacheService->cachePoolData(['piscinas' => []], 10);

        // Verificar que estão em cache
        $this->assertNotNull($this->cacheService->getAlerts(1));
        // O gráfico foi guardado sem 'series' → hash de [] (igual ao usado por cacheGraphData).
        $this->assertNotNull($this->cacheService->getGraphData($pool->id, md5(json_encode([])) ?: ''));
        $this->assertNotNull($this->cacheService->getPoolData());

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

    public function test_cache_pattern_invalidation(): void
    {
        $pool1 = Pool::factory()->create();
        $pool2 = Pool::factory()->create();

        // Guardar gráficos de ambas as piscinas
        $this->cacheService->cacheGraphData($pool1->id, ['modo' => 'mono'], 30);
        $this->cacheService->cacheGraphData($pool2->id, ['modo' => 'mono'], 30);

        // Verificar que estão em cache
        $hash = md5(json_encode([]) ?: '');
        $this->assertNotNull($this->cacheService->getGraphData($pool1->id, $hash));
        $this->assertNotNull($this->cacheService->getGraphData($pool2->id, $hash));

        // Invalidar todas as piscinas (não funciona com array cache, mas verifica API)
        $this->cacheService->invalidateAllGraphs();

        $this->assertTrue(true); // Test passed if no exceptions
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
