<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\SensorReading;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class OrpChlorineCorrelationWidget extends ChartWidget
{
    protected static ?int $sort = 5;

    protected static ?string $heading = 'Correlação ORP vs Cloro Livre (Últimos 30 dias)';

    protected static ?string $description = 'Avalia a relação entre as leituras do sensor de ORP e os testes manuais de Cloro Livre, diferenciando o turno da manhã e o turno da tarde.';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    protected static ?string $maxHeight = '400px';

    protected function getFilters(): ?array
    {
        return Pool::orderBy('name')->pluck('name', 'id')->toArray();
    }

    protected function getData(): array
    {
        $poolId = $this->filter;

        if (! $poolId) {
            $poolId = Pool::orderBy('name')->value('id');
        }

        if (! $poolId) {
            return ['datasets' => []];
        }

        $inicio = now()->subDays(30)->startOfDay();
        $fim = now()->endOfDay();

        // 1. Fetch all manual records for the pool in the last 30 days
        $registos = DailyRecord::query()
            ->where('pool_id', $poolId)
            ->whereBetween('registado_em', [$inicio, $fim])
            ->whereNotNull('cloro_livre') // only those with valid chlorine
            ->whereDoesntHave('correcoes') // ignore overwritten records
            ->orderBy('registado_em')
            ->get();

        $morningData = [];
        $afternoonData = [];

        foreach ($registos as $registo) {
            $cloroLivre = $registo->cloro_livre_efetivo;
            if ($cloroLivre === null) {
                continue;
            }

            $lidaEm = $registo->registado_em;

            // 2. Find the closest ORP reading within ± 15 minutes
            $inicioJanela = $lidaEm->copy()->subMinutes(15);
            $fimJanela = $lidaEm->copy()->addMinutes(15);

            // Fetch ORP using basic proximity ordering
            $leituraSonda = SensorReading::query()
                ->where('pool_id', $poolId)
                ->whereBetween('lida_em', [$inicioJanela, $fimJanela])
                ->whereNotNull('orp')
                // Order by time proximity (works in SQLite and Postgres using different syntax, but let's just get them and sort in PHP to be DB agnostic)
                ->get()
                ->sortBy(fn($r) => abs($r->lida_em->diffInSeconds($lidaEm)))
                ->first();

            if ($leituraSonda) {
                $ponto = [
                    'x' => (float) $cloroLivre,
                    'y' => (float) $leituraSonda->orp,
                    't' => $lidaEm->format('d/m H:i'), // Extra meta info for tooltip
                ];

                // Turno da manhã = antes das 14h00
                if ($lidaEm->format('H:i') < '14:00') {
                    $morningData[] = $ponto;
                } else {
                    $afternoonData[] = $ponto;
                }
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Turno da Manhã (< 14h00)',
                    'data' => $morningData,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.7)', // Blue
                    'borderColor' => 'rgb(59, 130, 246)',
                    'pointRadius' => 6,
                    'pointHoverRadius' => 8,
                ],
                [
                    'label' => 'Turno da Tarde (≥ 14h00)',
                    'data' => $afternoonData,
                    'backgroundColor' => 'rgba(249, 115, 22, 0.7)', // Orange
                    'borderColor' => 'rgb(249, 115, 22)',
                    'pointRadius' => 6,
                    'pointHoverRadius' => 8,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'scatter';
    }

    protected function getOptions(): array|\Filament\Support\RawJs|null
    {
        return \Filament\Support\RawJs::make(<<<'JS'
        {
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Cloro Livre Manual (ppm)'
                    },
                    min: 0,
                    max: 3,
                    ticks: {
                        stepSize: 0.5
                    },
                    grid: {
                        color: (context) => {
                            if (context.tick.value === 1.0 || context.tick.value === 1.5) {
                                return 'rgba(34, 197, 94, 0.3)';
                            }
                            return 'rgba(0,0,0,0.05)';
                        },
                        lineWidth: (context) => {
                            if (context.tick.value === 1.0 || context.tick.value === 1.5) {
                                return 2;
                            }
                            return 1;
                        }
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: 'ORP Controlador (mV)'
                    },
                    min: 500,
                    max: 900,
                    grid: {
                        color: (context) => {
                            if (context.tick.value === 650 || context.tick.value === 750) {
                                return 'rgba(34, 197, 94, 0.3)';
                            }
                            return 'rgba(0,0,0,0.05)';
                        },
                        lineWidth: (context) => {
                            if (context.tick.value === 650 || context.tick.value === 750) {
                                return 2;
                            }
                            return 1;
                        }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            const p = context.raw;
                            return label + p.x + ' ppm / ' + p.y + ' mV (' + p.t + ')';
                        }
                    }
                }
            }
        }
        JS);
    }
}
