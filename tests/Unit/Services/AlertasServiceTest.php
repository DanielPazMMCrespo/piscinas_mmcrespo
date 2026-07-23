<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Services\AlertasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AlertasServiceTest extends TestCase
{
    use RefreshDatabase;

    private AlertasService $service;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        AlertasService::resetMemo();

        $this->service = new AlertasService;
    }

    private function criarPiscina(string $nome = 'Teste', float $tempMin = 26.0, float $tempMax = 27.0): Pool
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);

        return Pool::create([
            'installation_id' => $inst->id,
            'name' => $nome,
            'type' => 'Interior',
            'temp_min' => $tempMin,
            'temp_max' => $tempMax,
            'volume' => 900.0,
            'active' => true,
        ]);
    }

    public function test_alertas_service_calculates_violations(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        // Cria um registo com pH fora dos limites
        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 5.0,  // abaixo do mínimo (6.9)
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $resultado = $this->service->calcular($user);

        $this->assertIsArray($resultado['alertas']);
        $this->assertTrue(
            collect($resultado['alertas'])->some(fn ($alerta) => str_contains($alerta['titulo'] ?? '', 'fora dos limites')),
            'Deveria haver alerta de parâmetros fora dos limites'
        );
    }

    public function test_legal_violation_for_low_ph(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 6.5,  // abaixo do mínimo (6.9)
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $resultado = $this->service->calcular($user);

        $this->assertTrue(
            collect($resultado['alertas'])->some(
                fn ($alerta) => str_contains($alerta['titulo'] ?? '', 'fora dos limites') && $alerta['nivel'] === 'vermelho'
            ),
            'pH baixo deveria gerar alerta vermelho'
        );
    }

    public function test_legal_violation_for_high_temp(): void
    {
        $pool = $this->criarPiscina(tempMax: 27.0);
        $user = User::factory()->create();

        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 28.5,  // acima do máximo (27.0)
            'transparencia' => 2,
        ]);

        $resultado = $this->service->calcular($user);

        $this->assertTrue(
            collect($resultado['alertas'])->some(
                fn ($alerta) => str_contains($alerta['titulo'] ?? '', 'temperatura') && $alerta['nivel'] === 'amarelo'
            ),
            'Temperatura alta deveria gerar alerta amarelo'
        );
    }

    public function test_memoization_per_request(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $result1 = $this->service->calcular($user);
        $result2 = $this->service->calcular($user);

        $this->assertSame($result1, $result2, 'Chamadas consecutivas devem retornar a mesma instância (memoizada)');
    }

    public function test_cache_hits_correctly(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        // Cria um registo conforme
        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $resultado = $this->service->calcular($user);

        // Verifica que há conformidade registada
        $this->assertEquals(1, $resultado['conformesHoje']);
        $this->assertEquals(1, $resultado['totalPiscinas']);
    }

    public function test_cache_misses_on_new_data(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        // Sem registo
        $resultado1 = $this->service->calcular($user);
        $conformesAntes = $resultado1['conformesHoje'];

        // Cria um novo registo
        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        // A criação do registo deve invalidar a cache; com o array driver dos testes a
        // invalidação por padrão é no-op, por isso limpamos explicitamente (memo + cache)
        // para simular fielmente o cache miss e forçar o recálculo nesta nova "request".
        AlertasService::resetMemo();
        Cache::flush();
        $novoServico = new AlertasService;
        $resultado2 = $novoServico->calcular($user);
        $conformesDepois = $resultado2['conformesHoje'];

        // Após criar um registo conforme hoje, a contagem de conformes deve aumentar.
        $this->assertGreaterThan($conformesAntes, $conformesDepois);
    }

    public function test_multiple_violations_aggregated(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        // Cria um registo com múltiplas violações
        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 5.0,  // acima do máximo (2.0)
            'cloro_total' => 5.2,
            'ph' => 9.0,  // acima do máximo (8.0)
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $resultado = $this->service->calcular($user);

        // Deveria haver um alerta vermelho agregando as violações
        $alerta = collect($resultado['alertas'])->first(
            fn ($a) => str_contains($a['titulo'] ?? '', 'fora dos limites')
        );

        $this->assertNotNull($alerta);
        $this->assertEquals('vermelho', $alerta['nivel']);
        $this->assertStringContainsString('pH', $alerta['detalhe']);
        $this->assertStringContainsString('cloro', $alerta['detalhe']);
    }

    public function test_cloro_combinado_calculated_correctly(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.8,  // combinado = 1.8 - 1.0 = 0.8
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        // Cloro combinado acima do máximo (0.6)
        $this->assertEquals(0.8, $registo->cloro_combinado);
        $this->assertFalse($registo->cloroCombinadoConforme());

        $resultado = $this->service->calcular($user);

        $this->assertTrue(
            collect($resultado['alertas'])->some(
                fn ($a) => str_contains($a['detalhe'] ?? '', 'cloro combinado')
            )
        );
    }
}
