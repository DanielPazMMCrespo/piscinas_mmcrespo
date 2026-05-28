<?php

namespace App\Filament\Widgets;

use App\Models\DailyRecord;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class CloroPhChartWidget extends ChartWidget
{
    protected static ?int $sort = 2;
    protected static ?string $heading = 'Cloro Livre e pH — Últimos 14 Dias';
    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $dias = collect(range(13, 0))->map(fn ($d) => Carbon::today()->subDays($d));

        $registos = DailyRecord::selectRaw('DATE(registado_em) as dia, AVG(cloro_livre) as cloro, AVG(ph) as ph')
            ->where('registado_em', '>=', Carbon::today()->subDays(13)->startOfDay())
            ->groupByRaw('DATE(registado_em)')
            ->orderByRaw('DATE(registado_em)')
            ->get()
            ->keyBy('dia');

        $labels = $dias->map(fn ($d) => $d->format('d/m'))->toArray();
        $cloro  = $dias->map(fn ($d) => ($r = $registos->get($d->format('Y-m-d'))) ? round((float) $r->cloro, 2) : null)->toArray();
        $ph     = $dias->map(fn ($d) => ($r = $registos->get($d->format('Y-m-d'))) ? round((float) $r->ph, 2) : null)->toArray();

        return [
            'datasets' => [
                [
                    'label'           => 'Cloro Livre (mg/L)',
                    'data'            => $cloro,
                    'borderColor'     => '#2b9cd8',
                    'backgroundColor' => 'rgba(43, 156, 216, 0.08)',
                    'fill'            => true,
                    'tension'         => 0.3,
                    'yAxisID'         => 'y',
                ],
                [
                    'label'           => 'pH',
                    'data'            => $ph,
                    'borderColor'     => '#76b82a',
                    'backgroundColor' => 'rgba(118, 184, 42, 0.08)',
                    'fill'            => true,
                    'tension'         => 0.3,
                    'yAxisID'         => 'y1',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'spanGaps' => false,
            'plugins' => [
                'legend' => ['display' => true],
            ],
            'scales' => [
                'y' => [
                    'type'     => 'linear',
                    'position' => 'left',
                    'min'      => 0,
                    'max'      => 3,
                    'title'    => ['display' => true, 'text' => 'Cloro Livre (mg/L)'],
                ],
                'y1' => [
                    'type'     => 'linear',
                    'position' => 'right',
                    'min'      => 6.5,
                    'max'      => 8.5,
                    'grid'     => ['drawOnChartArea' => false],
                    'title'    => ['display' => true, 'text' => 'pH'],
                ],
            ],
        ];
    }
}
