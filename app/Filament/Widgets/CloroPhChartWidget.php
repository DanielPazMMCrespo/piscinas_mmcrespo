<?php declare(strict_types=1);
namespace App\Filament\Widgets;


use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Services\CacheService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CloroPhChartWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    // Não aparece no dashboard global — apenas na página dedicada de gráficos.
    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.widgets.painel-parametros';

    /** Piscinas selecionadas (IDs como string, default: todas em mount). */
    public array $piscinasSelecionadas = [];

    /** Parâmetros a mostrar. Default: pH + cloro livre. */
    public array $metricasSelecionadas = ['cloro_livre', 'ph'];

    /**
     * Definição de cada parâmetro: label, unidade, casas decimais, gama do eixo,
     * banda de conformidade CN 14/DA (faixa verde) e cor própria.
     *
     * Os campos `min`/`max` são usados no modo mono-metrica (eixo Y real).
     * No modo multi-metrica, a normalização 0-100% usa os limites de conformidade
     * (banda), não estes limites de escala.
     *
     * Entradas com `sensor_campo` são métricas do controlador Hanna BL132
     * e são buscadas em sensor_readings em vez de daily_records.
     * Renderizam como linha tracejada para distinguir visualmente do registo manual.
     */
    private const METRICAS = [
        // --- Registo Manual ---
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
            'label' => 'Turbidez', 'unidade' => 'FNU', 'casas' => 2,
            'min' => 0, 'max' => 1, 'cor' => '#8b5cf6',
            'banda' => null,
        ],
        // --- Controlador Hanna BL132 (sensor_readings) ---
        'controlador_ph' => [
            'label' => 'Controlador — pH', 'unidade' => '', 'casas' => 2,
            'min' => 6.5, 'max' => 8.5, 'cor' => '#059669',
            'banda' => ['min' => DailyRecord::PH_MIN, 'max' => DailyRecord::PH_MAX],
            'sensor_campo' => 'ph',
        ],
        'controlador_orp' => [
            'label' => 'Controlador — ORP', 'unidade' => 'mV', 'casas' => 0,
            'min' => 500, 'max' => 900, 'cor' => '#d97706',
            'banda' => null,
            'sensor_campo' => 'orp',
        ],
        'controlador_temp' => [
            'label' => 'Controlador — Temp. Água', 'unidade' => '°C', 'casas' => 1,
            'min' => 22, 'max' => 32, 'cor' => '#dc2626',
            'banda' => null,
            'sensor_campo' => 'temperatura_agua',
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
                (string) $p->id => ($p->instalacao?->name ? $p->instalacao->name.' — ' : '').$p->name,
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
     * Normaliza um valor para o intervalo 0-100% com base nos limites de conformidade
     * da banda (CN 14/DA). Parâmetros sem banda usam os limites próprios de escala.
     */
    private function normalizarValor(float $valor, string $metrica, array $def, Pool $piscina): float
    {
        if ($metrica === 'temperatura' || $metrica === 'controlador_temp') {
            $normMin = $piscina->temp_min !== null ? (float) $piscina->temp_min : 20.0;
            $normMax = $piscina->temp_max !== null ? (float) $piscina->temp_max : 35.0;
        } elseif ($def['banda'] !== null) {
            $normMin = (float) $def['banda']['min'];
            $normMax = (float) $def['banda']['max'];
        } else {
            $normMin = $def['min'] !== null ? (float) $def['min'] : 0.0;
            $normMax = (float) $def['max'];
        }

        $intervalo = $normMax - $normMin;

        if ($intervalo === 0.0) {
            return 50.0;
        }

        return round(($valor - $normMin) / $intervalo * 100, 2);
    }

    /**
     * Blocos de gráficos a desenhar.
     * - 1 piscina  → UM gráfico com todos os parâmetros (manual + controlador), eixo normalizado.
     * - 2+ piscinas → um gráfico por parâmetro, cada série uma piscina.
     *
     * Métricas do controlador (sensor_campo) buscam em sensor_readings.
     * Renderizam com linha tracejada (`dashed: true`).
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

        // Separar métricas manuais das do controlador.
        $metricasManual = array_values(array_filter($metricas, fn ($m) => ! isset(self::METRICAS[$m]['sensor_campo'])));
        $metricasSensor = array_values(array_filter($metricas, fn ($m) => isset(self::METRICAS[$m]['sensor_campo'])));

        // Validação de nomes de colunas (prevenção de SQL injection nas métricas manuais).
        $metricasManual = array_values(array_filter($metricasManual, fn ($m) => preg_match('/^[a-z_]+$/', $m)));
        $allowedMetrics = array_keys(self::METRICAS);
        $metricasManual = array_values(array_intersect($metricasManual, $allowedMetrics));

        $temSensor = ! empty($metricasSensor);

        // Cache: só para 1 piscina, apenas métricas manuais (sensor é mais volátil).
        $cacheService = app(CacheService::class);
        if (count($piscinaIds) === 1 && ! $temSensor) {
            $poolId = $piscinaIds[0];
            $metricsHash = md5(json_encode($metricas) ?: '');
            $cached = $cacheService->getGraphData($poolId, $metricsHash);
            if ($cached !== null) {
                return $cached;
            }
        }

        // --- Dados de registo manual ---
        $registos = collect();
        if (! empty($metricasManual)) {
            $selectCols = array_map(fn ($m) => DB::raw("AVG({$m}) as {$m}"), $metricasManual);
            array_unshift($selectCols, 'pool_id', DB::raw('DATE(registado_em) as dia'));

            $registos = DailyRecord::select($selectCols)
                ->whereIn('pool_id', $piscinaIds)
                ->where('registado_em', '>=', Carbon::today()->subDays(13)->startOfDay())
                ->whereDoesntHave('correcoes')
                ->groupByRaw('pool_id, DATE(registado_em)')
                ->get()
                ->groupBy('pool_id');
        }

        // --- Dados do controlador (sensor_readings) ---
        $leiturasSensor = collect();
        if ($temSensor) {
            $camposSensor = array_unique(array_map(
                fn ($m) => self::METRICAS[$m]['sensor_campo'],
                $metricasSensor
            ));
            $selectSensor = array_map(fn ($c) => DB::raw("AVG({$c}) as {$c}"), $camposSensor);
            array_unshift($selectSensor, 'pool_id', DB::raw('DATE(lida_em) as dia'));

            $leiturasSensor = SensorReading::select($selectSensor)
                ->whereIn('pool_id', $piscinaIds)
                ->where('lida_em', '>=', Carbon::today()->subDays(13)->startOfDay())
                ->groupByRaw('pool_id, DATE(lida_em)')
                ->get()
                ->groupBy('pool_id');
        }

        $piscinas = Pool::query()->whereIn('id', $piscinaIds)
            ->orderBy('installation_id')->orderBy('name')->get();

        // Retorna valores reais por dia para uma métrica manual.
        $serieManual = function (int $poolId, string $metrica, array $def) use ($registos, $dias): array {
            $porDia = ($registos->get($poolId) ?? collect())->keyBy('dia');
            return $dias->map(function ($d) use ($porDia, $metrica, $def) {
                $r = $porDia->get($d->format('Y-m-d'));
                return $r && $r->{$metrica} !== null
                    ? round((float) $r->{$metrica}, $def['casas'])
                    : null;
            })->values()->toArray();
        };

        // Retorna valores reais por dia para uma métrica de sensor.
        $serieSensor = function (int $poolId, string $metrica, array $def) use ($leiturasSensor, $dias): array {
            $campo = $def['sensor_campo'];
            $porDia = ($leiturasSensor->get($poolId) ?? collect())->keyBy('dia');
            return $dias->map(function ($d) use ($porDia, $campo, $def) {
                $r = $porDia->get($d->format('Y-m-d'));
                return $r && $r->{$campo} !== null
                    ? round((float) $r->{$campo}, $def['casas'])
                    : null;
            })->values()->toArray();
        };

        // --- MODO 1 PISCINA: eixo único normalizado 0-100% ---
        if ($piscinas->count() === 1) {
            $p = $piscinas->first();
            $series = [];

            foreach ($metricas as $metrica) {
                $def = self::METRICAS[$metrica];
                $esSensor = isset($def['sensor_campo']);

                $valoresReais = $esSensor
                    ? $serieSensor($p->id, $metrica, $def)
                    : $serieManual($p->id, $metrica, $def);

                $valoresNorm = array_map(
                    fn ($v) => $v !== null ? $this->normalizarValor($v, $metrica, $def, $p) : null,
                    $valoresReais
                );

                $valoresReaisTooltip = array_map(function ($v) use ($def) {
                    if ($v === null) {
                        return null;
                    }
                    $formatado = number_format($v, $def['casas'], ',', '');
                    return $def['unidade'] ? "{$formatado} {$def['unidade']}" : $formatado;
                }, $valoresReais);

                $series[] = [
                    'label'    => $def['label'],
                    'cor'      => $def['cor'],
                    'data'     => $valoresNorm,
                    'dataReal' => $valoresReaisTooltip,
                    'unidade'  => $def['unidade'],
                    'dashed'   => $esSensor,
                ];
            }

            $grafico = [[
                'modo'   => 'multi-metrica',
                'titulo' => $p->instalacao?->name ? "{$p->instalacao->name} — {$p->name}" : $p->name,
                'labels' => $labels,
                'series' => $series,
                'bandaNormalizada' => ['min' => 0, 'max' => 100],
            ]];

            if (! $temSensor) {
                $metricsHash = md5(json_encode($metricas) ?: '');
                $cacheService->cacheGraphData($p->id, $grafico, 30);
            }

            return $grafico;
        }

        // --- MODO VÁRIAS PISCINAS: um gráfico por parâmetro ---
        $corPorPiscina = [];
        foreach ($piscinas->values() as $i => $p) {
            $corPorPiscina[$p->id] = self::CORES_PISCINA[$i % count(self::CORES_PISCINA)];
        }

        $graficos = [];
        foreach ($metricas as $metrica) {
            $def = self::METRICAS[$metrica];
            $esSensor = isset($def['sensor_campo']);
            $series = [];

            foreach ($piscinas as $p) {
                $valores = $esSensor
                    ? $serieSensor($p->id, $metrica, $def)
                    : $serieManual($p->id, $metrica, $def);

                $series[] = [
                    'label'  => $p->name,
                    'cor'    => $corPorPiscina[$p->id],
                    'data'   => $valores,
                    'dashed' => $esSensor,
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
