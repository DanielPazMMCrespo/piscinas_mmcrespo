# Gráfico de Análise ECharts — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir Chart.js por Apache ECharts no gráfico da página de Análise de Parâmetros, com crosshair/tooltip, banda legal, não-conformidade pintada na linha, minimapa de navegação temporal e três modos de comparação (dual/multi-métrica/multi-piscina).

**Architecture:** O widget Livewire `CloroPhChartWidget` mantém-se e passa a devolver um payload multi-modo (`{ mode, period, series: [...] }`). Um novo componente Alpine `mmcEcharts` (ficheiro isolado `resources/js/charts/analise.js`) renderiza com ECharts, mantendo o ciclo de vida provado do componente atual (`wire:ignore` + evento Livewire `mmc-chart-update` + `dispose()` no destroy). O Chart.js coexiste até validação em produção.

**Tech Stack:** Laravel 12, Filament 3.3, Livewire 3, Alpine.js, Apache ECharts 5 (import tree-shaken), Vite.

**Spec:** `docs/superpowers/specs/2026-07-13-grafico-analise-echarts-design.md`

**Regra da fase final:** alterações cirúrgicas apenas. Proibido restaurar ficheiros inteiros de commits antigos. Cada commit toca só os ficheiros do seu passo.

---

## Estrutura de ficheiros

| Ficheiro | Ação | Responsabilidade |
|---|---|---|
| `package.json` | Modificar | Adicionar dependência `echarts` |
| `app/Filament/Widgets/CloroPhChartWidget.php` | Modificar | Properties de modo, form com seletor de modo, payload multi-modo (`getChartPayload`, `buildSerie`, `payloadDual/MultiMetrica/MultiPiscina`, `remember`) |
| `tests/Feature/ChartPayloadTest.php` | Criar | Cobertura dos 3 modos, scope NS, exclusão de correções, coalesce `ns_*` |
| `resources/js/charts/analise.js` | Criar | Componente Alpine `mmcEcharts` (render ECharts, normalização, visualMap, dataZoom, dark mode, ciclo de vida) |
| `resources/js/app.js` | Modificar | Importar `./charts/analise.js` |
| `resources/views/filament/widgets/painel-parametros.blade.php` | Modificar | Seletor de modo, selects condicionais, container ECharts |

O modo `dual` é o default e replica o comportamento atual (2 eixos reais).

---

## Task 1: Adicionar ECharts como dependência

**Files:**
- Modify: `package.json`

- [ ] **Step 1: Instalar echarts**

Run:
```bash
npm install echarts@^5.5.0
```
Expected: `package.json` passa a listar `"echarts": "^5.5.0"` em `dependencies`; sem erros de peer deps.

- [ ] **Step 2: Confirmar import tree-shaken resolve**

Run:
```bash
node -e "import('echarts/core').then(m => console.log(typeof m.init === 'function' || typeof m.use === 'function' ? 'OK' : 'FAIL'))"
```
Expected: imprime `OK`.

- [ ] **Step 3: Commit**

```bash
git add package.json package-lock.json
git commit -m "chore(charts): adicionar echarts como dependencia"
```

---

## Task 2: Payload multi-modo no widget + testes

Refatora `getChartPayload()` para despachar por modo e adiciona os métodos de construção de séries. Mantém as queries atuais (coalesce `ns_*`, `whereDoesntHave('correcoes')`, sensor de `sensor_readings`, cache em períodos longos sem sensor).

