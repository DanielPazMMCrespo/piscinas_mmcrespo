<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function criarPiscina(float $tempMin = 26.0, float $tempMax = 27.0): Pool
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);

        return Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => $tempMin,
            'temp_max' => $tempMax,
            'volume' => 900.0,
            'active' => true,
        ]);
    }

    public function test_avaliar_conformidade_verde_all_within_limits(): void
    {
        $pool = $this->criarPiscina();

        // Todos os valores dentro dos limites
        $phResult = DailyRecord::avaliarConformidade('ph', 7.4, $pool);
        $this->assertEquals('verde', $phResult['estado']->value);
        $this->assertStringContainsString('Conforme', $phResult['mensagem']);

        $cloroResult = DailyRecord::avaliarConformidade('cloro_livre', 1.0, $pool);
        $this->assertEquals('verde', $cloroResult['estado']->value);

        $tempResult = DailyRecord::avaliarConformidade('temperatura', 26.5, $pool);
        $this->assertEquals('verde', $tempResult['estado']->value);
    }

    public function test_avaliar_conformidade_amarelo_warning_range(): void
    {
        $pool = $this->criarPiscina();

        // pH ligeiramente abaixo do mínimo (6.9)
        // Tolerância padrão = 0.2
        // Aviso se pH estiver entre 6.7 e 6.89
        $resultBaixo = DailyRecord::avaliarConformidade('ph', 6.80, $pool);
        $this->assertEquals('amarelo', $resultBaixo['estado']->value);
        $this->assertStringContainsString('ligeiramente abaixo do mínimo', $resultBaixo['mensagem']);

        // pH ligeiramente acima do máximo (8.0)
        // Aviso se pH estiver entre 8.01 e 8.20
        $resultAlto = DailyRecord::avaliarConformidade('ph', 8.10, $pool);
        $this->assertEquals('amarelo', $resultAlto['estado']->value);
        $this->assertStringContainsString('ligeiramente acima do máximo', $resultAlto['mensagem']);
    }

    public function test_avaliar_conformidade_vermelho_exceeds_limits(): void
    {
        $pool = $this->criarPiscina();

        // pH abaixo do mínimo
        $resultBaixo = DailyRecord::avaliarConformidade('ph', 6.5, $pool);
        $this->assertEquals('vermelho', $resultBaixo['estado']->value);
        $this->assertStringContainsString('abaixo do mínimo', $resultBaixo['mensagem']);

        // pH acima do máximo
        $resultAlto = DailyRecord::avaliarConformidade('ph', 8.5, $pool);
        $this->assertEquals('vermelho', $resultAlto['estado']->value);
        $this->assertStringContainsString('acima do máximo', $resultAlto['mensagem']);

        // Temperatura acima do máximo da piscina
        $resultTemp = DailyRecord::avaliarConformidade('temperatura', 28.0, $pool);
        $this->assertEquals('vermelho', $resultTemp['estado']->value);
    }

    public function test_cloro_combinado_calculates_from_total_and_livre(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.8,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $this->assertEquals(0.8, $registo->cloro_combinado);
    }

    public function test_null_values_return_neutro_estado(): void
    {
        $pool = $this->criarPiscina();

        // Valor nulo
        $resultNull = DailyRecord::avaliarConformidade('ph', null, $pool);
        $this->assertEquals('neutro', $resultNull['estado']->value);
        $this->assertEquals('', $resultNull['mensagem']);

        // String vazia
        $resultEmpty = DailyRecord::avaliarConformidade('ph', '', $pool);
        $this->assertEquals('neutro', $resultEmpty['estado']->value);
    }

    public function test_cloro_combinado_null_when_missing_readings(): void
    {
        // As colunas cloro_livre/cloro_total são NOT NULL no schema (formulário exige-as);
        // o accessor cloro_combinado é null-safe para registos legados. Testa-se o accessor
        // em memória, sem persistir, para validar o null-handling sem violar o schema.

        // Sem cloro_total
        $registo1 = new DailyRecord([
            'cloro_livre' => 1.0,
            'cloro_total' => null,
        ]);

        $this->assertNull($registo1->cloro_combinado);

        // Sem cloro_livre
        $registo2 = new DailyRecord([
            'cloro_livre' => null,
            'cloro_total' => 1.2,
        ]);

        $this->assertNull($registo2->cloro_combinado);
    }

    public function test_conformidade_helpers_return_true_when_within_limits(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $registo->setRelation('piscina', $pool);

        $this->assertTrue($registo->phConforme());
        $this->assertTrue($registo->cloroLivreConforme());
        $this->assertTrue($registo->cloroCombinadoConforme());
        $this->assertTrue($registo->temperaturaConforme());
    }

    public function test_conformidade_helpers_return_false_when_exceeding_limits(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 5.0,  // acima do máximo (2.0)
            'cloro_total' => 5.2,
            'ph' => 9.0,  // acima do máximo (8.0)
            'temperatura' => 40.0,  // acima do máximo da piscina
            'transparencia' => 2,
        ]);

        $registo->setRelation('piscina', $pool);

        $this->assertFalse($registo->phConforme());
        $this->assertFalse($registo->cloroLivreConforme());
        $this->assertFalse($registo->temperaturaConforme());
    }

    public function test_relationships_work_correctly(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $this->assertNotNull($registo->piscina);
        $this->assertEquals($pool->id, $registo->piscina->id);

        $this->assertNotNull($registo->utilizador);
        $this->assertEquals($user->id, $registo->utilizador->id);
    }

    public function test_correccao_relationship_works(): void
    {
        $pool = $this->criarPiscina();
        $user = User::factory()->create();

        $original = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $correcao = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => $original->registado_em,
            'cloro_livre' => 1.1,
            'cloro_total' => 1.3,
            'ph' => 7.5,
            'temperatura' => 26.6,
            'transparencia' => 2,
            'e_correcao' => true,
            'corrige_registo_id' => $original->id,
            'razao_correcao' => 'Erro de leitura',
        ]);

        $this->assertEquals(1, $original->correcoes()->count());
        $this->assertEquals($original->id, $correcao->registoOriginal->id);
    }
}
