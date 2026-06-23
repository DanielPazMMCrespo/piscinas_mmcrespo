<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use App\Models\HannaDevice;
use App\Models\Pool;
use Filament\Widgets\Widget;

/**
 * Primeiro widget do dashboard: o estado atual de todas as piscinas.
 *
 * Cada cartão junta as duas fontes de verdade da mesma piscina:
 *  - o último registo manual válido (não substituído por correção), avaliado
 *    contra os limites CN 14/DA;
 *  - a última leitura da sonda Hanna (BL132) mapeada à piscina, se existir,
 *    com indicação de idade (stale > 15 min, o intervalo de envio do BL132).
 *
 * Ação direta: "Registar" por piscina (pré-seleciona a piscina no formulário).
 */
class PainelPiscinasWidget extends Widget
{
    protected static ?int $sort = -30;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.painel-piscinas';

    /** 15s de polling garante que os dados aparecem logo após um sync manual. */
    protected static ?string $pollingInterval = '15s';

    protected function getViewData(): array
    {
        $sondas = HannaDevice::query()
            ->where('active', true)
            ->whereNotNull('pool_id')
            ->get()
            ->keyBy('pool_id');

        $piscinas = Pool::query()
            ->where('active', true)
            ->with('instalacao')
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get()
            ->map(function (Pool $piscina) use ($sondas): array {
                $registo = DailyRecord::query()
                    ->where('pool_id', $piscina->id)
                    ->whereDoesntHave('correcoes')
                    ->orderByDesc('registado_em')
                    ->orderByDesc('id')
                    ->first();

                // Garante que a avaliação de temperatura conhece os limites da piscina.
                $registo?->setRelation('piscina', $piscina);

                $leitura = $sondas->get($piscina->id)?->ultimaLeitura();
                $idadeMin = $leitura?->lida_em
                    ? (int) $leitura->lida_em->diffInMinutes(now())
                    : null;

                return [
                    'piscina' => $piscina,
                    'registo' => $registo,
                    'sem_hoje' => ! $registo || ! $registo->registado_em->isToday(),
                    'ha_quanto' => $registo?->registado_em->diffForHumans(),
                    'metricas' => $registo ? [
                        self::metrica('pH', $registo->ph, 2, '', $registo->ph !== null ? $registo->phConforme() : null),
                        self::metrica('Cl. Livre', $registo->cloro_livre, 2, ' mg/L', $registo->cloro_livre !== null ? $registo->cloroLivreConforme() : null),
                        self::metrica('Cl. Total', $registo->cloro_total, 2, ' mg/L', $registo->cloro_total !== null && $registo->cloro_livre !== null ? $registo->cloroCombinadoConforme() : null),
                        self::metrica('Temp.', $registo->temperatura, 1, ' °C', $registo->temperatura !== null ? $registo->temperaturaConforme() : null),
                    ] : [],
                    'sonda' => $leitura ? [
                        'ph' => $leitura->ph !== null ? number_format((float) $leitura->ph, 2, ',', '') : null,
                        'ph_ok' => $leitura->ph !== null
                            ? ((float) $leitura->ph >= DailyRecord::PH_MIN && (float) $leitura->ph <= DailyRecord::PH_MAX)
                            : null,
                        'orp' => $leitura->orp !== null ? number_format((float) $leitura->orp, 0, ',', '') : null,
                        'temp' => $leitura->temperatura_agua !== null ? number_format((float) $leitura->temperatura_agua, 1, ',', '') : null,
                        'idade_txt' => match (true) {
                            $idadeMin === null => 'sem dados',
                            $idadeMin < 1 => 'agora',
                            $idadeMin < 60 => "há {$idadeMin}m",
                            default => $leitura->lida_em->locale('pt')->diffForHumans(),
                        },
                        'stale' => $idadeMin !== null && $idadeMin > 15,
                    ] : null,
                    'url_registar' => DailyRecordResource::getUrl('create', ['pool' => $piscina->id]),
                ];
            });

        return [
            'piscinas' => $piscinas,
            'urlRegistar' => DailyRecordResource::getUrl('create'),
        ];
    }

    /**
     * Linha de métrica com tratamento de leitura em falta (registos legados):
     * valor "—" e estado neutro em vez de "0,00" enganador.
     *
     * @return array{label: string, valor: string, ok: bool|null}
     */
    private static function metrica(string $label, mixed $valor, int $casas, string $sufixo, ?bool $ok): array
    {
        return [
            'label' => $label,
            'valor' => $valor !== null ? number_format((float) $valor, $casas, ',', '').$sufixo : '—',
            'ok' => $valor !== null ? $ok : null,
        ];
    }
}
