<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyRecord;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\SensorReading;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Correlação local entre o Potencial Redox (ORP) da sonda e o cloro livre
 * medido a DPD, para a mesma piscina.
 *
 * [AI_CONTEXT]
 * - O ORP não mede concentração: mede poder oxidante. O que este serviço faz é
 *   validar o ORP contra o método de referência (DPD manual) com os dados da
 *   própria instalação, que é o que torna a leitura da sonda defensável como
 *   fonte de cloro num documento legal.
 * - A correlação só vale dentro da gama de pH em que foi construída — o ORP
 *   move-se com o pH. Por isso a gama sai sempre no relatório, junto do valor.
 * - Nunca devolver uma estimativa sem `n`, `r` e a gama de pH: um número
 *   sozinho num relatório de auditoria é indefensável.
 */
class CorrelacaoOrpCloroService
{
    /** Distância máxima entre a colheita manual e a leitura da sonda a emparelhar. */
    private const TOLERANCIA_MINUTOS = 30;

    /** Abaixo disto não há amostra que sustente uma correlação. */
    private const MIN_PARES = 4;

    /** Correlação fraca não se usa para estimar — só se reporta. */
    private const MIN_R = 0.7;

    public function __construct(
        private readonly LeituraArtefactoService $artefactoService
    ) {}

    /**
     * Emparelha as medições manuais com a leitura de sonda mais próxima e
     * devolve a correlação resultante.
     *
     * @return array{
     *     pares: array<int, array{momento: CarbonInterface, orp: float, cloro_livre: float, ph: ?float, origem: string}>,
     *     n: int,
     *     r: ?float,
     *     declive: ?float,
     *     ordenada: ?float,
     *     ph_min: ?float,
     *     ph_max: ?float,
     *     orp_min_par: ?float,
     *     orp_max_par: ?float,
     *     utilizavel: bool,
     *     motivo_nao_validada: ?string
     * }
     */
    public function analisar(Pool $piscina, Carbon $de, Carbon $ate): array
    {
        $manuais = $this->medicoesManuais($piscina, $de, $ate);
        $pares = $this->emparelhar($piscina, $manuais);

        $n = count($pares);
        $orps = array_column($pares, 'orp');
        $cloros = array_column($pares, 'cloro_livre');
        $phs = array_values(array_filter(array_column($pares, 'ph'), fn ($v): bool => $v !== null));

        $r = $this->pearson($orps, $cloros);
        [$declive, $ordenada] = $this->regressao($orps, $cloros);

        return [
            'pares' => $pares,
            'n' => $n,
            'r' => $r,
            'declive' => $declive,
            'ordenada' => $ordenada,
            'ph_min' => $phs === [] ? null : min($phs),
            'ph_max' => $phs === [] ? null : max($phs),
            'orp_min_par' => $orps === [] ? null : min($orps),
            'orp_max_par' => $orps === [] ? null : max($orps),
            'utilizavel' => $n >= self::MIN_PARES && $r !== null && $r >= self::MIN_R && $declive !== null,
            'motivo_nao_validada' => $this->motivoNaoValidada($n, $r),
        ];
    }

    /**
     * Porque e que a correlacao nao serve. Dizer "amostra insuficiente" quando
     * ha 66 pares e uma explicacao errada num documento legal: o problema ai
     * nao e a quantidade, e a ausencia de relacao.
     */
    private function motivoNaoValidada(int $n, ?float $r): ?string
    {
        if ($n >= self::MIN_PARES && $r !== null && $r >= self::MIN_R) {
            return null;
        }

        if ($n < self::MIN_PARES) {
            return 'Não há pares suficientes: são precisos pelo menos '.self::MIN_PARES
                .' momentos com análise manual feita à hora a que a sonda está a ler.';
        }

        if ($r === null) {
            return 'Os pares não têm variação suficiente em ORP ou em cloro para se calcular uma relação.';
        }

        return 'Há pares em número bastante, mas sem relação utilizável entre as duas medidas (r = '
            .number_format($r, 3, ',', '').'; exigido '.number_format(self::MIN_R, 1, ',', '')
            .'). Tipicamente porque as leituras se concentram acima de 750 mV, onde a resposta do ORP satura, '
            .'ou porque o pH variou demasiado no período — o ORP move-se com o pH.';
    }

    /**
     * Cloro livre estimado para um dado ORP, pela reta local. Devolve null
     * quando a correlação não tem amostra ou força para o sustentar — é
     * preferível não dizer nada a inventar um número num documento legal.
     *
     * @param  array<string, mixed>  $correlacao
     */
    public function estimarCloro(array $correlacao, float $orp): ?float
    {
        if (($correlacao['utilizavel'] ?? false) !== true) {
            return null;
        }

        $estimado = ((float) $correlacao['declive'] * $orp) + (float) $correlacao['ordenada'];

        return $estimado <= 0 ? null : round($estimado, 2);
    }

