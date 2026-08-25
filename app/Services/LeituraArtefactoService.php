<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\TrabalhoParagem;
use App\Models\DailyRecord;
use App\Models\FilterCheck;
use App\Models\OperationalAction;
use App\Models\PoolClosure;
use App\Models\PoolClosureTask;
use App\Models\SensorOutage;
use Carbon\Carbon;

/**
 * Determina janelas temporais em que as leituras do controlador Hanna são
 * artefacto (inválidas) porque a água não circula normalmente no sensor:
 * durante uma lavagem/enxaguamento de filtro, ou com a bomba parada — e ainda
 * os períodos em que a própria sonda foi declarada indisponível (SensorOutage),
 * ou durante operações de paragem técnica (supercloração, desinfeção de choque, tanque vazio).
 *
 * Uma leitura dentro destas janelas não deve contar como não-conformidade —
 * é uma causa conhecida, não um problema de qualidade da água. Fonte única
 * usada por PDF, dashboard, esquema, alertas e gráficos.
 */
class LeituraArtefactoService
{
    /** Duração assumida de uma lavagem quando não é indicada (minutos). */
    private const DURACAO_DEFAULT_MIN = 20;

    /** Margem para o fluxo estabilizar após a operação (minutos). */
    private const ESTABILIZACAO_MIN = 10;

    /** Recuo para apanhar operações iniciadas pouco antes do período. */
    private const LOOKBACK_HORAS = 6;

    /** Recuo para conhecer o estado de bomba anterior ao período. */
    private const BOMBA_LOOKBACK_DIAS = 2;

    /**
     * Recuo para apanhar uma supercloração iniciada antes do período cuja
     * janela (contacto + 12h de estabilização) ainda o alcança. Folgado sobre
     * as 24h+12h por omissão, sem varrer a tabela inteira.
     */
    private const PARAGEM_LOOKBACK_DIAS = 7;

    /**
     * Janelas de artefacto que se sobrepõem ao período [de, ate], recortadas
     * ao período.
     *
     * @return array<int, array{inicio: Carbon, fim: Carbon, motivo: string}>
     */
    public function janelas(int $poolId, Carbon $de, Carbon $ate): array
    {
        $todas = array_merge(
            $this->janelasLavagem($poolId, $de, $ate),
            $this->janelasBombaParada($poolId, $de, $ate),
            $this->janelasSondaIndisponivel($poolId, $de, $ate),
            $this->janelasSupercloracao($poolId, $de, $ate),
            $this->janelasTanqueVazio($poolId, $de, $ate),
        );

        $resultado = [];
        foreach ($todas as $janela) {
            if ($janela['fim']->lt($de) || $janela['inicio']->gt($ate)) {
                continue;
            }

            $resultado[] = [
                'inicio' => $janela['inicio']->lt($de) ? $de->copy() : $janela['inicio'],
                'fim' => $janela['fim']->gt($ate) ? $ate->copy() : $janela['fim'],
                'motivo' => $janela['motivo'],
            ];
        }

        return $resultado;
    }

    /** Motivo de artefacto no instante $t, ou null se a leitura é válida. */
    public function motivoEm(int $poolId, Carbon $t): ?string
    {
        foreach ($this->janelas($poolId, $t, $t) as $janela) {
            return $janela['motivo'];
        }

        return null;
    }

    /**
     * @return array<int, array{inicio: Carbon, fim: Carbon, motivo: string}>
     */
    private function janelasLavagem(int $poolId, Carbon $de, Carbon $ate): array
    {
        $inicioQuery = $de->copy()->subHours(self::LOOKBACK_HORAS);
        $janelas = [];

        $motivos = [
            OperationalAction::TIPO_LAVAGEM_FILTRO => 'Lavagem de filtro',
            OperationalAction::TIPO_ENXAGUAMENTO_FILTRO => 'Enxaguamento de filtro',
        ];

        $acoes = OperationalAction::query()
            ->where('pool_id', $poolId)
            ->whereIn('tipo', array_keys($motivos))
            ->whereBetween('registado_em', [$inicioQuery, $ate])
            ->get();

        foreach ($acoes as $acao) {
            $duracao = (int) ($acao->dados['duracao_min'] ?? 0);
            $janelas[] = $this->janela($acao->registado_em, $duracao, $motivos[$acao->tipo]);
        }

        $lavagens = DailyRecord::query()
            ->where('pool_id', $poolId)
            ->where('filtro_faz_retrolavagem', true)
            ->whereDoesntHave('correcoes')
            ->whereBetween('registado_em', [$inicioQuery, $ate])
            ->get();

        foreach ($lavagens as $registo) {
            $janelas[] = $this->janela($registo->registado_em, 0, 'Lavagem de filtro');
        }

        $filterChecks = FilterCheck::query()
            ->where('pool_id', $poolId)
            ->whereIn('tipo_operacao', ['lavagem', 'enxaguamento'])
            ->whereBetween('verificado_em', [$inicioQuery, $ate])
            ->get();

        foreach ($filterChecks as $check) {
            $motivo = $check->tipo_operacao === 'enxaguamento' ? 'Enxaguamento de filtro' : 'Lavagem de filtro';
            $janelas[] = $this->janela($check->verificado_em, 0, $motivo);
        }

        return $janelas;
    }

