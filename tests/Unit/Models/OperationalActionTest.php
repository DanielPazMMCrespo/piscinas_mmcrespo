<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $this->pool = Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Lazer',
            'type' => 'leisure',
            'temp_min' => 28,
            'temp_max' => 30,
            'volume' => 500,
            'active' => true,
        ]);
    }

    public function test_dados_formatados_lavagem_filtro(): void
    {
        $action = OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'registado_em' => now(),
            'dados' => ['duracao_min' => 15],
        ]);

        $this->assertEquals('Duração: 15 min', $action->dadosFormatados());
    }

    public function test_dados_formatados_torneira(): void
    {
        $action = OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_TORNEIRA,
            'registado_em' => now(),
            'dados' => ['agua_modo' => 'on_sem_agua'],
        ]);

        $this->assertEquals('Estado: Ligado sem água', $action->dadosFormatados());
    }

    public function test_dados_formatados_bomba(): void
    {
        $action = OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_BOMBA,
            'registado_em' => now(),
            'dados' => ['bomba_ferrada' => true],
        ]);

        $this->assertEquals('Bomba ferrada', $action->dadosFormatados());

        $action2 = OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_BOMBA,
            'registado_em' => now(),
            'dados' => ['bomba_ferrada' => false],
        ]);

        $this->assertEquals('Bomba desferrada', $action2->dadosFormatados());
    }

    public function test_dados_formatados_contador(): void
    {
        $action = OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_CONTADOR,
            'registado_em' => now(),
            'dados' => ['contador_valor' => 1234.56],
        ]);

        $this->assertEquals('Leitura: 1 234,56 m³', $action->dadosFormatados());
    }

    public function test_dados_formatados_tanque(): void
    {
        $action = OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_TANQUE,
            'registado_em' => now(),
            'dados' => ['tanque_ok' => true],
        ]);

        $this->assertEquals('Tanque OK', $action->dadosFormatados());

        $action2 = OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_TANQUE,
            'registado_em' => now(),
            'dados' => ['tanque_ok' => false],
        ]);

        $this->assertEquals('Problema no tanque', $action2->dadosFormatados());
    }

    public function test_dados_formatados_analise_pontual(): void
    {
        $action = OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_ANALISE_PONTUAL,
            'registado_em' => now(),
            'dados' => [
                'ph' => 7.21,
                'cloro_livre' => 1.5,
                'cloro_total' => 1.8,
                'temperatura' => 28.5,
            ],
        ]);

        $this->assertEquals('pH: 7,21 | Cl livre: 1,50 mg/L | Cl total: 1,80 mg/L | Temp: 28,5 °C', $action->dadosFormatados());
    }
}
