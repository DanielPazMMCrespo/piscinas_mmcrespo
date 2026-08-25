<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Constants\TrabalhoParagem;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\PoolClosureTask;
use App\Models\User;
use App\Services\CacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Expectation;
use Tests\TestCase;

class PoolClosureTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_pertence_a_encerramento_e_utilizador(): void
    {
        $user = User::factory()->create();
        $encerramento = PoolClosure::factory()->create();

        $tarefa = PoolClosureTask::factory()->executado(
            Carbon::parse('2026-08-20 14:30:00'),
            $user
        )->create([
            'pool_closure_id' => $encerramento->id,
            'tipo' => TrabalhoParagem::LIMPEZA_TANQUE,
        ]);

        $this->assertTrue($tarefa->encerramento->is($encerramento));
        $this->assertTrue($tarefa->executadoPor->is($user));
        $this->assertSame('2026-08-20 14:30:00', $tarefa->executado_em->format('Y-m-d H:i:s'));
    }

    public function test_scope_obrigatorios_em_falta(): void
    {
        $encerramento = PoolClosure::factory()->create();

        // 1. Obrigatório previsto -> em falta
        $t1 = PoolClosureTask::factory()->create([
            'pool_closure_id' => $encerramento->id,
            'tipo' => TrabalhoParagem::LIMPEZA_TANQUE,
            'obrigatorio' => true,
            'estado' => TrabalhoParagem::ESTADO_PREVISTO,
        ]);

        // 2. Obrigatório em curso -> em falta
        $t2 = PoolClosureTask::factory()->create([
            'pool_closure_id' => $encerramento->id,
            'tipo' => TrabalhoParagem::SUPERCLORACAO,
            'obrigatorio' => true,
            'estado' => TrabalhoParagem::ESTADO_EM_CURSO,
        ]);

        // 3. Obrigatório não executado -> em falta
        $t3 = PoolClosureTask::factory()->naoExecutado('Sem acesso')->create([
            'pool_closure_id' => $encerramento->id,
            'tipo' => TrabalhoParagem::REPOSICAO_CLORO,
            'obrigatorio' => true,
        ]);

        // 4. Obrigatório executado -> NÃO em falta
        $t4 = PoolClosureTask::factory()->executado()->create([
            'pool_closure_id' => $encerramento->id,
            'tipo' => TrabalhoParagem::DESINFECAO_LEGIONELLA,
            'obrigatorio' => true,
        ]);

        // 5. Obrigatório não aplicável -> NÃO em falta
        $t5 = PoolClosureTask::factory()->naoAplicavel('Sem tanque de compensação')->create([
            'pool_closure_id' => $encerramento->id,
            'tipo' => TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO,
            'obrigatorio' => true,
        ]);

        // 6. Não obrigatório previsto -> NÃO em falta
        $t6 = PoolClosureTask::factory()->create([
            'pool_closure_id' => $encerramento->id,
            'tipo' => TrabalhoParagem::ESVAZIAMENTO_TANQUE,
            'obrigatorio' => false,
            'estado' => TrabalhoParagem::ESTADO_PREVISTO,
        ]);

        $emFalta = PoolClosureTask::obrigatoriosEmFalta()->pluck('id')->all();

        $this->assertContains($t1->id, $emFalta);
        $this->assertContains($t2->id, $emFalta);
        $this->assertContains($t3->id, $emFalta);
        $this->assertNotContains($t4->id, $emFalta);
        $this->assertNotContains($t5->id, $emFalta);
        $this->assertNotContains($t6->id, $emFalta);
    }

    public function test_labels_e_formatacao_dados(): void
    {
        $tarefa = PoolClosureTask::factory()->make([
            'tipo' => TrabalhoParagem::SUPERCLORACAO,
            'estado' => TrabalhoParagem::ESTADO_EXECUTADO,
            'origem' => TrabalhoParagem::ORIGEM_RECONSTRUIDA,
            'dados' => [
                'produto' => 'Hipoclorito de Sódio',
                'quantidade' => '50 L',
                'horas_contacto' => 12,
                'orp_atingido_mv' => 850,
                'cloro_livre_ppm' => 10.5,
            ],
        ]);

        $this->assertSame('Supercloração / choque de arranque', $tarefa->tipoLabel());
        $this->assertSame('Executado', $tarefa->estadoLabel());
        $this->assertSame('Reconstruída', $tarefa->origemLabel());

        $formatado = $tarefa->dadosFormatados();
        $this->assertStringContainsString('Produto: Hipoclorito de Sódio', $formatado);
        $this->assertStringContainsString('Quantidade: 50 L', $formatado);
        $this->assertStringContainsString('Tempo contacto: 12 h', $formatado);
        $this->assertStringContainsString('ORP: 850 mV', $formatado);
        $this->assertStringContainsString('Cl livre: 10,50 mg/L', $formatado);

        $tarefaSemDados = PoolClosureTask::factory()->make(['dados' => null]);
        $this->assertSame('—', $tarefaSemDados->dadosFormatados());

        $tarefaLegionella = PoolClosureTask::factory()->make([
            'tipo' => TrabalhoParagem::DESINFECAO_LEGIONELLA,
            'dados' => [
                'numero_boletim' => 'BOL-2026-99',
                'laboratorio' => 'Laboratório Central',
                'data_colheita' => '2026-08-15',
                'resultado' => '< 100 UFC/L (Conforme)',
                'zonas' => ['Tanque', 'Caleiras'],
            ],
        ]);

        $legionellaFmt = $tarefaLegionella->dadosFormatados();
        $this->assertStringContainsString('Boletim: BOL-2026-99', $legionellaFmt);
        $this->assertStringContainsString('Laboratório: Laboratório Central', $legionellaFmt);
        $this->assertStringContainsString('Colheita: 2026-08-15', $legionellaFmt);
        $this->assertStringContainsString('Resultado: < 100 UFC/L (Conforme)', $legionellaFmt);
        $this->assertStringContainsString('Zonas: Tanque, Caleiras', $legionellaFmt);
    }

    public function test_pool_closure_relacao_trabalhos_ordenados(): void
    {
        $encerramento = PoolClosure::factory()->create();

        $t3 = PoolClosureTask::factory()->create([
            'pool_closure_id' => $encerramento->id,
            'ordem' => 3,
            'tipo' => TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO,
        ]);
        $t1 = PoolClosureTask::factory()->create([
            'pool_closure_id' => $encerramento->id,
            'ordem' => 1,
            'tipo' => TrabalhoParagem::ESVAZIAMENTO_TANQUE,
        ]);
        $t2 = PoolClosureTask::factory()->create([
            'pool_closure_id' => $encerramento->id,
            'ordem' => 2,
            'tipo' => TrabalhoParagem::LIMPEZA_TANQUE,
        ]);

        $trabalhos = $encerramento->trabalhos;

        $this->assertCount(3, $trabalhos);
        $this->assertSame([$t1->id, $t2->id, $t3->id], $trabalhos->pluck('id')->all());
    }

    public function test_apagar_encerramento_apaga_trabalhos_em_cascata(): void
    {
        $encerramento = PoolClosure::factory()->create();

        PoolClosureTask::factory()->count(3)->create([
            'pool_closure_id' => $encerramento->id,
        ]);

        $this->assertSame(3, PoolClosureTask::where('pool_closure_id', $encerramento->id)->count());

        $encerramento->delete();

        $this->assertSame(0, PoolClosureTask::where('pool_closure_id', $encerramento->id)->count());
    }

    public function test_invalidacao_de_cache_ao_salvar_e_apagar(): void
    {
        $piscina = Pool::factory()->create();
        $encerramento = PoolClosure::factory()->create(['pool_id' => $piscina->id]);

        $this->mock(CacheService::class, function ($mock) use ($piscina): void {
            /** @var Expectation $exp1 */
            $exp1 = $mock->shouldReceive('invalidateAllAlerts');
            $exp1->atLeast()->once();

            /** @var Expectation $exp2 */
            $exp2 = $mock->shouldReceive('invalidateGraphCache');
            $exp2->with($piscina->id)->atLeast()->once();
        });

        $tarefa = PoolClosureTask::factory()->create([
            'pool_closure_id' => $encerramento->id,
        ]);

        $tarefa->update(['estado' => TrabalhoParagem::ESTADO_EXECUTADO]);
        $tarefa->delete();

        $this->assertDatabaseMissing('pool_closure_tasks', [
            'id' => $tarefa->id,
        ]);
    }
}
