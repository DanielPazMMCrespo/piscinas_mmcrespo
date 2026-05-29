<?php

namespace App\Filament\Widgets;

use App\Models\DailyRecord;
use App\Models\Pool;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class CloroPhChartWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';
    protected static string $view = 'filament.widgets.painel-parametros';

    /** Piscinas selecionadas (IDs como string, default: todas em mount). */
    public array $piscinasSelecionadas = [];

    /** Parâmetros a mostrar. Default: pH + cloro livre. */
    public array $metricasSelecionadas = ['cloro_livre', 'ph'];

    /**
     * Definição de cada parâmetro: label, unidade, casas decimais, gama do eixo,
     * banda de conformidade CN 14/DA (faixa verde) e cor própria (usada quando há
     * só uma piscina, para distinguir parâmetros pela cor).
     */
    private const METRICAS = [
        'cloro_livre' => [
            'label' => 'Cloro Livre', 'unidade' => 'mg/L', 'casas' => 2,
            'min' => 0, 'max' => 2.5, 'cor' => '#2b9cd8',
            'banda' => ['min' => DailyRecord::CLORO_LIVRE_MIN, 'max' => DailyRecord::CLORO_LIVRE_MAX],
        ],
        'cloro_total' => [
            'label' => 'Cloro Total', 'unidade' => 'mg/L', 'casas' => 2,
            'min' => 0, 'max' => 3, 'cor' => '#0e7490',
            'banda' => null,
        ],
        'ph' => [
            'label' => 'pH', 'unidade' => '', 'casas' => 2,
            'min' => 6.5, 'max' => 8.5, 'cor' => '#76b82a',
            'banda' => ['min' => DailyRecord::PH_MIN, 'max' => DailyRecord::PH_MAX],
        ],
        'temperatura' => [
            'label' => 'Temperatura', 'unidade' => '°C', 'casas' => 1,
            'min' => 22, 'max' => 32, 'cor' => '#e0a800',
            'banda' => null,
        ],
        'transparencia' => [
            'label' => 'Transparência', 'unidade' => 'm', 'casas' => 0,
            'min' => 0, 'max' => 4, 'cor' => '#8b5cf6',
            'banda' => null,
        ],
    ];

    /** Cor por piscina (estável, por ordem). Usada quando há várias piscinas. */
    private const CORES_PISCINA = ['#2b9cd8', '#76b82a', '#e0a800', '#dc3545', '#8b5cf6', '#fd7e14'];

    public function mount(): void
    {
        $this->piscinasSelecionadas = Pool::query()
            ->where('active', true)
            ->orderBy('installation_id')->orderBy('name')
            ->pluck('id')->map(fn ($id) => (string) $id)->toArray();

        $this->form->fill([
            'piscinasSelecionadas' => $this->piscinasSelecionadas,
            'metricasSelecionadas' => $this->metricasSelecionadas,
        ]);
    }

    protected function getFormSchema(): array
    {
        $opcoesPiscinas = Pool::query()
            ->where('active', true)->with('instalacao')
            ->orderBy('installation_id')->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Pool $p) => [
                (string) $p->id => ($p->instalacao?->name ? $p->instalacao->name . ' — ' : '') . $p->name,
            ])->toArray();

        $opcoesMetricas = collect(self::METRICAS)
            ->mapWithKeys(fn ($m, $k) => [$k => $m['label']])->toArray();

        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('piscinasSelecionadas')
                    ->label('Piscinas')
                    ->multiple()->options($opcoesPiscinas)
                    ->live()->afterStateUpdated(fn () => $this->dispatch('mmc-chart-updated')),
                Forms\Components\Select::make('metricasSelecionadas')
                    ->label('Parâmetros')
                    ->multiple()->options($opcoesMetricas)
                    ->live()->afterStateUpdated(fn () => $this->dispatch('mmc-chart-updated')),
            ]),
        ];
    }

    /**
     * Blocos de gráficos a desenhar. Regra:
     *  - 1 piscina selecionada  -> UM gráfico único com todos os parâmetros como
     *    linhas de cores distintas (eixo normalizado 0-100%) para ver correlações
     *    pH<->cloro de relance.
     *  - 2+ piscinas            -> um gráfico por parâmetro, cada série uma piscina.
     */
    public function getGraficos(): array
    {
        $piscinaIds = array_map('intval', $this->piscinasSelecionadas);
        $metricas = array_values(array_intersect(
            $this->metricasSelecionadas,
            array_keys(self::METRICAS)
        ));

        $dias = collect(range(13, 0))->map(fn ($d) => Carbon::today()->subDays($d));
        $labels = $dias->map(fn ($d) => $d->format('d/m'))->values()->toArray();

        if (empty($piscinaIds) || empty($metricas)) {
            return [];
        }

        $colunas = collect($metricas)->map(fn ($m) => "AVG({$m}) as {$m}")->implode(', ');

        $registos = DailyRecord::selectRaw("pool_id, DATE(registado_em) as dia, {$colunas}")
            ->whereIn('pool_id', $piscinaIds)
            ->where('registado_em', '>=', Carbon::today()->subDays(13)->startOfDay())
            ->whereDoesntHave('correcoes')
            ->groupByRaw('pool_id, DATE(registado_em)')
            ->get()
            ->groupBy('pool_id');

        $piscinas = Pool::query()->whereIn('id', $piscinaIds)
            ->orderBy('installation_id')->orderBy('name')->get();

        // Devolve a série (array de valores por dia) de uma piscina+métrica.
        $serie = function (int $poolId, string $metrica, array $def) use ($registos, $dias) {
            $porDia = ($registos->get($poolId) ?? collect())->keyBy('dia');
            return $dias->map(function ($d) use ($porDia, $metrica, $def) {
                $r = $porDia->get($d->format('Y-m-d'));
                return $r && $r->{$metrica} !== null
                    ? round((float) $r->{$metrica}, $def['casas'])
                    : null;
            })->values()->toArray();
        };

        // --- MODO 1 PISCINA: um gráfico, parâmetros como linhas de cores distintas ---
        if ($piscinas->count() === 1) {
            $p = $piscinas->first();
            $series = [];
            foreach ($metricas as $metrica) {
                $def = self::METRICAS[$metrica];
                $series[] = [
                    'label'   => $def['label'] . ($def['unidade'] ? " ({$def['unidade']})" : ''),
                    'cor'     => $def['cor'],
                    'data'    => $serie($p->id, $metrica, $def),
                    // Eixo próprio por parâmetro: pH à direita, cloros/temp à esquerda.
                    'eixo'    => $metrica === 'ph' ? 'ph' : 'principal',
                    'banda'   => $def['banda'],
                    'unidade' => $def['unidade'],
                ];
            }

            return [[
                'modo'    => 'multi-metrica',
                'titulo'  => $p->instalacao?->name ? "{$p->instalacao->name} — {$p->name}" : $p->name,
                'labels'  => $labels,
                'series'  => $series,
                // Eixo principal (cloro/temp/transp) e eixo pH separados.
                'eixos'   => [
                    'principal' => ['min' => 0,   'max' => 3,   'titulo' => 'mg/L · °C · m'],
                    'ph'        => ['min' => 6.5, 'max' => 8.5, 'titulo' => 'pH', 'banda' => ['min' => DailyRecord::PH_MIN, 'max' => DailyRecord::PH_MAX]],
                ],
                // Banda do cloro livre marcada no eixo principal se cloro_livre estiver selecionado.
                'bandaPrincipal' => in_array('cloro_livre', $metricas, true)
                    ? ['min' => DailyRecord::CLORO_LIVRE_MIN, 'max' => DailyRecord::CLORO_LIVRE_MAX]
                    : null,
            ]];
        }

        // --- MODO VÁRIAS PISCINAS: um gráfico por parâmetro, série = piscina ---
        $corPorPiscina = [];
        foreach ($piscinas->values() as $i => $p) {
            $corPorPiscina[$p->id] = self::CORES_PISCINA[$i % count(self::CORES_PISCINA)];
        }

        $graficos = [];
        foreach ($metricas as $metrica) {
            $def = self::METRICAS[$metrica];
            $series = [];
            foreach ($piscinas as $p) {
                $series[] = [
                    'label' => $p->name,
                    'cor'   => $corPorPiscina[$p->id],
                    'data'  => $serie($p->id, $metrica, $def),
                ];
            }

            $graficos[] = [
                'modo'    => 'mono-metrica',
                'titulo'  => $def['label'],
                'unidade' => $def['unidade'],
                'min'     => $def['min'],
                'max'     => $def['max'],
                'banda'   => $def['banda'],
                'labels'  => $labels,
                'series'  => $series,
            ];
        }

        return $graficos;
    }
}