    /**
     * Medições manuais de cloro livre no período: registos diários e análises
     * pontuais registadas como ação operacional.
     *
     * @return array<int, array{momento: CarbonInterface, cloro_livre: float, ph: ?float, origem: string}>
     */
    private function medicoesManuais(Pool $piscina, Carbon $de, Carbon $ate): array
    {
        $medicoes = [];

        $registos = DailyRecord::query()
            ->where('pool_id', $piscina->id)
            ->whereBetween('registado_em', [$de, $ate])
            ->whereDoesntHave('correcoes')
            ->orderBy('registado_em')
            ->get();

        foreach ($registos as $registo) {
            $cloro = $registo->cloro_livre_efetivo;
            if ($cloro === null) {
                continue;
            }

            $medicoes[] = [
                'momento' => $this->momentoDaColheita($registo),
                'cloro_livre' => (float) $cloro,
                'ph' => $registo->ph_efetivo === null ? null : (float) $registo->ph_efetivo,
                'origem' => 'Registo diário',
            ];
        }

        $analises = OperationalAction::query()
            ->where('pool_id', $piscina->id)
            ->where('tipo', OperationalAction::TIPO_ANALISE_PONTUAL)
            ->whereBetween('registado_em', [$de, $ate])
            ->orderBy('registado_em')
            ->get();

        foreach ($analises as $analise) {
            $dados = is_array($analise->dados) ? $analise->dados : [];
            $cloro = $dados['cloro_livre'] ?? null;

            if (! is_numeric($cloro)) {
                continue;
            }

            $medicoes[] = [
                'momento' => $analise->registado_em->copy(),
                'cloro_livre' => (float) $cloro,
                'ph' => isset($dados['ph']) && is_numeric($dados['ph']) ? (float) $dados['ph'] : null,
                'origem' => 'Análise pontual',
            ];
        }

        return $medicoes;
    }

    /**
     * A hora da colheita é que interessa para emparelhar — o registo pode ter
     * sido gravado horas depois de a amostra ter sido tirada.
     */
    private function momentoDaColheita(DailyRecord $registo): CarbonInterface
    {
        $momento = $registo->registado_em->copy();

        if (filled($registo->hora_colheita)) {
            [$h, $m] = array_pad(explode(':', (string) $registo->hora_colheita), 2, '0');

            if (is_numeric($h) && is_numeric($m)) {
                $momento->setTime((int) $h, (int) $m);
            }
        }

        return $momento;
    }

    /**
     * @param  array<int, array{momento: CarbonInterface, cloro_livre: float, ph: ?float, origem: string}>  $manuais
     * @return array<int, array{momento: CarbonInterface, orp: float, cloro_livre: float, ph: ?float, origem: string}>
     */
    private function emparelhar(Pool $piscina, array $manuais): array
    {
        $pares = [];

        foreach ($manuais as $manual) {
            /** @var CarbonInterface $momento */
            $momento = $manual['momento'];

            /** @var SensorReading|null $leitura */
            $leitura = SensorReading::query()
                ->where('pool_id', $piscina->id)
                ->whereNotNull('orp')
                ->whereBetween('lida_em', [
                    $momento->copy()->subMinutes(self::TOLERANCIA_MINUTOS),
                    $momento->copy()->addMinutes(self::TOLERANCIA_MINUTOS),
                ])
                ->get(['lida_em', 'orp'])
                ->sortBy(fn (SensorReading $l): float => abs($l->lida_em->diffInSeconds($momento)))
                ->first();

            if (! $leitura instanceof SensorReading) {
                continue;
            }

            // Uma leitura tirada durante lavagem de filtro, bomba parada ou
            // avaria declarada nao representa a agua da piscina. Emparelhar
            // essa com uma analise manual injeta ruido puro na correlacao —
            // e foi assim que apareceu um par de 297 mV com 2,95 mg/L.
            if ($this->artefactoService->motivoEm($piscina->id, $leitura->lida_em) !== null) {
                continue;
            }

            $pares[] = [
                'momento' => $momento,
                'orp' => (float) $leitura->orp,
                'cloro_livre' => $manual['cloro_livre'],
                'ph' => $manual['ph'],
                'origem' => $manual['origem'],
            ];
        }

        usort($pares, fn (array $a, array $b): int => $a['momento'] <=> $b['momento']);

        return $pares;
    }

    /**
     * @param  array<int, float>  $x
     * @param  array<int, float>  $y
     */
    private function pearson(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n < 2) {
            return null;
        }

        $mediaX = array_sum($x) / $n;
        $mediaY = array_sum($y) / $n;

        $cov = 0.0;
        $varX = 0.0;
        $varY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $mediaX;
            $dy = $y[$i] - $mediaY;
            $cov += $dx * $dy;
            $varX += $dx ** 2;
            $varY += $dy ** 2;
        }

        if ($varX <= 0.0 || $varY <= 0.0) {
            return null;
        }

        return round($cov / sqrt($varX * $varY), 3);
    }

    /**
     * Reta dos mínimos quadrados y = declive*x + ordenada.
     *
     * @param  array<int, float>  $x
     * @param  array<int, float>  $y
     * @return array{0: ?float, 1: ?float}
     */
    private function regressao(array $x, array $y): array
    {
        $n = count($x);
        if ($n < 2) {
            return [null, null];
        }

        $mediaX = array_sum($x) / $n;
        $mediaY = array_sum($y) / $n;

        $num = 0.0;
        $den = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $num += ($x[$i] - $mediaX) * ($y[$i] - $mediaY);
            $den += ($x[$i] - $mediaX) ** 2;
        }

        if ($den <= 0.0) {
            return [null, null];
        }

        $declive = $num / $den;

        return [$declive, $mediaY - ($declive * $mediaX)];
    }
}
