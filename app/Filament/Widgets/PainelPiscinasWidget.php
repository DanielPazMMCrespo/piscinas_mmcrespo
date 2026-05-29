<?php

namespace App\Filament\Widgets;

use App\Models\DailyRecord;
use App\Models\Pool;
use Filament\Widgets\Widget;

class PainelPiscinasWidget extends Widget
{
    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.painel-piscinas';

    /**
     * Estado atual de todas as piscinas ativas: último registo válido (não substituído
     * por correção) de cada uma, com avaliação de conformidade CN 14/DA.
     */
    protected function getViewData(): array
    {
        $piscinas = Pool::query()
            ->where('active', true)
            ->with('instalacao')
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get()
            ->map(function (Pool $piscina): array {
                $registo = DailyRecord::query()
                    ->where('pool_id', $piscina->id)
                    ->whereDoesntHave('correcoes')
                    ->orderByDesc('registado_em')
                    ->orderByDesc('id')
                    ->first();

                // Garante que a avaliação de temperatura conhece os limites da piscina.
                if ($registo) {
                    $registo->setRelation('piscina', $piscina);
                }

                return [
                    'piscina'    => $piscina,
                    'registo'    => $registo,
                    'sem_hoje'   => ! $registo || ! $registo->registado_em->isToday(),
                    'metricas'   => $registo ? [
                        ['label' => 'pH',       'valor' => number_format((float) $registo->ph, 2, ',', ''),         'ok' => $registo->phConforme()],
                        ['label' => 'Cl. Livre', 'valor' => number_format((float) $registo->cloro_livre, 2, ',', '') . ' mg/L', 'ok' => $registo->cloroLivreConforme()],
                        ['label' => 'Cl. Total', 'valor' => number_format((float) $registo->cloro_total, 2, ',', '') . ' mg/L', 'ok' => $registo->cloroCombinadoConforme()],
                        ['label' => 'Temp.',    'valor' => number_format((float) $registo->temperatura, 1, ',', '') . ' °C', 'ok' => $registo->temperaturaConforme()],
                    ] : [],
                ];
            });

        return ['piscinas' => $piscinas];
    }
}
