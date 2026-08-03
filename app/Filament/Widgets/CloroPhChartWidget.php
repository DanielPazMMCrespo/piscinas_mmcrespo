<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Services\LeituraArtefactoService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CloroPhChartWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.widgets.painel-parametros';

    public ?string $poolSelecionada = null;

    public string $leftMetric = 'controlador_ph';

    public string $rightMetric = 'controlador_orp';

    public string $period = '7d';

    public string $tabAtiva = 'graph';

    public ?string $customStartDate = null;

    public ?string $customEndDate = null;

    private const NS_CAMPOS = ['ph', 'cloro_livre', 'cloro_total', 'temperatura'];

    private const PERIODOS_VALIDOS = ['12h', '6h', '24h', '7d', '14d', 'custom'];

    private const TABS_VALIDAS = ['graph', 'table'];

    /** Métricas escondidas do Nadador-Salvador (sem relevância operacional para o seu papel). */
    private const METRICAS_OCULTAS_NS = ['transparencia'];

    public function isNS(): bool
    {
        return auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false;
    }

    private function poolsQuery()
    {
        $query = Pool::query()->where('active', true);

        if ($this->isNS()) {
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
                'banda' => ['min' => DailyRecord::getCloroLivreMin(), 'max' => DailyRecord::getCloroLivreMax()],
            ],
            'cloro_total' => [
                'label' => 'Cloro Total', 'unidade' => 'mg/L', 'casas' => 2,
                'min' => 0.0, 'max' => 3.0, 'cor' => '#0e7490',
                'banda' => null,
            ],
            'cloro_combinado' => [
                'label' => 'Cloro Combinado', 'unidade' => 'mg/L', 'casas' => 2,
                'min' => 0.0, 'max' => 1.5, 'cor' => '#b45309',
                'banda' => ['min' => 0.0, 'max' => DailyRecord::getCloroCombinadoMax()],
            ],
            'ph' => [
                'label' => 'pH', 'unidade' => '', 'casas' => 2,
                'min' => 6.5, 'max' => 8.5, 'cor' => '#76b82a',
                'banda' => ['min' => DailyRecord::getPhMin(), 'max' => DailyRecord::getPhMax()],
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
                'banda' => ['min' => DailyRecord::getPhMin(), 'max' => DailyRecord::getPhMax()],
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

    public static function canView(): bool
    {
        return (bool) auth()->user()?->podeVer(NSPermission::ANALISE_PARAMETROS);
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);

        $primeiraPool = $this->poolsQuery()
            ->orderBy('installation_id')->orderBy('name')
            ->value('id');

        $this->poolSelecionada = $primeiraPool !== null ? (string) $primeiraPool : null;

        if ($this->isNS()) {
            $this->period = '12h';
        }

        $this->customStartDate = now()->subDays(7)->format('Y-m-d');
        $this->customEndDate = now()->format('Y-m-d');

        $this->form->fill([
            'poolSelecionada' => $this->poolSelecionada,
            'leftMetric' => $this->leftMetric,
            'rightMetric' => $this->rightMetric,
            'customStartDate' => $this->customStartDate,
            'customEndDate' => $this->customEndDate,
        ]);
    }

    protected function getFormSchema(): array
    {
        // Encerradas continuam selecionáveis: é sobre o histórico delas que se
        // consulta o gráfico. A linha já quebra nos dias sem dados (spanGaps
        // false), por isso o período fechado lê-se como interrupção.
        $opcoesPiscinas = $this->poolsQuery()->with(['instalacao', 'encerramentos'])
            ->orderBy('installation_id')->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Pool $p) => [
                (string) $p->id => $p->nomeCompleto(' — ').($p->estaEncerradaEm() ? ' (encerrada)' : ''),
            ])->toArray();

        $opcoesMetricas = collect(self::getMetricas())
            ->reject(fn ($m, $k) => $this->isNS() && in_array($k, self::METRICAS_OCULTAS_NS, true))
            ->mapWithKeys(fn ($m, $k) => [$k => $m['label']])->toArray();

        return [
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('poolSelecionada')
                    ->label('Piscina')
                    ->options($opcoesPiscinas)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->dispatchChartRefresh()),
                Forms\Components\Select::make('leftMetric')
                    ->label('Eixo Esquerdo')
                    ->options($opcoesMetricas)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->dispatchChartRefresh()),
                Forms\Components\Select::make('rightMetric')
                    ->label('Eixo Direito')
                    ->options($opcoesMetricas)
                    ->required()
                    ->live()
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

    public function setPeriod(string $p): void
    {
        if ($this->isNS()) {
            return;
        }
        if (! in_array($p, self::PERIODOS_VALIDOS, true)) {
            return;
        }
        $this->period = $p;
        $this->dispatchChartRefresh();
    }

    /**
     * Atalho "Sensor vs Manual": preenche os dois eixos com o mesmo parâmetro
     * (leitura do controlador Hanna à esquerda, leitura manual do técnico à
     * direita), para comparar visualmente e detetar desvios de calibração.
     */
    public function presetSensorVsManual(string $metricaControlador, string $metricaManual): void
    {
        $metricas = self::getMetricas();
        if (! array_key_exists($metricaControlador, $metricas) || ! array_key_exists($metricaManual, $metricas)) {
            return;
        }

        $this->leftMetric = $metricaControlador;
        $this->rightMetric = $metricaManual;

        $this->form->fill([
            'poolSelecionada' => $this->poolSelecionada,
            'leftMetric' => $this->leftMetric,
            'rightMetric' => $this->rightMetric,
            'customStartDate' => $this->customStartDate,
            'customEndDate' => $this->customEndDate,
        ]);

        $this->dispatchChartRefresh();
    }

    private function dispatchChartRefresh(): void
    {
        if ($this->tabAtiva === 'graph' && $this->poolSelecionada !== null) {
            $this->dispatch('mmc-chart-update', payload: $this->getChartPayload());
        }
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
            '12h' => now()->subHours(12),
            '6h' => now()->subHours(6),
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7)->startOfDay(),
            '14d' => now()->subDays(14)->startOfDay(),
            'custom' => $this->customStartDate ? Carbon::parse($this->customStartDate)->startOfDay() : now()->subDays(7)->startOfDay(),
            default => now()->subDays(7)->startOfDay(),
        };
    }

    private function getPeriodEnd(): Carbon
    {
        return match ($this->period) {
            'custom' => $this->customEndDate ? Carbon::parse($this->customEndDate)->endOfDay() : now(),
            default => now(),
        };
    }

    private function isShortPeriod(): bool
    {
        return in_array($this->period, ['12h', '6h', '24h'], true);
    }

    private function buildMetricAxis(string $metricKey): array
    {
        $metricas = self::getMetricas();
        if (! array_key_exists($metricKey, $metricas)) {
            return [];
        }

        $def = $metricas[$metricKey];
        $poolId = (int) $this->poolSelecionada;
        $start = $this->getPeriodStart();
        $end = $this->getPeriodEnd();
        $isSensor = isset($def['sensor_campo']);
        $datasets = [];

        if ($isSensor) {
            $campo = $def['sensor_campo'];

            $rows = SensorReading::query()
                ->select(['lida_em', $campo])
                ->where('pool_id', $poolId)
                ->where('lida_em', '>=', $start)
                ->where('lida_em', '<=', $end)
                ->orderBy('lida_em')
                ->get();

            // Exclui leituras artefacto (lavagem/bomba parada): não circula água
            // no sensor e o valor não reflete a qualidade real.
            $janelas = app(LeituraArtefactoService::class)->janelas($poolId, $start, $end);
            $emArtefacto = fn ($lidaEm): bool => collect($janelas)
                ->contains(fn (array $j) => $lidaEm->gte($j['inicio']) && $lidaEm->lte($j['fim']));

            $data = $rows->filter(fn ($r) => $r->{$campo} !== null && ! $emArtefacto($r->lida_em))
                ->map(fn ($r) => [
                    'x' => $r->lida_em->toIso8601String(),
                    'y' => round((float) $r->{$campo}, $def['casas']),
                ])->values()->toArray();

            $datasets[] = ['label' => $def['label'], 'data' => $data, 'dashed' => true];
        } else {
            $campo = $metricKey;

            if ($campo === 'cloro_combinado') {
                $colunas = ['registado_em', 'cloro_livre', 'cloro_total', 'ns_cloro_livre', 'ns_cloro_total'];
            } else {
                $nsCampo = in_array($campo, self::NS_CAMPOS, true) ? 'ns_'.$campo : null;
                $colunas = $nsCampo !== null ? ['registado_em', $campo, $nsCampo] : ['registado_em', $campo];
            }

            $rows = DailyRecord::query()
                ->select($colunas)
                ->where('pool_id', $poolId)
                ->where('registado_em', '>=', $start)
                ->where('registado_em', '<=', $end)
                ->whereDoesntHave('correcoes')
                ->orderBy('registado_em')
                ->get();

            $data = $rows->map(fn ($r) => [
                'val' => $campo === 'cloro_combinado'
                    ? $r->cloro_combinado
                    : ($r->{$campo} ?? ($nsCampo !== null ? $r->{$nsCampo} : null)),
                'r' => $r,
            ])
                ->filter(fn ($item) => $item['val'] !== null)
                ->map(fn ($item) => [
                    'x' => $item['r']->registado_em->toIso8601String(),
                    'y' => round((float) $item['val'], $def['casas']),
                ])->values()->toArray();

            $datasets[] = [
                'label' => $def['label'],
                'data' => $data,
                'dashed' => false,
            ];
        }

        return [
            'key' => $metricKey,
            'label' => $def['label'],
            'unidade' => $def['unidade'],
            'casas' => $def['casas'],
            'yMin' => $def['min'],
            'yMax' => $def['max'],
            'cor' => $def['cor'],
            'banda' => $def['banda'],
            'datasets' => $datasets,
        ];
    }

    /** @var array<string, mixed>|null */
    private ?array $payloadMemo = null;

    public function getChartPayload(): array
    {
        if ($this->payloadMemo !== null) {
            return $this->payloadMemo;
        }

        $this->payloadMemo = $this->calcularChartPayload();

        return $this->payloadMemo;
    }

    private function calcularChartPayload(): array
    {
        if ($this->poolSelecionada === null) {
            return [];
        }

        $metricas = self::getMetricas();
        $leftKey = array_key_exists($this->leftMetric, $metricas) ? $this->leftMetric : 'controlador_ph';
        $rightKey = array_key_exists($this->rightMetric, $metricas) ? $this->rightMetric : 'controlador_orp';

        // Cache only for long-period, manual-only queries (sensor data changes every 15 min).
        $leftIsSensor = isset($metricas[$leftKey]['sensor_campo']);
        $rightIsSensor = isset($metricas[$rightKey]['sensor_campo']);
        $canCache = ! $this->isShortPeriod() && ! $leftIsSensor && ! $rightIsSensor;

        // Prefixo cache_graph_{pool_id}_ obrigatório: é o padrão que o
        // CacheService::invalidateGraphCache() apaga quando entra um registo novo
        // (DailyRecordObserver) ou a piscina muda. Com a chave antiga (chart_v3_*)
        // a invalidação não acertava em nada e o gráfico ficava 10 min desatualizado.
        $cacheKey = "cache_graph_{$this->poolSelecionada}_v3_{$leftKey}_{$rightKey}_{$this->period}_{$this->customStartDate}_{$this->customEndDate}";

        if ($canCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $pool = Pool::with('instalacao')->find((int) $this->poolSelecionada);
        $titulo = $pool ? $pool->nomeCompleto(' — ') : '';

        $payload = [
            'titulo' => $titulo,
            'period' => $this->period,
            'left' => $this->buildMetricAxis($leftKey),
            'right' => $this->buildMetricAxis($rightKey),
        ];

        if ($canCache) {
            Cache::put($cacheKey, $payload, now()->addMinutes(10));
        }

        return $payload;
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
                'data' => $r->registado_em->format('d/m H:i'),
                'ph' => $r->ph_efetivo !== null ? number_format((float) $r->ph_efetivo, 2, ',', '') : '—',
                'cloro_livre' => $r->cloro_livre_efetivo !== null ? number_format((float) $r->cloro_livre_efetivo, 2, ',', '') : '—',
                'cloro_total' => $r->cloro_total_efetivo !== null ? number_format((float) $r->cloro_total_efetivo, 2, ',', '') : '—',
                'turbidez' => $r->transparencia !== null ? number_format((float) $r->transparencia, 2, ',', '') : '—',
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
                'ph' => $r->ph !== null ? number_format((float) $r->ph, 2, ',', '') : '—',
                'orp' => $r->orp !== null ? number_format((float) $r->orp, 0, ',', '') : '—',
                'temp' => $r->temperatura_agua !== null ? number_format((float) $r->temperatura_agua, 1, ',', '') : '—',
            ])->toArray();

        return ['manual' => $manual, 'sensor' => $sensor];
    }
}
