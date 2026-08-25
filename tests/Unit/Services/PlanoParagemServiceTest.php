<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Constants\TrabalhoParagem;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\PoolClosureTask;
use App\Models\User;
use App\Services\PlanoParagemService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlanoParagemServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanoParagemService $service;

    private User $user;

    private PoolClosure $closure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PlanoParagemService::class);
        $this->user = User::factory()->create();

        $pool = Pool::factory()->create();
        $this->closure = PoolClosure::factory()->create([
            'pool_id' => $pool->id,
            'inicio' => Carbon::parse('2026-08-01'),
            'fim' => Carbon::parse('2026-08-15'),
        ]);
    }

    public function test_criar_plano_completo_a_partir_do_template(): void
    {
        $tarefas = $this->service->criarPlano($this->closure, $this->user);

        $this->assertCount(13, $tarefas);
        $this->assertEquals(13, $this->closure->trabalhos()->count());

        $ordens = $tarefas->pluck('ordem')->all();
        $this->assertEquals(range(1, 13), $ordens);

        foreach ($tarefas as $tarefa) {
            $this->assertEquals(TrabalhoParagem::ESTADO_PREVISTO, $tarefa->estado);
            $this->assertEquals(TrabalhoParagem::ORIGEM_DECLARADA, $tarefa->origem);
            $this->assertNull($tarefa->previsto_para);
            $this->assertNull($tarefa->executado_em);
            $this->assertNull($tarefa->executado_por);
            $this->assertNull($tarefa->motivo_nao_execucao);
        }
    }

    public function test_nao_permite_criar_plano_duplicado(): void
    {
        $this->service->criarPlano($this->closure, $this->user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A paragem técnica já tem um plano de trabalhos criado.');

        $this->service->criarPlano($this->closure, $this->user);
    }

    public function test_acrescentar_trabalho_avulso(): void
    {
        $this->service->criarPlano($this->closure, $this->user);

        $novoTrabalho = $this->service->acrescentarTrabalho($this->closure, [
            'tipo' => TrabalhoParagem::OUTRO,
            'obrigatorio' => false,
            'previsto_para' => '2026-08-10',
            'observacoes' => 'Pintura dos balneários',
        ], $this->user);

        $this->assertEquals(14, $novoTrabalho->ordem);
        $this->assertEquals(TrabalhoParagem::OUTRO, $novoTrabalho->tipo);
        $this->assertEquals('2026-08-10', $novoTrabalho->previsto_para?->toDateString());
        $this->assertEquals('Pintura dos balneários', $novoTrabalho->observacoes);
        $this->assertEquals(14, $this->closure->trabalhos()->count());
    }

    public function test_acrescentar_trabalho_com_tipo_invalido_lanca_excecao(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tipo de trabalho inválido.');

        $this->service->acrescentarTrabalho($this->closure, [
            'tipo' => 'tipo_inexistente',
        ], $this->user);
    }

    public function test_marcar_executado_regista_autor_e_data(): void
    {
        $tarefas = $this->service->criarPlano($this->closure, $this->user);
        /** @var PoolClosureTask $tarefaTanque */
        $tarefaTanque = $tarefas->firstWhere('tipo', TrabalhoParagem::LIMPEZA_TANQUE);

        $agora = Carbon::parse('2026-08-05 14:30:00');
        Carbon::setTestNow($agora);

        $atualizada = $this->service->marcarExecutado($tarefaTanque, [
            'executado_em' => $agora,
            'observacoes' => 'Tanque escovado e desinfetado',
            'fotos' => ['paragens/foto1.jpg'],
        ], $this->user);

        $this->assertEquals(TrabalhoParagem::ESTADO_EXECUTADO, $atualizada->estado);
        $this->assertEquals($agora->toDateTimeString(), $atualizada->executado_em?->toDateTimeString());
        $this->assertEquals($this->user->id, $atualizada->executado_por);
        $this->assertNull($atualizada->motivo_nao_execucao);
        $this->assertEquals(['paragens/foto1.jpg'], $atualizada->fotos);
        $this->assertEquals('Tanque escovado e desinfetado', $atualizada->observacoes);

        Carbon::setTestNow();
    }

    public function test_marcar_executado_legionella_exige_boletim(): void
    {
        $tarefas = $this->service->criarPlano($this->closure, $this->user);
        /** @var PoolClosureTask $tarefaLegionella */
        $tarefaLegionella = $tarefas->firstWhere('tipo', TrabalhoParagem::DESINFECAO_LEGIONELLA);

        try {
            $this->service->marcarExecutado($tarefaLegionella, [
                'documentos' => [],
            ], $this->user);
            $this->fail('Deveria ter lançado DomainException por falta de boletim analítico.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('boletim analítico acreditado', $e->getMessage());
        }

        $sucesso = $this->service->marcarExecutado($tarefaLegionella, [
            'documentos' => ['paragens/boletim_legionella.pdf'],
            'dados' => ['laboratorio' => 'LabQualidade', 'resultado' => '< 100 UFC/L'],
        ], $this->user);

        $this->assertEquals(TrabalhoParagem::ESTADO_EXECUTADO, $sucesso->estado);
        $this->assertEquals(['paragens/boletim_legionella.pdf'], $sucesso->documentos);
    }

    public function test_marcar_nao_executado_exige_motivo(): void
    {
        $tarefas = $this->service->criarPlano($this->closure, $this->user);
        /** @var PoolClosureTask $tarefa */
        $tarefa = $tarefas->first();

        try {
            $this->service->marcarNaoExecutado($tarefa, '   ', $this->user);
            $this->fail('Deveria ter lançado DomainException por motivo vazio.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('motivo de não execução é obrigatório', $e->getMessage());
        }

        $atualizada = $this->service->marcarNaoExecutado($tarefa, 'Falta de material de filtragem', $this->user);

        $this->assertEquals(TrabalhoParagem::ESTADO_NAO_EXECUTADO, $atualizada->estado);
        $this->assertEquals('Falta de material de filtragem', $atualizada->motivo_nao_execucao);
        $this->assertNull($atualizada->executado_em);
        $this->assertNull($atualizada->executado_por);
    }

    public function test_marcar_nao_aplicavel_exige_justificacao(): void
    {
        $tarefas = $this->service->criarPlano($this->closure, $this->user);
        /** @var PoolClosureTask $tarefaTanqueCompensacao */
        $tarefaTanqueCompensacao = $tarefas->firstWhere('tipo', TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO);

        try {
            $this->service->marcarNaoAplicavel($tarefaTanqueCompensacao, '', $this->user);
            $this->fail('Deveria ter lançado DomainException por justificação vazia.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('justificação de não aplicabilidade é obrigatória', $e->getMessage());
        }

        $atualizada = $this->service->marcarNaoAplicavel(
            $tarefaTanqueCompensacao,
            'Piscina do tipo skimmers, sem tanque de compensação',
            $this->user
        );

        $this->assertEquals(TrabalhoParagem::ESTADO_NAO_APLICAVEL, $atualizada->estado);
        $this->assertEquals('Piscina do tipo skimmers, sem tanque de compensação', $atualizada->motivo_nao_execucao);
    }

    public function test_resumo_calcula_totais_e_obrigatorios_em_falta(): void
    {
        $tarefas = $this->service->criarPlano($this->closure, $this->user);

        // Inicial: 13 tarefas, 0 executadas, 6 obrigatórias em falta, 13 pendentes
        $resumoInicial = $this->service->resumo($this->closure);
        $this->assertEquals(13, $resumoInicial['total']);
        $this->assertEquals(0, $resumoInicial['executados']);
        $this->assertEquals(6, $resumoInicial['obrigatorios_em_falta']);
        $this->assertCount(13, $resumoInicial['pendentes']);

        // Executar 1 obrigatório (Limpeza do Tanque)
        /** @var PoolClosureTask $limpezaTanque */
        $limpezaTanque = $tarefas->firstWhere('tipo', TrabalhoParagem::LIMPEZA_TANQUE);
        $this->service->marcarExecutado($limpezaTanque, [], $this->user);

        // Marcar 1 obrigatório como Não Aplicável (Tanque de Compensação)
        /** @var PoolClosureTask $tanqueCompensacao */
        $tanqueCompensacao = $tarefas->firstWhere('tipo', TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO);
        $this->service->marcarNaoAplicavel($tanqueCompensacao, 'Piscina de skimmers', $this->user);

        // Marcar 1 não-obrigatório como Executado (Caleiras)
        /** @var PoolClosureTask $caleiras */
        $caleiras = $tarefas->firstWhere('tipo', TrabalhoParagem::LIMPEZA_CALEIRAS);
        $this->service->marcarExecutado($caleiras, [], $this->user);

        $resumo = $this->service->resumo($this->closure->fresh());
        $this->assertEquals(13, $resumo['total']);
        $this->assertEquals(2, $resumo['executados']);
        // 6 obrigatórios iniciais - 1 executado - 1 não aplicável = 4 obrigatórios em falta
        $this->assertEquals(4, $resumo['obrigatorios_em_falta']);
        // 13 - 2 executados - 1 não aplicável = 10 pendentes
        $this->assertCount(10, $resumo['pendentes']);
    }

    public function test_evidencia_operacional_filtra_por_piscina_e_intervalo(): void
    {
        $poolId = (int) $this->closure->pool_id;
        /** @var Pool $outraPiscina */
        $outraPiscina = Pool::factory()->create();

        // 1. Ação dentro da janela da piscina correta
        /** @var OperationalAction $acaoDentro */
        $acaoDentro = OperationalAction::create([
            'pool_id' => $poolId,
            'user_id' => $this->user->id,
            'registado_em' => Carbon::parse('2026-08-05 10:00:00'),
            'tipo' => OperationalAction::TIPO_TRATAMENTO_CHOQUE,
        ]);

        // 2. Ação antes da janela
        OperationalAction::create([
            'pool_id' => $poolId,
            'user_id' => $this->user->id,
            'registado_em' => Carbon::parse('2026-07-31 23:59:59'),
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
        ]);

        // 3. Ação depois da janela
        OperationalAction::create([
            'pool_id' => $poolId,
            'user_id' => $this->user->id,
            'registado_em' => Carbon::parse('2026-08-16 00:00:01'),
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
        ]);

        // 4. Ação dentro da janela mas de outra piscina
        OperationalAction::create([
            'pool_id' => $outraPiscina->id,
            'user_id' => $this->user->id,
            'registado_em' => Carbon::parse('2026-08-05 10:00:00'),
            'tipo' => OperationalAction::TIPO_TRATAMENTO_CHOQUE,
        ]);

        $evidencias = $this->service->evidenciaOperacional($this->closure);

        $this->assertCount(1, $evidencias);
        $this->assertEquals($acaoDentro->id, $evidencias->first()?->id);
    }
}