**Files:**
- Modify: `app/Filament/Widgets/CloroPhChartWidget.php`
- Test: `tests/Feature/ChartPayloadTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Cria `tests/Feature/ChartPayloadTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\CloroPhChartWidget;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChartPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->syncRoles(['admin']);
        return $u;
    }

    private function pool(string $name, Installation $inst): Pool
    {
        return Pool::create([
            'installation_id' => $inst->id,
            'name' => $name,
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);
    }

    private function record(Pool $pool, array $attrs): DailyRecord
    {
        return DailyRecord::create(array_merge([
            'pool_id' => $pool->id,
            'user_id' => $this->admin()->id,
            'registado_em' => Carbon::now()->subDay(),
            'agua_modo' => 'off',
        ], $attrs));
    }

    public function test_dual_mode_returns_two_series_with_axis_and_band(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $pool = $this->pool('Competição', $inst);
        $this->record($pool, ['ph' => 7.4, 'cloro_livre' => 1.2]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'dual')
            ->set('leftMetric', 'ph')
            ->set('rightMetric', 'cloro_livre')
            ->set('poolSelecionada', (string) $pool->id);

        $payload = $widget->instance()->getChartPayload();

        $this->assertSame('dual', $payload['mode']);
        $this->assertCount(2, $payload['series']);
        $this->assertSame('left', $payload['series'][0]['axis']);
        $this->assertSame('right', $payload['series'][1]['axis']);
        $this->assertSame('ph', $payload['series'][0]['key']);
        $this->assertNotNull($payload['series'][0]['banda']);
        $this->assertCount(1, $payload['series'][0]['data']);
        $this->assertEqualsWithDelta(7.4, $payload['series'][0]['data'][0]['y'], 0.001);
    }

    public function test_multi_metrica_filters_invalid_keys_and_carries_ymin_ymax(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $pool = $this->pool('Competição', $inst);
        $this->record($pool, ['ph' => 7.4, 'cloro_livre' => 1.2]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'multi-metrica')
            ->set('selectedMetrics', ['ph', 'cloro_livre', 'inexistente'])
            ->set('poolSelecionada', (string) $pool->id);

        $payload = $widget->instance()->getChartPayload();

        $this->assertSame('multi-metrica', $payload['mode']);
        $this->assertCount(2, $payload['series']);
        $keys = array_column($payload['series'], 'key');
        $this->assertSame(['ph', 'cloro_livre'], $keys);
        $this->assertArrayHasKey('yMin', $payload['series'][0]);
        $this->assertArrayHasKey('yMax', $payload['series'][0]);
    }

    public function test_multi_piscina_one_series_per_active_pool(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $p1 = $this->pool('Competição', $inst);
        $p2 = $this->pool('Lazer', $inst);
        $this->record($p1, ['ph' => 7.4]);
        $this->record($p2, ['ph' => 7.1]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'multi-piscina')
            ->set('selectedMetric', 'ph');

        $payload = $widget->instance()->getChartPayload();

        $this->assertSame('multi-piscina', $payload['mode']);
        $this->assertCount(2, $payload['series']);
        $this->assertSame('Leiria — Competição', $payload['series'][0]['label']);
        $this->assertNotSame($payload['series'][0]['cor'], $payload['series'][1]['cor']);
        $this->assertSame('pH', $payload['metrica']['label']);
    }

    public function test_multi_piscina_respects_nadador_salvador_scope(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $p1 = $this->pool('Competição', $inst);
        $p2 = $this->pool('Lazer', $inst);
        $this->record($p1, ['ph' => 7.4]);
        $this->record($p2, ['ph' => 7.1]);

        $ns = User::factory()->create();
        $ns->syncRoles(['nadador_salvador']);
        $ns->piscinas()->sync([$p1->id]);
        $this->actingAs($ns);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'multi-piscina')
            ->set('selectedMetric', 'ph');

        $payload = $widget->instance()->getChartPayload();

        $this->assertCount(1, $payload['series']);
        $this->assertSame('Leiria — Competição', $payload['series'][0]['label']);
    }

    public function test_corrected_records_excluded_and_ns_fallback_used(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $pool = $this->pool('Competição', $inst);

        $original = $this->record($pool, ['ph' => 6.0]);
        $this->record($pool, ['ph' => 7.4, 'e_correcao' => true, 'corrige_registo_id' => $original->id]);
        $this->record($pool, ['ph' => null, 'ns_ph' => 7.2]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'dual')
            ->set('leftMetric', 'ph')
            ->set('rightMetric', 'cloro_livre')
            ->set('poolSelecionada', (string) $pool->id);

        $payload = $widget->instance()->getChartPayload();
        $ys = array_column($payload['series'][0]['data'], 'y');

        $this->assertNotContains(6.0, $ys);
        $this->assertContains(7.2, $ys);
    }
}
```

- [ ] **Step 2: Correr o teste para confirmar que falha**

Run: `php artisan test --filter=ChartPayloadTest`
Expected: FAIL — payload atual devolve `left`/`right`, não `mode`/`series`; `set('mode', ...)` falha porque a property `mode` ainda não existe.

- [ ] **Step 3: Adicionar properties, constantes e paleta**

Em `app/Filament/Widgets/CloroPhChartWidget.php`, substitui o bloco de properties/constantes (linhas 25-34) por:

```php
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
```

- [ ] **Step 4: Adicionar `setMode()` e incluir `mode` no fill do mount**

Em `mount()`, dentro do `$this->form->fill([...])` (linhas 107-113), acrescenta as chaves de modo:

```php
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
```

Adiciona o método `setMode()` imediatamente antes de `setPeriod()` (antes da linha 164):

```php
    public function setMode(string $m): void
    {
        if (! in_array($m, self::MODOS_VALIDOS, true)) {
            return;
        }
        $this->mode = $m;
        $this->dispatchChartRefresh();
    }

