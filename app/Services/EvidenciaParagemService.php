<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\TrabalhoParagem;
use App\Models\PoolClosure;
use App\Models\SensorOutage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Serviço de análise forense e reconciliação de leituras do controlador Hanna
 * para deteção de evidências físicas durante uma Paragem Técnica.
 *
 * [AI_CONTEXT]
 * - SERVIÇO DE LEITURA PURA: NUNCA escreve ou altera a base de dados.
 * - Deteta padrões físico-químicos (supercloração, arranque de aquecimento,
 *   reposição de cloro, tanques vazios / lacunas).
 * - Trabalhos mecânicos (limpeza de tanques, caleiras, filtros, circuitos) e
 *   microbiológicos (Legionella) devolvem SEMPRE array vazio [], pois dependem
 *   exclusivamente de testemunho presencial ou boletim laboratorial acreditado.
 */
class EvidenciaParagemService
{
    private const LOOKBACK_DIAS_BASE = 14;

    /**
     * Devolve todos os candidatos a evidência para a paragem técnica agrupados por tipo.
     *
     * @return array<string, array<int, array{
     *     momento: Carbon,
     *     fim: ?Carbon,
     *     detalhe: string,
     *     fonte: string,
     *     confianca: string,
     *     criterio: string,
     *     dados: array<string, mixed>
     * }>>
     */
    public function candidatos(PoolClosure $encerramento): array
    {
        return [
            TrabalhoParagem::SUPERCLORACAO => $this->detetarSupercloracao($encerramento),
            TrabalhoParagem::ARRANQUE_AQUECIMENTO => $this->detetarAquecimento($encerramento),
            TrabalhoParagem::REPOSICAO_CLORO => $this->detetarReposicaoCloro($encerramento),
            TrabalhoParagem::ESVAZIAMENTO_TANQUE => $this->detetarTanqueVazio($encerramento),
            TrabalhoParagem::LIMPEZA_TANQUE => [],
            TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO => [],
            TrabalhoParagem::LIMPEZA_CALEIRAS => [],
            TrabalhoParagem::MANUTENCAO_FILTROS => [],
            TrabalhoParagem::LIMPEZA_CIRCUITO => [],
            TrabalhoParagem::DESINFECAO_LEGIONELLA => [],
            TrabalhoParagem::ENCHIMENTO_TANQUE => [],
            TrabalhoParagem::VERIFICACAO_PARAMETROS => [],
            TrabalhoParagem::OUTRO => [],
        ];
    }

    /**
     * @return array<int, array{
     *     momento: Carbon,
     *     fim: ?Carbon,
     *     detalhe: string,
     *     fonte: string,
     *     confianca: string,
     *     criterio: string,
     *     dados: array<string, mixed>
     * }>
     */
    public function candidatosPara(PoolClosure $encerramento, string $tipo): array
    {
        $todos = $this->candidatos($encerramento);

        return $todos[$tipo] ?? [];
    }

