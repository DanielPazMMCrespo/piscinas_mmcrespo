<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyRecord;
use App\Models\FilterCheck;
use App\Models\OperationalAction;
use Carbon\Carbon;

/**
 * Determina janelas temporais em que as leituras do controlador Hanna são
 * artefacto (inválidas) porque a água não circula normalmente no sensor:
 * durante uma lavagem/enxaguamento de filtro, ou com a bomba parada.
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
}