```

- [ ] **Step 5: Estender o form com os campos de modo**

Substitui o `getFormSchema()` (linhas 116-162) por:

```php
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
```

- [ ] **Step 6: Ajustar o guard de `dispatchChartRefresh()` para o modo multi-piscina**

Substitui `dispatchChartRefresh()` (linhas 173-178) por:

```php
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
```

- [ ] **Step 7: Adicionar `buildSerie()` e o wrapper `remember()`**

Insere estes dois métodos imediatamente antes de `buildMetricAxis()` (antes da linha 213). Reutilizam exatamente a lógica de query existente:

```php
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
```

- [ ] **Step 8: Reescrever `getChartPayload()` com dispatch por modo**

Substitui `getChartPayload()` (linhas 288-329) por:

```php
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
```

Nota: `buildMetricAxis()` fica órfão após esta mudança. Remove-o (linhas 213-286 do original) no mesmo passo para não deixar dead code.

- [ ] **Step 9: Correr os testes para confirmar que passam**

Run: `php artisan test --filter=ChartPayloadTest`
Expected: PASS (5 testes, todas as asserções verdes).

- [ ] **Step 10: Confirmar que a suite não regrediu**

Run: `php artisan test`
Expected: sem novas falhas face ao baseline.

- [ ] **Step 11: Commit**

```bash
git add app/Filament/Widgets/CloroPhChartWidget.php tests/Feature/ChartPayloadTest.php
git commit -m "feat(charts): payload multi-modo no CloroPhChartWidget + testes"
```

---

## Task 3: Componente Alpine `mmcEcharts`

Novo ficheiro isolado. Espelha o ciclo de vida do `mmcChart` atual (init único, update via evento Livewire, retry de dimensões, ResizeObserver, dark mode) mas renderiza com ECharts.

**Files:**
- Create: `resources/js/charts/analise.js`
- Modify: `resources/js/app.js`

- [ ] **Step 1: Criar `resources/js/charts/analise.js`**

```javascript
const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// ECharts core partilhado entre instâncias (import tree-shaken, uma só vez).
let EChartsCore = null;

async function carregarECharts() {
    if (EChartsCore) return EChartsCore;

    const [core, charts, comps, renderers] = await Promise.all([
        import('echarts/core'),
        import('echarts/charts'),
        import('echarts/components'),
        import('echarts/renderers'),
    ]);

    core.use([
        charts.LineChart,
        comps.GridComponent,
        comps.TooltipComponent,
        comps.LegendComponent,
        comps.DataZoomComponent,
        comps.MarkAreaComponent,
        comps.VisualMapComponent,
        renderers.CanvasRenderer,
    ]);

    EChartsCore = core;
    return core;
}

