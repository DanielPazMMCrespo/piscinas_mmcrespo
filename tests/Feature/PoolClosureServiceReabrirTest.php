<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\MotivoEncerramento;
use App\Constants\TrabalhoParagem;
use App\Models\Pool;
use App\Models\PoolClosureTask;
use App\Models\User;
use App\Services\PlanoParagemService;
use App\Services\PoolClosureService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PoolClosureServiceReabrirTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_bloqueia_reabertura_com_trabalhos_obrigatorios_por_terminar(): void
    {
        $utilizador = User::factory()->create();
        $piscina = Pool::factory()->create();

        $encerramento = app(PoolClosureService::class)->encerrar($piscina, [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => '2026-08-03',
        ], $utilizador);

        app(PlanoParagemService::class)->criarPlano($encerramento, $utilizador);

        // Deixa uma tarefa obrigatoria "em_curso" (nao terminada nem justificada).
        $tarefaObrigatoria = $encerramento->trabalhos()
            ->where('obrigatorio', true)
            ->where('estado', '!=', TrabalhoParagem::ESTADO_EXECUTADO)
            ->firstOrFail();

        $tarefaObrigatoria->update(['estado' => TrabalhoParagem::ESTADO_EM_CURSO]);

        $this->expectException(DomainException::class);

        app(PoolClosureService::class)->reabrir($piscina->fresh(), $utilizador, Carbon::parse('2026-09-15'));
    }

    public function test_permite_reabertura_quando_todas_as_obrigatorias_estao_concluidas(): void
    {
        $utilizador = User::factory()->create();
        $piscina = Pool::factory()->create();

        $encerramento = app(PoolClosureService::class)->encerrar($piscina, [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => '2026-08-03',
        ], $utilizador);

        app(PlanoParagemService::class)->criarPlano($encerramento, $utilizador);

        PoolClosureTask::where('pool_closure_id', $encerramento->id)
            ->where('obrigatorio', true)
            ->update(['estado' => TrabalhoParagem::ESTADO_EXECUTADO]);

        $resultado = app(PoolClosureService::class)->reabrir($piscina->fresh(), $utilizador, Carbon::parse('2026-09-15'));

        $this->assertNotNull($resultado);
    }

    public function test_permite_reabertura_sem_plano_de_trabalhos_criado(): void
    {
        $utilizador = User::factory()->create();
        $piscina = Pool::factory()->create();

        app(PoolClosureService::class)->encerrar($piscina, [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => '2026-08-03',
        ], $utilizador);

        $resultado = app(PoolClosureService::class)->reabrir($piscina->fresh(), $utilizador, Carbon::parse('2026-09-15'));

        $this->assertNotNull($resultado);
    }
}
