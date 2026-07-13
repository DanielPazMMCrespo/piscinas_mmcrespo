<?php declare(strict_types=1);
namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\SensorReading;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CloroPhChartWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';
    protected static bool $isDiscovered = false;
    protected static string $view = 'filament.widgets.painel-parametros';

    public ?string $poolSelecionada = null;
    public string $leftMetric = 'ph';
    public string $rightMetric = 'controlador_orp';
    public string $mode = 'dual';
    public array $selectedMetrics = ['ph', 'cloro_livre'];
    public string $selectedMetric = 'ph';
    public string $period = '7d';
    public string $tabAtiva = 'graph';
    public ?string $customStartDate = null;
    public ?string $customEndDate = null;

    private const PERIODOS_VALIDOS = ['6h', '24h', '7d', '14d', 'custom'];
    private const TABS_VALIDAS = ['graph', 'table'];
    private const MODOS_VALIDOS = ['dual', 'multi-metrica', 'multi-piscina'];
    private const PALETA_PISCINAS = ['#76b82a', '#2b9cd8', '#d97706', '#8b5cf6', '#dc2626'];

    private function poolsQuery()
    {
        $query = Pool::query()->where('active', true);

        if (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->whereIn('id', auth()->user()->piscinas()->pluck('pools.id'));
        }

        return $query;
    }

    private static function getMetricas(): array
    {
        return [
            'cloro_livre' => [
                'label' => 'Cloro Livre', 'unidade' => 'mg/L', 'casas' => 2,
                'min' => 0.0, 'max' => 2.5, 'cor' => '#2b9cd8',
                'banda' => ['min' => DailyRecord::CLORO_LIVRE_MIN, 'max' => DailyRecord::CLORO_LIVRE_MAX],
            ],
            'cloro_total' => [
                'label' => 'Cloro Total', 'unidade' => 'mg/L', 'casas' => 2,
                'min' => 0.0, 'max' => 3.0, 'cor' => '#0e7490',
                'banda' => null,
            ],
            'ph' => [
                'label' => 'pH', 'unidade' => '', 'casas' => 2,
                'min' => 6.5, 'max' => 8.5, 'cor' => '#76b82a',
                'banda' => ['min' => DailyRecord::PH_MIN, 'max' => DailyRecord::PH_MAX],
            ],
            'temperatura' => [
                'label' => 'Temperatura', 'unidade' => '°C', 'casas' => 1,
                'min' => 22.0, 'max' => 32.0, 'cor' => '#e0a800',
                'banda' => null,
            ],
            'transparencia' => [
                'label' => 'Turbidez', 'unidade' => 'FNU', 'casas' => 2,
                'min' => 0.0, 'max' => 1.0, 'cor' => '#8b5cf6',
                'banda' => null,
            ],
            'controlador_ph' => [
                'label' => 'Controlador — pH', 'unidade' => '', 'casas' => 2,
                'min' => 6.5, 'max' => 8.5, 'cor' => '#059669',
                'banda' => ['min' => DailyRecord::PH_MIN, 'max' => DailyRecord::PH_MAX],
                'sensor_campo' => 'ph',
            ],
            'controlador_orp' => [
                'label' => 'Controlador — ORP', 'unidade' => 'mV', 'casas' => 0,
                'min' => 580.0, 'max' => 820.0, 'cor' => '#d97706',
                'banda' => ['min' => 660, 'max' => 750],
                'sensor_campo' => 'orp',
            ],
            'controlador_temp' => [
                'label' => 'Controlador — Temp. Água', 'unidade' => '°C', 'casas' => 1,
                'min' => 22.0, 'max' => 32.0, 'cor' => '#dc2626',
                'banda' => null,
                'sensor_campo' => 'temperatura_agua',
            ],
        ];
    }

    public function mount(): void
    {
        $primeiraPool = $this->poolsQuery()
            ->orderBy('installation_id')->orderBy('name')
            ->value('id');

        $this->poolSelecionada = $primeiraPool !== null ? (string) $primeiraPool : null;

        $this->customStartDate = now()->subDays(7)->format('Y-m-d');
        $this->customEndDate = now()->format('Y-m-d');

        $this->form->fill([
            'poolSelecionada' => $this->poolSelecionada,
            'leftMetric'      => $this->leftMetric,
            'rightMetric'     => $this->rightMetric,
            'mode'            => $this->mode,
            'selectedMetrics' => $this->selectedMetrics,
            'selectedMetric'  => $this->selectedMetric,
            'customStartDate' => $this->customStartDate,
            'customEndDate'   => $this->customEndDate,
        ]);
    }

    protected function getFormSchema(): array
    {
        $opcoesPiscinas = $this->poolsQuery()->with('instalacao')
            ->orderBy('installation_id')->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Pool $p) => [
                (string) $p->id => ($p->instalacao?->name ? $p->instalacao->name.' — ' : '').$p->name,
            ])->toArray();

        $opcoesMetricas = collect(self::getMetricas())
            ->mapWithKeys(fn ($m, $k) => [$k => $m['label']])->toArray();

        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('poolSelecionada')
                    ->label('Piscina')
                    ->options($opcoesPiscinas)
                    ->required()
                    ->live()
                    ->hidden(fn () => $this->mode === 'multi-piscina')
                    ->afterStateUpdated(fn () => $this->dispatchChartRefresh()),
                Forms\Components\Select::make('leftMetric')
                    ->label('Eixo Esquerdo')
                    ->options($opcoesMetricas)
                    ->required()
                    ->live()
                    ->hidden(fn () => $this->mode !== 'dual')
                    ->afterStateUpdated(fn () => $this->dispatchChartRefresh()),
                Forms\Components\Select::make('rightMetric')
                    ->label('Eixo Direito')
                    ->options($opcoesMetricas)
                    ->required()
                    ->live()
                    ->hidden(fn () => $this->mode !== 'dual')
                    ->afterStateUpdated(fn () => $this->dispatchChartRefresh()),
                Forms\Components\Select::make('selectedMetrics')
                    ->label('Métricas')
                    ->options($opcoesMetricas)
                    ->multiple()
                    ->required()
                    ->live()
                    ->hidden(fn () => $this->mode !== 'multi-metrica')
                    ->afterStateUpdated(fn () => $this->dispatchChartRefresh()),
                Forms\Components\Select::make('selectedMetric')
                    ->label('Métrica')
                    ->options($opcoesMetricas)
                    ->required()
                    ->live()
                    ->hidden(fn () => $this->mode !== 'multi-piscina')
                    ->afterStateUpdated(fn () => $this->dispatchChartRefresh()),
            ]),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\DatePicker::make('customStartDate')
                    ->label('Data Início')
                    ->hidden(fn () => $this->period !== 'custom')
                    ->live()
                    ->afterStateUpdated(fn () => $this->dispatchChartRefresh()),
                Forms\Components\DatePicker::make('customEndDate')
                    ->label('Data Fim')
                    ->hidden(fn () => $this->period !== 'custom')
                    ->live()
                    ->afterStateUpdated(fn () => $this->dispatchChartRefresh()),
            ]),
        ];
    }

    public function setMode(string $m): void
    {
        if (! in_array($m, self::MODOS_VALIDOS, true)) {
            return;
        }
        $this->mode = $m;
        $this->dispatchChartRefresh();
    }

    public function setPeriod(string $p): void
    {
        if (! in_array($p, self::PERIODOS_VALIDOS, true)) {
            return;
        }
        $this->period = $p;
        $this->dispatchChartRefresh();
    }

    private function dispatchChartRefresh(): void
    {
        if ($this->tabAtiva !== 'graph') {
            return;
        }
        if ($this->mode !== 'multi-piscina' && $this->poolSelecionada === null) {
            return;
        }
        $this->dispatch('mmc-chart-update', payload: $this->getChartPayload());
    }

    public function setTab(string $t): void
    {
        if (! in_array($t, self::TABS_VALIDAS, true)) {
            return;
        }
        $this->tabAtiva = $t;
    }

    private function getPeriodStart(): Carbon
    {
        return match ($this->period) {
            '6h'     => now()->subHours(6),
            '24h'    => now()->subHours(24),
            '7d'     => now()->subDays(7)->startOfDay(),
            '14d'    => now()->subDays(14)->startOfDay(),
            'custom' => $this->customStartDate ? Carbon::parse($this->customStartDate)->startOfDay() : now()->subDays(7)->startOfDay(),
            default  => now()->subDays(7)->startOfDay(),
        };
    }

    private function getPeriodEnd(): Carbon
    {
        return match ($this->period) {
            'custom' => $this->customEndDate ? Carbon::parse($this->customEndDate)->endOfDay() : now(),
            default  => now(),
        };
    }

    private function isShortPeriod(): bool
    {
        return in_array($this->period, ['6h', '24h'], true);
    }

    private function remember(string $key, bool $hasSensor, \Closure $build): array
    {
        $canCache = ! $this->isShortPeriod() && ! $hasSensor;

        if ($canCache) {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $payload = $build();

        if ($canCache) {
            Cache::put($key, $payload, now()->addMinutes(10));
        }

        return $payload;
    }

    private function buildSerie(string $metricKey, int $poolId, ?string $labelOverride = null): array
    {
        $def = self::getMetricas()[$metricKey];
        $start = $this->getPeriodStart();
        $end = $this->getPeriodEnd();
        $isSensor = isset($def['sensor_campo']);

        if ($isSensor) {
            $campo = $def['sensor_campo'];

            $rows = SensorReading::query()
                ->select(['lida_em', $campo])
                ->where('pool_id', $poolId)
                ->where('lida_em', '>=', $start)
                ->where('lida_em', '<=', $end)
                ->orderBy('lida_em')
                ->get();

            $data = $rows->filter(fn ($r) => $r->{$campo} !== null)
                ->map(fn ($r) => [
                    'x' => $r->lida_em->toIso8601String(),
                    'y' => round((float) $r->{$campo}, $def['casas']),
                ])->values()->toArray();
        } else {
            $campo = $metricKey;
            $nsCampo = 'ns_' . $campo;

            $rows = DailyRecord::query()
                ->select(['registado_em', $campo, $nsCampo])
                ->where('pool_id', $poolId)
                ->where('registado_em', '>=', $start)
                ->where('registado_em', '<=', $end)
                ->whereDoesntHave('correcoes')
                ->orderBy('registado_em')
                ->get();

            $data = $rows->map(fn ($r) => ['val' => $r->{$campo} ?? $r->{$nsCampo}, 'r' => $r])
                ->filter(fn ($item) => $item['val'] !== null)
                ->map(fn ($item) => [
                    'x' => $item['r']->registado_em->toIso8601String(),
                    'y' => round((float) $item['val'], $def['casas']),
                ])->values()->toArray();
        }

        return [
            'key'     => $metricKey,
            'label'   => $labelOverride ?? $def['label'],
            'unidade' => $def['unidade'],
            'casas'   => $def['casas'],
            'cor'     => $def['cor'],
            'banda'   => $def['banda'],
            'yMin'    => $def['min'],
            'yMax'    => $def['max'],
            'data'    => $data,
        ];
    }

    public function getChartPayload(): array
    {
        if ($this->mode !== 'multi-piscina' && $this->poolSelecionada === null) {
            return [];
        }

        $metricas = self::getMetricas();

        return match ($this->mode) {
            'multi-metrica' => $this->payloadMultiMetrica($metricas),
            'multi-piscina' => $this->payloadMultiPiscina($metricas),
            default         => $this->payloadDual($metricas),
        };
    }

    private function payloadDual(array $metricas): array
    {
        $leftKey = array_key_exists($this->leftMetric, $metricas) ? $this->leftMetric : 'ph';
        $rightKey = array_key_exists($this->rightMetric, $metricas) ? $this->rightMetric : 'controlador_orp';
        $poolId = (int) $this->poolSelecionada;
        $hasSensor = isset($metricas[$leftKey]['sensor_campo']) || isset($metricas[$rightKey]['sensor_campo']);
        $key = "chart_v4_dual_{$poolId}_{$leftKey}_{$rightKey}_{$this->period}_{$this->customStartDate}_{$this->customEndDate}";

        return $this->remember($key, $hasSensor, function () use ($leftKey, $rightKey, $poolId) {
            $left = $this->buildSerie($leftKey, $poolId);
            $left['axis'] = 'left';
            $right = $this->buildSerie($rightKey, $poolId);
            $right['axis'] = 'right';

            return [
                'mode'   => 'dual',
                'period' => $this->period,
                'series' => [$left, $right],
            ];
        });
    }

    private function payloadMultiMetrica(array $metricas): array
    {
        $poolId = (int) $this->poolSelecionada;
        $keys = array_values(array_filter($this->selectedMetrics, fn ($k) => array_key_exists($k, $metricas)));
        if ($keys === []) {
            $keys = ['ph'];
        }
        $hasSensor = collect($keys)->contains(fn ($k) => isset($metricas[$k]['sensor_campo']));
        $keysStr = implode('-', $keys);
        $key = "chart_v4_multimet_{$poolId}_{$keysStr}_{$this->period}_{$this->customStartDate}_{$this->customEndDate}";

        return $this->remember($key, $hasSensor, function () use ($keys, $poolId) {
            $series = array_map(fn ($k) => $this->buildSerie($k, $poolId), $keys);

            return [
                'mode'   => 'multi-metrica',
                'period' => $this->period,
                'series' => $series,
            ];
        });
    }

    private function payloadMultiPiscina(array $metricas): array
    {
        $metricKey = array_key_exists($this->selectedMetric, $metricas) ? $this->selectedMetric : 'ph';
        $def = $metricas[$metricKey];
        $hasSensor = isset($def['sensor_campo']);

        $pools = $this->poolsQuery()->with('instalacao')
            ->orderBy('installation_id')->orderBy('name')
            ->get()
            ->values();

        $poolIds = $pools->pluck('id')->implode('-');
        $key = "chart_v4_multipool_{$metricKey}_{$poolIds}_{$this->period}_{$this->customStartDate}_{$this->customEndDate}";

        return $this->remember($key, $hasSensor, function () use ($metricKey, $def, $pools) {
            $series = [];
            foreach ($pools as $i => $pool) {
                $label = ($pool->instalacao?->name ? $pool->instalacao->name.' — ' : '').$pool->name;
                $serie = $this->buildSerie($metricKey, (int) $pool->id, $label);
                $serie['cor'] = self::PALETA_PISCINAS[$i % count(self::PALETA_PISCINAS)];
                $series[] = $serie;
            }

            return [
                'mode'    => 'multi-piscina',
                'period'  => $this->period,
                'metrica' => [
                    'label'   => $def['label'],
                    'unidade' => $def['unidade'],
                    'banda'   => $def['banda'],
                ],
                'series'  => $series,
            ];
        });
    }

    public function getTableRows(): array
    {
        if ($this->poolSelecionada === null) {
            return ['manual' => [], 'sensor' => []];
        }

        $poolId = (int) $this->poolSelecionada;
        $start = $this->getPeriodStart();
        $end = $this->getPeriodEnd();

        $manual = DailyRecord::query()
            ->select(['registado_em', 'ph', 'cloro_livre', 'cloro_total', 'transparencia', 'temperatura', 'ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura'])
            ->where('pool_id', $poolId)
            ->where('registado_em', '>=', $start)
            ->where('registado_em', '<=', $end)
            ->whereDoesntHave('correcoes')
            ->orderByDesc('registado_em')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'data'        => $r->registado_em->format('d/m H:i'),
                'ph'          => $r->ph_efetivo !== null ? number_format((float) $r->ph_efetivo, 2, ',', '') : '—',
                'cloro_livre' => $r->cloro_livre_efetivo !== null ? number_format((float) $r->cloro_livre_efetivo, 2, ',', '') : '—',
                'cloro_total' => $r->cloro_total_efetivo !== null ? number_format((float) $r->cloro_total_efetivo, 2, ',', '') : '—',
                'turbidez'    => $r->transparencia !== null ? number_format((float) $r->transparencia, 2, ',', '') : '—',
                'temperatura' => $r->temperatura_efetivo !== null ? number_format((float) $r->temperatura_efetivo, 1, ',', '') : '—',
            ])->toArray();

        $sensor = SensorReading::query()
            ->select(['lida_em', 'ph', 'orp', 'temperatura_agua'])
            ->where('pool_id', $poolId)
            ->where('lida_em', '>=', $start)
            ->where('lida_em', '<=', $end)
            ->orderByDesc('lida_em')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'data' => $r->lida_em->format('d/m H:i'),
                'ph'   => $r->ph !== null ? number_format((float) $r->ph, 2, ',', '') : '—',
                'orp'  => $r->orp !== null ? number_format((float) $r->orp, 0, ',', '') : '—',
                'temp' => $r->temperatura_agua !== null ? number_format((float) $r->temperatura_agua, 1, ',', '') : '—',
            ])->toArray();

        return ['manual' => $manual, 'sensor' => $sensor];
    }
}