    /**
     * Deteta supercloração por potencial redox sustentado e pico de ORP.
     *
     * @return array<int, array{momento: Carbon, fim: ?Carbon, detalhe: string, fonte: string, confianca: string, criterio: string, dados: array<string, mixed>}>
     */
    private function detetarSupercloracao(PoolClosure $encerramento): array
    {
        $piscina = $encerramento->piscina;
        $de = $encerramento->inicio->copy()->startOfDay();
        $ate = ($encerramento->fim ?? Carbon::now())->copy()->endOfDay();

        // 1. Obter leituras do período
        $leituras = DB::table('sensor_readings')
            ->where('pool_id', $piscina->id)
            ->whereBetween('lida_em', [$de, $ate])
            ->whereNotNull('orp')
            ->orderBy('lida_em')
            ->get(['lida_em', 'orp', 'caudal_cloro']);

        if ($leituras->count() < 2) {
            return [];
        }

        // 2. Calcular linha de base dos 14 dias anteriores
        $baseDe = $de->copy()->subDays(self::LOOKBACK_DIAS_BASE);
        $leiturasBase = DB::table('sensor_readings')
            ->where('pool_id', $piscina->id)
            ->whereBetween('lida_em', [$baseDe, $de])
            ->whereNotNull('orp')
            ->pluck('orp')
            ->map(fn ($v) => (float) $v)
            ->sort()
            ->values();

        $medianaBase = $leiturasBase->count() > 0 ? (float) $leiturasBase->get((int) ($leiturasBase->count() / 2)) : 700.0;
        $p95Base = $leiturasBase->count() > 0 ? (float) $leiturasBase->get((int) ($leiturasBase->count() * 0.95)) : 750.0;

        $orpMaxPiscina = (float) ($piscina->orp_max ?? 800.0);
        $limiarAbsoluto = max($orpMaxPiscina, $p95Base + 60.0);
        $limiarRelativo = $medianaBase + 100.0;
        $limiarEntrada = min($limiarAbsoluto, $limiarRelativo);

        $eventos = [];
        $emCorrida = false;
        $inicioCorrida = null;
        $ultimoTs = null;
        $leiturasCorrida = [];

        foreach ($leituras as $l) {
            $ts = Carbon::parse($l->lida_em);
            $orp = (float) $l->orp;

            // Lacuna > 2h parte a corrida
            if ($ultimoTs !== null && abs($ts->diffInMinutes($ultimoTs)) > 120 && $emCorrida) {
                $evento = $this->avaliarEventoSupercloracao($leiturasCorrida, $inicioCorrida, $ultimoTs, $limiarEntrada, $orpMaxPiscina);
                if ($evento !== null) {
                    $eventos[] = $evento;
                }
                $emCorrida = false;
                $inicioCorrida = null;
                $leiturasCorrida = [];
            }

            if ($orp >= $limiarEntrada) {
                if (! $emCorrida) {
                    $emCorrida = true;
                    $inicioCorrida = $ts;
                    $leiturasCorrida = [];
                }
                $leiturasCorrida[] = ['ts' => $ts, 'orp' => $orp, 'caudal' => (float) ($l->caudal_cloro ?? 0)];
            } elseif ($emCorrida) {
                // Histerese de saída: mantém se for apenas uma leitura pontual abaixo
                $leiturasCorrida[] = ['ts' => $ts, 'orp' => $orp, 'caudal' => (float) ($l->caudal_cloro ?? 0)];
                if ($orp < ($limiarEntrada - 40.0)) {
                    $evento = $this->avaliarEventoSupercloracao($leiturasCorrida, $inicioCorrida, $ts, $limiarEntrada, $orpMaxPiscina);
                    if ($evento !== null) {
                        $eventos[] = $evento;
                    }
                    $emCorrida = false;
                    $inicioCorrida = null;
                    $leiturasCorrida = [];
                }
            }

            $ultimoTs = $ts;
        }

        if ($emCorrida && $inicioCorrida !== null && $ultimoTs !== null) {
            $evento = $this->avaliarEventoSupercloracao($leiturasCorrida, $inicioCorrida, $ultimoTs, $limiarEntrada, $orpMaxPiscina);
            if ($evento !== null) {
                $eventos[] = $evento;
            }
        }

        return $eventos;
    }

    /**
     * @param  array<int, array{ts: Carbon, orp: float, caudal: float}>  $leiturasCorrida
     * @return array{momento: Carbon, fim: ?Carbon, detalhe: string, fonte: string, confianca: string, criterio: string, dados: array<string, mixed>}|null
     */
    private function avaliarEventoSupercloracao(array $leiturasCorrida, ?Carbon $inicio, ?Carbon $fim, float $limiar, float $orpMax): ?array
    {
        if (count($leiturasCorrida) < 2 || $inicio === null || $fim === null) {
            return null;
        }

        $orps = array_column($leiturasCorrida, 'orp');
        $caudais = array_column($leiturasCorrida, 'caudal');
        $picoOrp = max($orps);
        $duracaoHoras = round(max(0.5, $inicio->diffInMinutes($fim) / 60), 1);

        if ($picoOrp < $limiar) {
            return null;
        }

        $comDosagem = count(array_filter($caudais, fn ($c) => $c > 0)) > 0;
        $confianca = ($comDosagem || $picoOrp >= 850.0) ? 'alta' : 'media';

        $criterio = sprintf(
            'ORP ≥ %.0f mV durante %.1f h (pico de %.0f mV; limiar de referência: %.0f mV)',
            $limiar,
            $duracaoHoras,
            $picoOrp,
            $orpMax
        );

        return [
            'momento' => $inicio->copy(),
            'fim' => $fim->copy(),
            'detalhe' => sprintf('Pico de supercloração a %.0f mV com duração de %.1f horas.', $picoOrp, $duracaoHoras),
            'fonte' => 'Sensor Hanna (Potencial Redox / ORP)',
            'confianca' => $confianca,
            'criterio' => $criterio,
            'dados' => [
                'pico_orp' => $picoOrp,
                'duracao_horas' => $duracaoHoras,
                'horas_contacto' => ceil($duracaoHoras),
            ],
        ];
    }