export function registarMmcEcharts(Alpine) {
    Alpine.data('mmcEcharts', (initialPayload = null) => ({
        chart: null,
        resizeObserver: null,
        resizeTimer: null,
        _destroyed: false,
        _rafId: null,
        _offChartUpdate: null,
        _payload: initialPayload,
        _hasData: !!(initialPayload && Array.isArray(initialPayload.series)),
        _renderRetries: 0,

        get _hasSeries() {
            const s = this._payload?.series;
            return Array.isArray(s) && s.some((serie) => (serie.data || []).length > 0);
        },

        async init() {
            await carregarECharts();

            if (this._hasData) {
                this.$nextTick(() => { if (!this._destroyed) this.render(); });
            }

            if (typeof Livewire !== 'undefined') {
                this._offChartUpdate = Livewire.on('mmc-chart-update', (eventData) => {
                    if (this._destroyed) return;
                    const payload = eventData?.payload ?? eventData;
                    if (!payload) return;

                    this._payload = payload;
                    this._hasData = Array.isArray(payload.series);

                    if (this._hasData) {
                        this.$nextTick(() => { if (!this._destroyed) this.render(); });
                    } else if (this.chart) {
                        this.chart.dispose();
                        this.chart = null;
                    }
                });
            }

            let themeEffectFirst = true;
            Alpine.effect(() => {
                Alpine.store('theme');
                if (themeEffectFirst) { themeEffectFirst = false; return; }
                if (this._hasData) {
                    this.$nextTick(() => { if (!this._destroyed) this.render(); });
                }
            });

            this.resizeObserver = new ResizeObserver(() => {
                clearTimeout(this.resizeTimer);
                this.resizeTimer = setTimeout(() => { if (!this._destroyed) this.chart?.resize(); }, 100);
            });
            this.resizeObserver.observe(this.$el);
        },

        destroy() {
            this._destroyed = true;
            if (this._rafId) { cancelAnimationFrame(this._rafId); this._rafId = null; }
            if (this._offChartUpdate) { this._offChartUpdate(); this._offChartUpdate = null; }
            this.resizeObserver?.disconnect();
            if (this.chart) { this.chart.dispose(); this.chart = null; }
        },

        resetZoom() {
            this.chart?.dispatchAction({ type: 'dataZoom', start: 0, end: 100 });
        },

        escuro() {
            return document.documentElement.classList.contains('dark');
        },

        cores() {
            const e = this.escuro();
            return {
                texto:  e ? 'rgba(255,255,255,0.65)' : 'rgba(0,0,0,0.6)',
                grelha: e ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)',
                banda:  e ? 'rgba(118,184,42,0.12)'  : 'rgba(118,184,42,0.14)',
                fundoTooltip: e ? '#1d2a1e' : '#ffffff',
                textoTooltip: e ? '#ffffff' : '#1d2a1e',
            };
        },

        // Cada ponto é [xIso, valorTracado, valorReal]. Em dual/multi-piscina
        // tracado === real; em multi-metrica tracado é normalizado 0-100.
        pontos(serie, normalizar) {
            return (serie.data || []).map((d) => {
                const real = d.y;
                let tracado = real;
                if (normalizar) {
                    const span = (serie.yMax - serie.yMin) || 1;
                    tracado = ((real - serie.yMin) / span) * 100;
                }
                return { value: [d.x, tracado, real] };
            });
        },

        render() {
            if (this._destroyed) return;
            const el = this.$refs.container;
            if (!el) return;

            if (el.offsetWidth === 0 || el.offsetHeight === 0) {
                if (this._renderRetries < 15) {
                    this._renderRetries++;
                    this._rafId = requestAnimationFrame(() => { if (!this._destroyed) this.render(); });
                }
                return;
            }
            this._renderRetries = 0;

            const p = this._payload;
            if (!p || !Array.isArray(p.series)) return;

            if (!this.chart) {
                this.chart = EChartsCore.init(el, null, { renderer: 'canvas' });
            }

            const c = this.cores();
            const modo = p.mode;
            const normalizar = modo === 'multi-metrica';

            const seriesEcharts = [];
            const visualMaps = [];
            const yAxis = [];

            if (modo === 'dual') {
                yAxis.push(this.yAxisReal(p.series[0], 'left', c));
                yAxis.push(this.yAxisReal(p.series[1], 'right', c));
            } else if (normalizar) {
                yAxis.push({
                    type: 'value', min: 0, max: 100, scale: false,
                    name: '% do intervalo', nameTextStyle: { color: c.texto, fontSize: 11 },
                    axisLabel: { color: c.texto, formatter: '{value}%' },
                    splitLine: { lineStyle: { color: c.grelha } },
                });
            } else {
                yAxis.push({
                    type: 'value', scale: true,
                    name: p.metrica?.unidade || '',
                    nameTextStyle: { color: c.texto, fontSize: 11 },
                    axisLabel: { color: c.texto },
                    splitLine: { lineStyle: { color: c.grelha } },
                });
            }

            p.series.forEach((serie, i) => {
                const yAxisIndex = modo === 'dual' ? (serie.axis === 'right' ? 1 : 0) : 0;
                const banda = modo === 'multi-piscina' ? p.metrica?.banda : serie.banda;

                const s = {
                    name: serie.label,
                    type: 'line',
                    yAxisIndex,
                    smooth: 0.3,
                    showSymbol: (serie.data || []).length <= 60,
                    symbolSize: 6,
                    lineStyle: { width: 2.5, color: serie.cor },
                    itemStyle: { color: serie.cor },
                    connectNulls: false,
                    data: this.pontos(serie, normalizar),
                    encode: { x: 0, y: 1 },
                };

                // markArea da banda legal — só nos modos com eixo real.
                if (banda && modo !== 'multi-metrica') {
                    s.markArea = {
                        silent: true,
                        itemStyle: { color: c.banda },
                        data: [[{ yAxis: banda.min }, { yAxis: banda.max }]],
                    };
                }

                // visualMap pinta a vermelho o troço fora da banda (dim 2 = valor real).
                if (banda) {
                    visualMaps.push({
                        show: false,
                        type: 'piecewise',
                        seriesIndex: i,
                        dimension: 2,
                        pieces: [
                            { lt: banda.min, color: '#dc2626' },
                            { gte: banda.min, lte: banda.max, color: serie.cor },
                            { gt: banda.max, color: '#dc2626' },
                        ],
                        outOfRange: { color: serie.cor },
                    });
                }

                seriesEcharts.push(s);
            });

            const isShort = p.period === '6h' || p.period === '24h';

            const option = {
                animation: !reduzMovimento,
                grid: { left: 48, right: modo === 'dual' ? 56 : 20, top: 24, bottom: 74 },
                legend: {
                    show: modo !== 'dual' || p.series.length > 1,
                    bottom: 44,
                    textStyle: { color: c.texto },
                    icon: 'roundRect',
                },
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'cross', label: { show: false } },
                    confine: true,
                    backgroundColor: c.fundoTooltip,
                    borderColor: c.grelha,
                    textStyle: { color: c.textoTooltip },
                    formatter: (params) => {
                        if (!params.length) return '';
                        const dt = new Date(params[0].value[0]);
                        const dd = String(dt.getDate()).padStart(2, '0');
                        const mm = String(dt.getMonth() + 1).padStart(2, '0');
                        const hh = String(dt.getHours()).padStart(2, '0');
                        const mi = String(dt.getMinutes()).padStart(2, '0');
                        let head = isShort ? `${dd}/${mm} ${hh}:${mi}` : `${dd}/${mm}/${dt.getFullYear()}`;
                        let linhas = params.map((it) => {
                            const serie = p.series[it.seriesIndex];
                            const real = it.value[2];
                            const casas = serie?.casas ?? 2;
                            const u = modo === 'multi-piscina'
                                ? (p.metrica?.unidade ? ' ' + p.metrica.unidade : '')
                                : (serie?.unidade ? ' ' + serie.unidade : '');
                            const v = Number(real).toLocaleString('pt-PT', {
                                minimumFractionDigits: casas, maximumFractionDigits: casas,
                            });
                            return `${it.marker}${it.seriesName}: <b>${v}${u}</b>`;
                        });
                        return `<div style="font-size:11px;opacity:.7;margin-bottom:4px">${head}</div>${linhas.join('<br>')}`;
                    },
                },
                dataZoom: [
                    { type: 'inside', throttle: 50 },
                    {
                        type: 'slider', height: 34, bottom: 4,
                        handleSize: 44, moveHandleSize: 8,
                        borderColor: c.grelha,
                    },
                ],
                xAxis: {
                    type: 'time',
                    axisLine: { lineStyle: { color: c.grelha } },
                    axisLabel: {
                        color: c.texto,
                        formatter: {
                            year: '{yyyy}', month: '{dd}/{MM}', day: '{dd}/{MM}',
                            hour: '{HH}:{mm}', minute: '{HH}:{mm}',
                        },
                    },
                    splitLine: { show: false },
                },
                yAxis,
                series: seriesEcharts,
                visualMap: visualMaps,
            };

            this.chart.setOption(option, { notMerge: true });
            this.chart.resize();
        },

        yAxisReal(serie, lado, c) {
            return {
                type: 'value',
                position: lado,
                scale: true,
                name: serie.unidade ? `${serie.label} (${serie.unidade})` : serie.label,
                nameTextStyle: { color: serie.cor, fontSize: 11 },
                axisLabel: { color: serie.cor },
                splitLine: lado === 'left' ? { lineStyle: { color: c.grelha } } : { show: false },
            };
        },
    }));
}
```

- [ ] **Step 2: Importar e registar o componente no `app.js`**

No topo de `resources/js/app.js`, logo após `import './bootstrap';` (linha 1), acrescenta:

```javascript
import { registarMmcEcharts } from './charts/analise.js';
```

Dentro do listener `alpine:init` existente (linha 23), como primeira instrução do callback, acrescenta:

```javascript
    registarMmcEcharts(window.Alpine);
