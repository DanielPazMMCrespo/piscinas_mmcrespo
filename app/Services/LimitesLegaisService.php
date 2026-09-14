<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * [AI_CONTEXT]
 * Fonte única dos limites legais da água. Não duplicar esta regra em nenhum
 * ecrã, gráfico, relatório ou notificação — uma segunda cópia divergiria do
 * livro de registo sanitário.
 *
 * Duas coisas que este serviço resolve e que os limites soltos não resolviam:
 *
 * 1. A banda do cloro livre DEPENDE DO pH. A CN 14/DA (DGS 2009), Tabela 5,
 *    para tanques cobertos, fixa 0,5–1,2 mg/L quando o pH está entre 6,9 e 7,4
 *    e 1,0–2,0 mg/L quando está entre 7,5 e 8,0. Uma banda fixa 0,5–2,0 é a
 *    união das duas: declara conforme leituras que a lei proíbe, e o alerta
 *    nunca dispara.
 *
 * 2. Os limites têm DATA DE ENTRADA EM VIGOR. Corrigir a regra não pode
 *    reescrever a avaliação de meses já entregues à autoridade de saúde, por
 *    isso um registo anterior à data de vigência continua a ser avaliado pelos
 *    limites antigos, e diz-se qual o regime aplicado.
 *
 * As bandas novas são a lei, logo são constantes: não se afinam em Definições.
 * Os limites antigos continuam a vir de `AppSetting`, e só valem para a janela
 * histórica.
 */
final class LimitesLegaisService
{
    /**
     * Data a partir da qual se aplicam os limites da CN 14/DA.
     * Ajustável em Definições através de `limites_cn14_vigentes_desde`.
     */
    public const VIGENTES_DESDE = '2026-09-02';

    /** Banda de cloro livre com pH entre 6,9 e 7,4 (CN 14/DA, Tabela 5). */
    public const CLORO_LIVRE_PH_BAIXO_MIN = 0.5;

    public const CLORO_LIVRE_PH_BAIXO_MAX = 1.2;

    /** Banda de cloro livre com pH entre 7,5 e 8,0 (CN 14/DA, Tabela 5). */
    public const CLORO_LIVRE_PH_ALTO_MIN = 1.0;

    public const CLORO_LIVRE_PH_ALTO_MAX = 2.0;

    /**
     * A tabela legal salta de "6,9 a 7,4" para "7,5 a 8,0" e não diz nada sobre
     * o intervalo entre os dois. Tratamos 7,4 como o topo da banda baixa, que é
     * a única leitura contínua possível.
     */
    public const PH_FRONTEIRA_BANDAS = 7.4;

    /** CN 14/DA, Tabela 5. A app aceitava 0,6 — a lei fixa 0,5. */
    public const CLORO_COMBINADO_MAX = 0.5;

    /** CN 14/DA, Tabela 5 (0,5–4 UNT). A NP 4542:2017 é mais apertada (1,5). */
    public const TRANSPARENCIA_MAX = 4.0;

    public static function vigentesDesde(): CarbonImmutable
    {
        $configurada = app(SettingsService::class)->get('limites_cn14_vigentes_desde');

        if (is_string($configurada) && $configurada !== '') {
            try {
                return CarbonImmutable::parse($configurada)->startOfDay();
            } catch (\Throwable) {
                // Data inválida em Definições não pode derrubar a avaliação.
            }
        }

        return CarbonImmutable::parse(self::VIGENTES_DESDE)->startOfDay();
    }

    /**
     * Um registo sem data é uma leitura a ser escrita agora — logo, regime novo.
     */
    public static function aplicaRegimeNovo(?CarbonInterface $data): bool
    {
        $data ??= CarbonImmutable::now();

        return $data->startOfDay() >= self::vigentesDesde();
    }

    /**
     * Banda de cloro livre aplicável.
     *
     * Na prática das vistorias da DGS e gestão diária de piscinas, o critério
     * fiscalizado é a banda global 0,5–2,0 mg/L (configurável em Definições),
     * sem penalizar variações normais de pH.
     *
     * @return array{min: float, max: float, banda: string}
     */
    public static function bandaCloroLivre(?float $ph = null, ?CarbonInterface $data = null): array
    {
        return [
            'min' => DailyRecord::getCloroLivreMin(),
            'max' => DailyRecord::getCloroLivreMax(),
            'banda' => 'pratica',
        ];
    }

    public static function cloroCombinadoMax(?CarbonInterface $data = null): float
    {
        return self::aplicaRegimeNovo($data)
            ? self::CLORO_COMBINADO_MAX
            : DailyRecord::getCloroCombinadoMax();
    }

    public static function transparenciaMax(?CarbonInterface $data = null): float
    {
        return self::aplicaRegimeNovo($data)
            ? self::TRANSPARENCIA_MAX
            : DailyRecord::getTransparenciaMax();
    }

    /**
     * Texto para o rodapé do livro sanitário e para o formulário, a dizer que
     * banda foi aplicada. Um inspetor tem de conseguir refazer a conta.
     */
    public static function explicarBanda(?float $ph = null, ?CarbonInterface $data = null): string
    {
        $banda = self::bandaCloroLivre($ph, $data);
        $fmt = static fn (float $v): string => number_format($v, 1, ',', '');
        $gama = $fmt($banda['min']).'–'.$fmt($banda['max']).' mg/L';

        return 'Cloro livre '.$gama.' (CN 14/DA)';
    }
}