    /**
     * Deteta arranque de aquecimento através de subida contínua e sustentada da temperatura da água.
     *
     * @return array<int, array{momento: Carbon, fim: ?Carbon, detalhe: string, fonte: string, confianca: string, criterio: string, dados: array<string, mixed>}>
     */
    private function detetarAquecimento(PoolClosure $encerramento): array
    {
        $de = $encerramento->inicio->copy()->startOfDay();
        $ate = ($encerramento->fim ?? Carbon::now())->copy()->endOfDay();

        $leituras = DB::table('sensor_readings')
            ->where('pool_id', $encerramento->pool_id)
            ->whereBetween('lida_em', [$de, $ate])
            ->whereNotNull('temperatura_agua')
            ->orderBy('lida_em')
            ->get(['lida_em', 'temperatura_agua', 'temperatura_ar']);

        if ($leituras->count() < 12) {
            return [];
        }

        // Agrupamento horário em PHP
        $porHora = [];
        foreach ($leituras as $l) {
            $hKey = Carbon::parse($l->lida_em)->format('Y-m-d H:00');
            if (! isset($porHora[$hKey])) {
                $porHora[$hKey] = ['soma_agua' => 0.0, 'soma_ar' => 0.0, 'contagem' => 0, 'ts' => Carbon::parse($hKey)];
            }
            $porHora[$hKey]['soma_agua'] += (float) $l->temperatura_agua;
            if ($l->temperatura_ar !== null) {
                $porHora[$hKey]['soma_ar'] += (float) $l->temperatura_ar;
            }
            $porHora[$hKey]['contagem']++;
        }

        $series = [];
        foreach ($porHora as $h) {
            $n = max(1, $h['contagem']);
            $series[] = [
                'ts' => $h['ts'],
                'temp_agua' => $h['soma_agua'] / $n,
                'temp_ar' => $h['soma_ar'] / $n,
            ];
        }

        $totalHoras = count($series);
        if ($totalHoras < 6) {
            return [];
        }

        $eventos = [];
        $i = 0;
        while ($i <= ($totalHoras - 6)) {
            $t0 = $series[$i];
            $t6 = $series[$i + 5];

            $deltaTemp = $t6['temp_agua'] - $t0['temp_agua'];

            // Critério: subida >= 2.0 °C numa janela de 6 horas
            if ($deltaTemp >= 2.0) {
                // Verificar que >= 80% dos passos horários não recuam (>= -0.1 °C)
                $passosPositivos = 0;
                for ($j = $i; $j < $i + 5; $j++) {
                    if (($series[$j + 1]['temp_agua'] - $series[$j]['temp_agua']) >= -0.15) {
                        $passosPositivos++;
                    }
                }

                if ($passosPositivos >= 4) {
                    $deltaAr = $t6['temp_ar'] - $t0['temp_ar'];
                    // Se a temperatura do ar subiu tanto ou mais que a água, pode ser onda térmica
                    $confianca = ($deltaAr < ($deltaTemp * 0.8)) ? 'alta' : 'media';

                    $criterio = sprintf(
                        'Subida térmica da água de %.1f °C para %.1f °C (+%.1f °C em 6h; taxa de +%.2f °C/h)',
                        $t0['temp_agua'],
                        $t6['temp_agua'],
                        $deltaTemp,
                        $deltaTemp / 6
                    );

                    $eventos[] = [
                        'momento' => $t0['ts']->copy(),
                        'fim' => $t6['ts']->copy(),
                        'detalhe' => sprintf('Rampa de aquecimento: elevação de %.1f °C para %.1f °C.', $t0['temp_agua'], $t6['temp_agua']),
                        'fonte' => 'Sonda de Temperatura da Água',
                        'confianca' => $confianca,
                        'criterio' => $criterio,
                        'dados' => [
                            'temp_inicial' => round($t0['temp_agua'], 1),
                            'temp_final' => round($t6['temp_agua'], 1),
                            'delta_temp' => round($deltaTemp, 1),
                        ],
                    ];

                    $i += 6; // Avança para evitar disparos duplicados na mesma rampa

                    continue;
                }
            }
            $i++;
        }

        return $eventos;
    }

