<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Constants\MotivoEncerramento;
use App\Models\AlertState;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\TapAlert;
use App\Models\User;
use App\Services\PoolClosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PoolClosureServiceTest extends TestCase
{
    use RefreshDatabase;

    private PoolClosureService $servico;

    private User $utilizador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servico = app(PoolClosureService::class);
        $this->utilizador = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_encerra_uma_piscina_com_motivo_e_periodo(): void
    {
        $piscina = Pool::factory()->create();

        $encerramento = $this->servico->encerrar($piscina, [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => '2026-08-03',
            'fim' => '2026-09-30',
            'observacoes' => 'Fim da época balnear.',
        ], $this->utilizador);

        $this->assertSame(MotivoEncerramento::EPOCA_BALNEAR, $encerramento->motivo);
        $this->assertFalse($encerramento->agua_em_tratamento);
        $this->assertSame($this->utilizador->id, $encerramento->encerrada_por);
        $this->assertTrue($piscina->fresh()->estaEncerradaEm(Carbon::parse('2026-08-10')));
    }

    public function test_rejeita_motivo_invalido(): void
    {
        $this->expectException(\DomainException::class);

        $this->servico->encerrar(Pool::factory()->create(), [
            'motivo' => 'ferias_do_tecnico',
        ], $this->utilizador);
    }

    public function test_rejeita_fim_anterior_ao_inicio(): void
    {
        $this->expectException(\DomainException::class);

        $this->servico->encerrar(Pool::factory()->create(), [
            'motivo' => MotivoEncerramento::OBRA,
            'inicio' => '2026-08-10',
            'fim' => '2026-08-01',
        ], $this->utilizador);
    }

    public function test_rejeita_encerramento_sobreposto(): void
    {
        $piscina = Pool::factory()->create();

        $this->servico->encerrar($piscina, [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => '2026-08-01',
            'fim' => '2026-09-30',
        ], $this->utilizador);

        $this->expectException(\DomainException::class);

        $this->servico->encerrar($piscina->fresh(), [
            'motivo' => MotivoEncerramento::OBRA,
            'inicio' => '2026-09-15',
            'fim' => '2026-10-15',
        ], $this->utilizador);
    }

    public function test_permite_encerramentos_consecutivos_sem_sobreposicao(): void
    {
        $piscina = Pool::factory()->create();

        $this->servico->encerrar($piscina, [
            'motivo' => MotivoEncerramento::OBRA,
            'inicio' => '2026-06-01',
            'fim' => '2026-06-30',
        ], $this->utilizador);

        $this->servico->encerrar($piscina->fresh(), [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => '2026-07-01',
            'fim' => '2026-08-31',
        ], $this->utilizador);

        $this->assertSame(2, $piscina->encerramentos()->count());
    }

    public function test_encerrar_resolve_torneiras_e_cartoes_pendentes(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00');

        $piscina = Pool::factory()->create();

        $torneira = TapAlert::create([
            'pool_id' => $piscina->id,
            'opened_at' => Carbon::now()->subHours(5),
        ]);

        $cartao = AlertState::create([
            'alert_key' => "sem_registo|{$piscina->id}|2026-08-03",
            'status' => 'pendente',
            'moved_at' => Carbon::now(),
        ]);

        $this->servico->encerrar($piscina, [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => '2026-08-03',
        ], $this->utilizador);

        $this->assertNotNull($torneira->fresh()->resolved_at);
        $this->assertSame('resolvido', $cartao->fresh()->status);
    }

    public function test_reabrir_grava_fim_no_dia_anterior_a_reabertura(): void
    {
        $piscina = Pool::factory()->create();

        $this->servico->encerrar($piscina, [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => '2026-08-03',
        ], $this->utilizador);

        $encerramento = $this->servico->reabrir(
            $piscina->fresh(),
            $this->utilizador,
            Carbon::parse('2026-09-15'),
        );

        $this->assertNotNull($encerramento);
        $this->assertSame('2026-09-14', $encerramento->fim->toDateString());
        $this->assertSame($this->utilizador->id, $encerramento->reaberta_por);
        $this->assertNotNull($encerramento->reaberta_em);

        $recarregada = $piscina->fresh();
        $this->assertTrue($recarregada->estaEncerradaEm(Carbon::parse('2026-09-14')));
        $this->assertFalse($recarregada->estaEncerradaEm(Carbon::parse('2026-09-15')));
    }

    public function test_desfazer_no_mesmo_dia_apaga_o_encerramento(): void
    {
        Carbon::setTestNow('2026-08-03 14:00:00');

        $piscina = Pool::factory()->create();

        $this->servico->encerrar($piscina, [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => '2026-08-03',
        ], $this->utilizador);

        $resultado = $this->servico->reabrir($piscina->fresh(), $this->utilizador);

        $this->assertNull($resultado);
        $this->assertSame(0, PoolClosure::count());
        $this->assertFalse($piscina->fresh()->estaEncerradaEm());
    }

    public function test_reabrir_piscina_aberta_falha(): void
    {
        $this->expectException(\DomainException::class);

        $this->servico->reabrir(Pool::factory()->create(), $this->utilizador);
    }

    public function test_encerrar_em_lote_reporta_erros_por_piscina(): void
    {
        $limpa = Pool::factory()->create();
        $jaEncerrada = Pool::factory()->create();

        $this->servico->encerrar($jaEncerrada, [
            'motivo' => MotivoEncerramento::OBRA,
            'inicio' => '2026-08-01',
        ], $this->utilizador);

        $resultado = $this->servico->encerrarEmLote(
            collect([$limpa, $jaEncerrada->fresh()]),
            ['motivo' => MotivoEncerramento::EPOCA_BALNEAR, 'inicio' => '2026-08-03'],
            $this->utilizador,
        );

        $this->assertCount(1, $resultado['encerradas']);
        $this->assertArrayHasKey($jaEncerrada->nome_completo, $resultado['erros']);
    }

    public function test_mapa_responde_sem_queries_adicionais(): void
    {
        $piscina = Pool::factory()->create();
        $outra = Pool::factory()->create();

        PoolClosure::factory()->periodo(
            Carbon::parse('2026-08-03'),
            Carbon::parse('2026-08-10'),
        )->create(['pool_id' => $piscina->id]);

        $mapa = $this->servico->mapa(
            [$piscina->id, $outra->id],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
        );

        $this->assertTrue(PoolClosureService::encerradaNoMapa($mapa, $piscina->id, Carbon::parse('2026-08-05')));
        $this->assertFalse(PoolClosureService::encerradaNoMapa($mapa, $piscina->id, Carbon::parse('2026-08-11')));
        $this->assertFalse(PoolClosureService::encerradaNoMapa($mapa, $outra->id, Carbon::parse('2026-08-05')));
    }

    public function test_mapa_inclui_periodos_que_apenas_intersetam_a_janela(): void
    {
        $piscina = Pool::factory()->create();

        PoolClosure::factory()->periodo(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-08-15'),
        )->create(['pool_id' => $piscina->id]);

        $mapa = $this->servico->mapa(
            [$piscina->id],
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
        );

        $this->assertTrue(PoolClosureService::encerradaNoMapa($mapa, $piscina->id, Carbon::parse('2026-08-01')));
        $this->assertTrue(PoolClosureService::encerradaNoMapa($mapa, $piscina->id, Carbon::parse('2026-08-15')));
        $this->assertFalse(PoolClosureService::encerradaNoMapa($mapa, $piscina->id, Carbon::parse('2026-08-16')));
    }
}