```

- [ ] **Step 3: Build para confirmar que compila**

Run: `npm run build`
Expected: build conclui sem erros; aparece um chunk `echarts` no output.

- [ ] **Step 4: Commit**

```bash
git add resources/js/charts/analise.js resources/js/app.js
git commit -m "feat(charts): componente mmcEcharts (ECharts) isolado"
```

---

## Task 4: Blade com seletor de modo e container ECharts

**Files:**
- Modify: `resources/views/filament/widgets/painel-parametros.blade.php`

- [ ] **Step 1: Substituir a barra de tabs/período pelo seletor de modo + período**

Localiza o bloco `<div class="flex items-center justify-between mb-3 ...">` (linhas 11-43). Mantém as tabs Gráfico/Tabela e os botões de período tal como estão, mas adiciona ACIMA deles (logo a seguir a `</div>` do `{{ $this->form }}`, linha 8) um seletor de modo:

```blade
        {{-- Seletor de modo --}}
        <div class="flex gap-1 mb-3">
            @foreach(['dual' => '2 Eixos', 'multi-metrica' => 'Multi-métrica', 'multi-piscina' => 'Multi-piscina'] as $key => $label)
                <button
                    wire:click="setMode('{{ $key }}')"
                    @class([
                        'px-3 py-1.5 text-xs font-medium rounded-md transition-colors',
                        'bg-primary-600 text-white shadow-sm' => $this->mode === $key,
                        'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 border border-gray-200 dark:border-gray-700' => $this->mode !== $key,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>
```

- [ ] **Step 2: Substituir o bloco Alpine do gráfico**

Substitui o bloco `@if ($this->tabAtiva === 'graph') ... @endif` que hoje usa `x-data="mmcChart(...)"` (linhas 45-68) por:

```blade
        @if ($this->tabAtiva === 'graph')
            @php($payload = $this->getChartPayload())

            <div
                x-data="mmcEcharts({{ Illuminate\Support\Js::from($payload ?: null) }})"
                wire:ignore
            >
                <div x-show="!_hasData" class="mmc-grafico-vazio" x-cloak>Seleciona uma piscina.</div>
                <div x-show="_hasData && !_hasSeries" class="mmc-grafico-vazio" x-cloak>Sem registos neste período.</div>
                <div x-show="_hasSeries" x-cloak>
                    <div class="mmc-grafico-canvas-wrap">
                        <div x-ref="container" style="width: 100%; height: 100%;"></div>
                    </div>
                    <div class="flex items-center justify-between mt-2">
                        <span class="text-xs text-gray-400 dark:text-gray-500 hidden sm:block">
                            Arrasta a barra inferior para navegar &middot; scroll para zoom
                        </span>
                        <button
                            x-on:click="resetZoom()"
                            class="text-xs text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 underline ml-auto"
                        >Repor zoom</button>
                    </div>
                </div>
            </div>
        @else
```

Deixa o bloco `@else` da tabela (linhas 69-139) intacto.

- [ ] **Step 3: Ajustar a altura do container para acomodar o slider**

No `<style>`, substitui a regra `.mmc-grafico-canvas-wrap` (linhas 143-146) por:

```css
        .mmc-grafico-canvas-wrap {
            position: relative;
            height: 420px;
        }
```

E o media query (linhas 186-188) por:

```css
        @media (max-width: 640px) {
            .mmc-grafico-canvas-wrap { height: 320px; }
        }
```

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 5: Commit**

```bash
git add resources/views/filament/widgets/painel-parametros.blade.php
git commit -m "feat(charts): blade da analise com seletor de modo e container ECharts"
```

---

## Task 5: Verificação manual no preview

**Files:** nenhum (verificação).

- [ ] **Step 1: Arrancar o preview e autenticar**

Usa o preview `piscinas` (`.claude/launch.json`). Login `daniel@mmcrespo.pt` / `password` (dev). Navega para a página de Análise de Parâmetros.

- [ ] **Step 2: Modo 2 Eixos (dual)**

Confirma: gráfico renderiza, 2 eixos com autoscale, banda legal sombreada, crosshair + tooltip com valores reais e unidades ao passar o rato, slider inferior arrasta e faz zoom, botão "Repor zoom" funciona. Consola sem erros (`preview_console_logs level=error`).

- [ ] **Step 3: Não-conformidade pintada**

Com dados que tenham pelo menos um registo fora da banda (ou cria um via `/daily-records/create`), confirma que o troço fora dos limites fica vermelho.

- [ ] **Step 4: Modo Multi-métrica**

Seleciona 3-4 métricas. Confirma: eixo "% do intervalo" 0-100, cada série no seu intervalo, tooltip mostra valores REAIS (não %), legenda liga/desliga séries.

- [ ] **Step 5: Modo Multi-piscina**

Seleciona uma métrica (ex: Cloro Livre). Confirma: uma linha por piscina com cores distintas, banda legal partilhada, legenda clicável.

- [ ] **Step 6: Mobile + dark mode**

`preview_resize` para 375px: confirma altura 320px, slider tátil utilizável, tooltip confinado. Alterna dark mode: cores de texto/grelha/tooltip corretas.

- [ ] **Step 7: Screenshot de prova**

`preview_screenshot` de cada modo para partilhar com o Daniel.

- [ ] **Step 8: Correr a suite completa uma última vez**

Run: `php artisan test`
Expected: verde (sem regressões).

---

## Task 6 (DIFERIDA — só após validação em produção pelo Daniel)

**Não executar nesta sessão.** Depois de o Daniel confirmar o gráfico em produção:

**Files:**
- Modify: `resources/js/app.js` (remover componente `mmcChart` e imports chart.js)
- Modify: `package.json` (remover `chart.js`, `chartjs-adapter-luxon`, `chartjs-plugin-annotation`, `chartjs-plugin-zoom`, `luxon`; manter `hammerjs` só se usado por outro componente — verificar com grep antes)

- [ ] **Step 1: Confirmar que nada além do gráfico usa chart.js/luxon**

Run: `grep -rn "chart.js\|chartjs\|luxon\|mmcChart\|new Chart" resources/js/`
Expected: só ocorrências dentro do componente `mmcChart` a remover.

- [ ] **Step 2: Remover o componente `mmcChart` e os imports**

Remove o bloco `window.Alpine.data('mmcChart', ...)` e a `let ChartWithPlugins` / imports associados.

- [ ] **Step 3: Remover dependências e rebuild**

```bash
npm uninstall chart.js chartjs-adapter-luxon chartjs-plugin-annotation chartjs-plugin-zoom luxon
npm run build
```
Expected: build ok, bundle sem o chunk chart.

- [ ] **Step 4: Commit**

```bash
git add resources/js/app.js package.json package-lock.json
git commit -m "chore(charts): remover chart.js apos migracao para ECharts"
```

---

## Self-review (feito)

- **Cobertura da spec:** dual (mantido) ✓ Task 2/3/4; multi-métrica ✓; multi-piscina + scope NS ✓ Task 2; crosshair/tooltip ✓ Task 3 Step 1; banda markArea ✓; visualMap não-conformidade ✓; dataZoom slider+inside com alvo tátil ✓; autoscale ✓ (`scale: true`, sem min/max); dark mode ✓; correções excluídas + coalesce ns_* ✓ Task 2 teste; remoção diferida do chart.js ✓ Task 6.
- **Consistência de tipos:** payload `{ mode, period, series:[{key,label,unidade,casas,cor,banda,yMin,yMax,axis?,data:[{x,y}]}], metrica? }` — usado igual em PHP (Task 2), JS (`pontos`, `render`, Task 3) e getter `_hasSeries`. `mmcEcharts` (não `mmcChart`) consistente entre `analise.js`, `app.js` e blade. `x-ref="container"` consistente entre blade e `render()`.
- **Placeholders:** nenhum — todo o código está completo.