    /**
     * Deteta reposição e estabilização de cloro após uma excursão prévia.
     *
     * @return array<int, array{momento: Carbon, fim: ?Carbon, detalhe: string, fonte: string, confianca: string, criterio: string, dados: array<string, mixed>}>
     */
    private function detetarReposicaoCloro(PoolClosure $encerramento): array
    {
        $piscina = $encerramento->piscina;
        $orpMin = (float) ($piscina->orp_min ?? 650.0);
        $orpMax = (float) ($piscina->orp_max ?? 800.0);

        $de = $encerramento->inicio->copy()->startOfDay();
        $ate = ($encerramento->fim ?? Carbon::now())->copy()->endOfDay();

        $leituras = DB::table('sensor_readings')
            ->where('pool_id', $piscina->id)
            ->whereBetween('lida_em', [$de, $ate])
            ->whereNotNull('orp')
            ->orderBy('lida_em')
            ->get(['lida_em', 'orp']);

        if ($leituras->count() < 12) {
            return [];
        }

        // Verificar se houve excursão prévia (fora da banda por >= 30 min)
        $houveExcursao = false;
        $consecutivosBanda = 0;
        $inicioEstabilizacao = null;
        $eventos = [];

        foreach ($leituras as $l) {
            $ts = Carbon::parse($l->lida_em);
            $orp = (float) $l->orp;

            if ($orp < $orpMin || $orp > $orpMax) {
                $houveExcursao = true;
                $consecutivosBanda = 0;
                $inicioEstabilizacao = null;
            } elseif ($houveExcursao) {
                if ($consecutivosBanda === 0) {
                    $inicioEstabilizacao = $ts;
                }
                $consecutivosBanda++;

                // 8 leituras consecutivas (2h) dentro da banda após excursão
                if ($consecutivosBanda === 8 && $inicioEstabilizacao !== null) {
                    $criterio = sprintf(
                        'Estabilização de ORP na banda regulamentar [%.0f–%.0f mV] sustentada por 8 leituras (2 h) após excursão',
                        $orpMin,
                        $orpMax
                    );

                    $eventos[] = [
                        'momento' => $inicioEstabilizacao->copy(),
                        'fim' => $ts->copy(),
                        'detalhe' => sprintf('Normalização do potencial redox para %.0f mV dentro dos parâmetros operacionais.', $orp),
                        'fonte' => 'Sensor Hanna (Potencial Redox / ORP)',
                        'confianca' => 'media',
                        'criterio' => $criterio,
                        'dados' => [
                            'orp_estabilizado' => $orp,
                            'orp_min_ref' => $orpMin,
                            'orp_max_ref' => $orpMax,
                        ],
                    ];

                    $houveExcursao = false; // Reset para apanhar novo ciclo se houver
                }
            }
        }

        return $eventos;
    }

    /**
     * Deteta períodos prováveis de tanque vazio ou paragem por lacuna de leituras (≥ 6h)
     * sem SensorOutage associado.
     *
     * @return array<int, array{momento: Carbon, fim: ?Carbon, detalhe: string, fonte: string, confianca: string, criterio: string, dados: array<string, mixed>}>
     */
    private function detetarTanqueVazio(PoolClosure $encerramento): array
    {
        $de = $encerramento->inicio->copy()->startOfDay();
        $ate = ($encerramento->fim ?? Carbon::now())->copy()->endOfDay();

        $leituras = DB::table('sensor_readings')
            ->where('pool_id', $encerramento->pool_id)
            ->whereBetween('lida_em', [$de, $ate])
            ->orderBy('lida_em')
            ->pluck('lida_em')
            ->map(fn ($ts) => Carbon::parse($ts));

        if ($leituras->count() < 2) {
            return [];
        }

        // Carregar avarias de sensor no período para descartar lacunas justificadas
        $avarias = SensorOutage::query()
            ->where('pool_id', $encerramento->pool_id)
            ->where('aberta_em', '<=', $ate)
            ->where(fn ($q) => $q->whereNull('resolvida_em')->orWhere('resolvida_em', '>=', $de))
            ->get();

        $eventos = [];
        $anterior = $leituras->first();

        foreach ($leituras as $atual) {
            $diffHoras = abs((float) $atual->diffInMinutes($anterior)) / 60.0;

            if ($diffHoras >= 6.0) {
                // Verificar se a lacuna coincide com SensorOutage
                $cobertaPorAvaria = $avarias->some(function (SensorOutage $av) use ($anterior, $atual) {
                    $avFim = $av->resolvida_em ?? Carbon::now()->addYear();

                    return $av->aberta_em->lte($anterior) && $avFim->gte($atual);
                });

                if (! $cobertaPorAvaria) {
                    $criterio = sprintf('Interrupção na aquisição de dados do controlador de %.1f horas sem avaria de sonda declarada', $diffHoras);

                    $eventos[] = [
                        'momento' => $anterior->copy(),
                        'fim' => $atual->copy(),
                        'detalhe' => sprintf('Lacuna contínua de leituras de %.1f horas durante a paragem técnica.', $diffHoras),
                        'fonte' => 'Histórico Instrumental Hanna Cloud',
                        'confianca' => 'baixa',
                        'criterio' => $criterio,
                        'dados' => [
                            'duracao_horas' => round($diffHoras, 1),
                        ],
                    ];
                }
            }

            $anterior = $atual;
        }

        return $eventos;
    }
}