    /**
     * Períodos em que a sonda foi declarada indisponível por ação operacional
     * (peça partida, calibração, leituras não fiáveis...). Enquanto a avaria está
     * em aberto a janela estende-se até ao fim do período consultado: o que o
     * controlador manda nesse intervalo não conta para a conformidade.
     *
     * @return array<int, array{inicio: Carbon, fim: Carbon, motivo: string}>
     */
    private function janelasSondaIndisponivel(int $poolId, Carbon $de, Carbon $ate): array
    {
        return SensorOutage::query()
            ->where('pool_id', $poolId)
            ->where('aberta_em', '<=', $ate)
            ->where(fn ($q) => $q->whereNull('resolvida_em')->orWhere('resolvida_em', '>=', $de))
            ->get()
            ->map(fn (SensorOutage $avaria) => [
                'inicio' => $avaria->aberta_em->copy(),
                'fim' => ($avaria->resolvida_em ?? $ate)->copy(),
                'motivo' => $avaria->resumo(),
            ])
            ->all();
    }

    /** @return array{inicio: Carbon, fim: Carbon, motivo: string} */
    private function janela(Carbon $inicio, int $duracaoMin, string $motivo): array
    {
        $duracao = $duracaoMin > 0 ? $duracaoMin : self::DURACAO_DEFAULT_MIN;

        return [
            'inicio' => $inicio->copy(),
            'fim' => $inicio->copy()->addMinutes($duracao + self::ESTABILIZACAO_MIN),
            'motivo' => $motivo,
        ];
    }

    /**
     * Intervalos em que a bomba esteve parada: cada evento bomba_ferrada=false
     * abre um intervalo que fecha no evento seguinte com bomba_ferrada=true (ou
     * no fim do período). Combina ações operacionais e registos diários.
     *
     * @return array<int, array{inicio: Carbon, fim: Carbon, motivo: string}>
     */
    private function janelasBombaParada(int $poolId, Carbon $de, Carbon $ate): array
    {
        $inicioQuery = $de->copy()->subDays(self::BOMBA_LOOKBACK_DIAS);

        $eventos = [];

        $acoes = OperationalAction::query()
            ->where('pool_id', $poolId)
            ->where('tipo', OperationalAction::TIPO_BOMBA)
            ->whereBetween('registado_em', [$inicioQuery, $ate])
            ->get();

        foreach ($acoes as $acao) {
            $ferrada = $acao->dados['bomba_ferrada'] ?? null;
            if ($ferrada !== null) {
                $eventos[] = ['ts' => $acao->registado_em, 'ferrada' => (bool) $ferrada];
            }
        }

        $registos = DailyRecord::query()
            ->where('pool_id', $poolId)
            ->whereNotNull('bomba_ferrada')
            ->whereDoesntHave('correcoes')
            ->whereBetween('registado_em', [$inicioQuery, $ate])
            ->get();

        foreach ($registos as $registo) {
            $eventos[] = ['ts' => $registo->registado_em, 'ferrada' => (bool) $registo->bomba_ferrada];
        }

        usort($eventos, fn ($a, $b) => $a['ts']->timestamp <=> $b['ts']->timestamp);

        $janelas = [];
        $inicioParada = null;

        foreach ($eventos as $evento) {
            if (! $evento['ferrada'] && $inicioParada === null) {
                $inicioParada = $evento['ts'];
            } elseif ($evento['ferrada'] && $inicioParada !== null) {
                $janelas[] = ['inicio' => $inicioParada->copy(), 'fim' => $evento['ts']->copy(), 'motivo' => 'Bomba parada'];
                $inicioParada = null;
            }
        }

        if ($inicioParada !== null) {
            $janelas[] = ['inicio' => $inicioParada->copy(), 'fim' => $ate->copy(), 'motivo' => 'Bomba parada'];
        }

        return $janelas;
    }

