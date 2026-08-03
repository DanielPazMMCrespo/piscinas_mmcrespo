<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Constants\MotivoEncerramento;
use App\Models\Pool;
use App\Models\PoolClosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PoolClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_trata_inicio_e_fim_como_dias_inclusivos(): void
    {
        $piscina = Pool::factory()->create();
        PoolClosure::factory()->periodo(
            Carbon::parse('2026-08-03'),
            Carbon::parse('2026-09-30'),
        )->create(['pool_id' => $piscina->id]);

        $this->assertFalse($piscina->estaEncerradaEm(Carbon::parse('2026-08-02')));
        $this->assertTrue($piscina->estaEncerradaEm(Carbon::parse('2026-08-03')));
        $this->assertTrue($piscina->estaEncerradaEm(Carbon::parse('2026-09-01')));
        $this->assertTrue($piscina->estaEncerradaEm(Carbon::parse('2026-09-30')));
        $this->assertFalse($piscina->estaEncerradaEm(Carbon::parse('2026-10-01')));
    }

    public function test_encerramento_sem_fim_fica_em_aberto(): void
    {
        $piscina = Pool::factory()->create();
        PoolClosure::factory()->periodo(Carbon::parse('2026-08-03'))->create(['pool_id' => $piscina->id]);

        $this->assertFalse($piscina->estaEncerradaEm(Carbon::parse('2026-08-02')));
        $this->assertTrue($piscina->estaEncerradaEm(Carbon::parse('2029-01-01')));
    }

    public function test_resolve_em_memoria_quando_a_relacao_esta_carregada(): void
    {
        $piscina = Pool::factory()->create();
        PoolClosure::factory()->periodo(
            Carbon::parse('2026-08-03'),
            Carbon::parse('2026-09-30'),
        )->create(['pool_id' => $piscina->id]);

        $carregada = Pool::with('encerramentos')->findOrFail($piscina->id);

        DB::enableQueryLog();
        $resultado = $carregada->estaEncerradaEm(Carbon::parse('2026-09-01'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($resultado);
        $this->assertSame([], $queries, 'estaEncerradaEm() nao deve consultar a BD com a relacao carregada.');
    }

    public function test_deriva_estado_operacional(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');

        $ativa = Pool::factory()->create();
        $encerrada = Pool::factory()->create();
        $desativada = Pool::factory()->create(['active' => false]);

        PoolClosure::factory()->periodo(Carbon::parse('2026-08-01'))->create(['pool_id' => $encerrada->id]);

        $this->assertSame(Pool::ESTADO_ATIVA, $ativa->estado_operacional);
        $this->assertSame(Pool::ESTADO_ENCERRADA, $encerrada->estado_operacional);
        $this->assertSame(Pool::ESTADO_DESATIVADA, $desativada->estado_operacional);
    }

    public function test_scopes_de_operacionais_e_encerradas(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');

        $aberta = Pool::factory()->create();
        $encerrada = Pool::factory()->create();
        $desativada = Pool::factory()->create(['active' => false]);
        $jaReaberta = Pool::factory()->create();

        PoolClosure::factory()->periodo(Carbon::parse('2026-08-01'))->create(['pool_id' => $encerrada->id]);
        PoolClosure::factory()->periodo(
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        )->create(['pool_id' => $jaReaberta->id]);

        $operacionais = Pool::operacionais()->pluck('id')->all();

        $this->assertContains($aberta->id, $operacionais);
        $this->assertContains($jaReaberta->id, $operacionais);
        $this->assertNotContains($encerrada->id, $operacionais);
        $this->assertNotContains($desativada->id, $operacionais);
        $this->assertSame([$encerrada->id], Pool::encerradasEm(Carbon::now())->pluck('id')->all());
    }

    public function test_calcula_dias_descricao_e_label(): void
    {
        $encerramento = PoolClosure::factory()->periodo(
            Carbon::parse('2026-08-03'),
            Carbon::parse('2026-08-05'),
        )->create();

        $this->assertSame(3, $encerramento->dias);
        $this->assertSame('de 03/08/2026 a 05/08/2026', $encerramento->descricao_periodo);
        $this->assertSame(
            MotivoEncerramento::labels()[MotivoEncerramento::EPOCA_BALNEAR],
            $encerramento->motivo_label,
        );

        $emAberto = PoolClosure::factory()->periodo(Carbon::parse('2026-08-03'))->create();

        $this->assertSame('desde 03/08/2026', $emAberto->descricao_periodo);
    }
}
