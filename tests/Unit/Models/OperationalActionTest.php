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
            'dados' => [
                'filtro_nome' => 'Filtro 1',
                'duracao_min' => 15,
                'pressao_antes_bar' => 1.50,
                'pressao_depois_bar' => 0.90,
            ],
        ]);

        $this->assertEquals('Filtro: Filtro 1 | Duração: 15 min | Pressão inicial: 1.5 bar | Pressão final: 0.9 bar', $action->dadosFormatados());
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
            'dados' => [
                'bomba_nome' => 'Bomba 1',
                'bomba_acao' => 'limpeza_pre_filtro',
                'bomba_ferrada' => true,
            ],
        ]);

        $this->assertEquals('Bomba: Bomba 1 | Ação: Limpeza de pré-filtro | Bomba ferrada', $action->dadosFormatados());
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
            'dados' => [
                'tanque_nome' => 'Tanque de Compensação',
                'tanque_nivel_pct' => 85,
                'tanque_ok' => true,
            ],
        ]);

        $this->assertEquals('Tanque: Tanque de Compensação | Nível: 85% | Tanque OK', $action->dadosFormatados());
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
                'orp' => 710,
                'temperatura' => 28.5,
            ],
        ]);

        $this->assertEquals('pH: 7,21 | Cl livre: 1,50 mg/L | Cl total: 1,80 mg/L | Cl comb: 0,30 mg/L | ORP: 710 mV | Temp: 28,5 °C', $action->dadosFormatados());
    }

    public function test_dados_formatados_tratamento_choque(): void
    {
        $action = OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_TRATAMENTO_CHOQUE,
            'registado_em' => now(),
            'dados' => [
                'produto' => 'Hipoclorito de Cálcio',
                'quantidade' => '5 kg',
            ],
        ]);

        $this->assertEquals('Produto: Hipoclorito de Cálcio | Quantidade: 5 kg', $action->dadosFormatados());
    }
}