    /**
     * Janelas de supercloração e desinfeção de choque / Legionella executadas.
     * Só conta quando estado = EXECUTADO e executado_em não é nulo.
     *
     * @return array<int, array{inicio: Carbon, fim: Carbon, motivo: string}>
     */
    private function janelasSupercloracao(int $poolId, Carbon $de, Carbon $ate): array
    {
        $tarefas = PoolClosureTask::query()
            ->whereIn('tipo', [TrabalhoParagem::SUPERCLORACAO, TrabalhoParagem::DESINFECAO_LEGIONELLA])
            ->where('estado', TrabalhoParagem::ESTADO_EXECUTADO)
            ->whereNotNull('executado_em')
            ->where('executado_em', '<=', $ate)
            ->where('executado_em', '>=', $de->copy()->subDays(self::PARAGEM_LOOKBACK_DIAS))
            ->whereHas('encerramento', fn ($q) => $q->where('pool_id', $poolId))
            ->get();

        $janelas = [];
        foreach ($tarefas as $tarefa) {
            /** @var Carbon $inicio */
            $inicio = $tarefa->executado_em;
            $horasContacto = (int) ($tarefa->dados['horas_contacto'] ?? $tarefa->dados['tempo_contacto_horas'] ?? 24);
            // Horas de contacto + 12h de estabilização pós-choque
            $fim = $inicio->copy()->addHours(max(1, $horasContacto) + 12);
            $motivo = $tarefa->tipo === TrabalhoParagem::DESINFECAO_LEGIONELLA ? 'Desinfeção Legionella' : 'Supercloração';

            $janelas[] = [
                'inicio' => $inicio->copy(),
                'fim' => $fim,
                'motivo' => $motivo,
            ];
        }

        return $janelas;
    }

    /**
     * Janelas em que o tanque esteve vazio (após esvaziamento executado até ao enchimento ou fim da paragem).
     * Só conta quando estado = EXECUTADO e executado_em não é nulo.
     *
     * @return array<int, array{inicio: Carbon, fim: Carbon, motivo: string}>
     */
    private function janelasTanqueVazio(int $poolId, Carbon $de, Carbon $ate): array
    {
        $tarefas = PoolClosureTask::query()
            ->where('tipo', TrabalhoParagem::ESVAZIAMENTO_TANQUE)
            ->where('estado', TrabalhoParagem::ESTADO_EXECUTADO)
            ->whereNotNull('executado_em')
            ->where('executado_em', '<=', $ate)
            // A janela do tanque vazio fecha no enchimento ou no fim do
            // encerramento; se o encerramento não interseta o período pedido,
            // a janela também não. queIntersetam é a fonte única desse teste.
            ->whereHas('encerramento', fn ($q) => $q->where('pool_id', $poolId)->queIntersetam($de, $ate))
            ->with(['encerramento.trabalhos'])
            ->get();

        $janelas = [];
        foreach ($tarefas as $tarefa) {
            /** @var Carbon $inicio */
            $inicio = $tarefa->executado_em;

            // Procurar se há enchimento executado posterior no mesmo encerramento
            /** @var PoolClosure|null $encerramento */
            $encerramento = $tarefa->encerramento;
            $enchimento = $encerramento?->trabalhos
                ->where('tipo', TrabalhoParagem::ENCHIMENTO_TANQUE)
                ->where('estado', TrabalhoParagem::ESTADO_EXECUTADO)
                ->whereNotNull('executado_em')
                ->where('executado_em', '>=', $inicio)
                ->sortBy('executado_em')
                ->first();

            if ($enchimento !== null && $enchimento->executado_em !== null) {
                $fim = $enchimento->executado_em->copy();
            } elseif ($encerramento?->fim !== null) {
                $fim = $encerramento->fim->copy()->endOfDay();
            } else {
                $fim = $ate->copy();
            }

            $janelas[] = [
                'inicio' => $inicio->copy(),
                'fim' => $fim,
                'motivo' => 'Tanque vazio',
            ];
        }

        return $janelas;
    }
}
