<?php declare(strict_types=1);
namespace App\Filament\Widgets;


use App\Models\DailyRecord;
use App\Models\Pool;
use App\Services\CacheService;
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

    // Não aparece no dashboard global — apenas na página dedicada de gráficos.
    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.widgets.painel-parametros';

    /** Piscinas selecionadas (IDs como string, default: todas em mount). */
    public array $piscinasSelecionadas = [];

    /** Parâmetros a mostrar. Default: pH + cloro livre. */
    public array $metricasSelecionadas = ['cloro_livre', 'ph'];

    /**
     * Definição de cada parâmetro: label, unidade, casas decimais, gama do eixo,
     * banda de conformidade CN 14/DA (faixa verde) e cor própria (usada quando há
     * só uma piscina, para distinguir parâmetros pela cor).
     *
     * Os campos `min`/`max` são usados no modo mono-metrica (eixo Y real).
     * No modo multi-metrica, a normalização 0-100% usa os limites de conformidade
     * (banda), não estes limites de escala.
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
            'label' => 'Turbidez', 'unidade' => 'FNU', 'casas' => 2,
            'min' => 0, 'max' => 1, 'cor' => '#8b5cf6',
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
     * da banda (CN 14/DA). Parâmetros sem banda usam os limites próprios de escala
     * (campo `min`/`max` da definição do parâmetro).
     *
     * Fórmula: pct = (valor - normMin) / (normMax - normMin) * 100
     * Valores fora de gama resultam em pct < 0 ou pct > 100 (visíveis no gráfico
     * acima/abaixo da banda verde, graças ao suggestedMin:-20 / suggestedMax:120).
     */
    private function normalizarValor(float $valor, string $metrica, array $def, Pool $piscina): float
    {
        // Para temperatura: usa os limites da própria piscina se existirem.
        // Senão usa escala fixa 20-35°C — razoável para piscinas cobertas municipais
        // portuguesas; valor documentado aqui e no app.js.
        if ($metrica === 'temperatura') {
            $normMin = $piscina->temp_min !== null ? (float) $piscina->temp_min : 20.0;
            $normMax = $piscina->temp_max !== null ? (float) $piscina->temp_max : 35.0;
        } elseif ($def['banda'] !== null) {
            // Parâmetro com banda de conformidade: normaliza contra os limites legais.
            $normMin = (float) $def['banda']['min'];
            $normMax = (float) $def['banda']['max'];
        } else {
            // Parâmetro sem banda (ex: cloro_total, transparencia, cloro_combinado):
            // usa min de escala (0 se null) e max de escala.
            $normMin = $def['min'] !== null ? (float) $def['min'] : 0.0;
            $normMax = (float) $def['max'];
        }

        $intervalo = $normMax - $normMin;

        // Evita divisão por zero em definições mal configuradas.
        if ($intervalo === 0.0) {
            return 50.0;
        }

        return round(($valor - $normMin) / $intervalo * 100, 2);
    }

    /**
     * Blocos de gráficos a desenhar. Regra:
     *  - 1 piscina selecionada  -> UM gráfico único com todos os parâmetros como
     *    linhas de cores distintas, eixo Y único normalizado 0-100% do intervalo
     *    legal (CN 14/DA). Tooltip mostra valores reais com unidade.
     *  - 2+ piscinas            -> um gráfico por parâmetro, cada série uma piscina
     *    (eixo Y com valores reais, sem normalização).
     *
     * Cache: 30 min TTL por piscina + métricas (hash).
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

        // Validate metric names to prevent SQL injection
        $metricas = array_filter($metricas, fn ($m) => preg_match('/^[a-z_]+$/', $m));
        if (empty($metricas)) {
            return [];
        }

        // Cache: para 1 piscina, tenta recuperar do cache antes de calcular.
        $cacheService = app(CacheService::class);
        if (count($piscinaIds) === 1) {
            $poolId = $piscinaIds[0];
            $metricsHash = md5(json_encode($metricas) ?: '');
            $cached = $cacheService->getGraphData($poolId, $metricsHash);
            if ($cached !== null) {
                return $cached;
            }
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

        // Devolve o array de valores reais (por dia) de uma piscina+métrica.
        $serieReal = function (int $poolId, string $metrica, array $def) use ($registos, $dias): array {
            $porDia = ($registos->get($poolId) ?? collect())->keyBy('dia');

            return $dias->map(function ($d) use ($porDia, $metrica, $def) {
                $r = $porDia->get($d->format('Y-m-d'));

                return $r && $r->{$metrica} !== null
                    ? round((float) $r->{$metrica}, $def['casas'])
                    : null;
            })->values()->toArray();
        };

        // --- MODO 1 PISCINA: eixo único normalizado 0-100% do intervalo legal ---
        if ($piscinas->count() === 1) {
            $p = $piscinas->first();
            $series = [];

            foreach ($metricas as $metrica) {
                $def = self::METRICAS[$metrica];
                $valoresReais = $serieReal($p->id, $metrica, $def);

                // Normaliza cada ponto; null mantém-se null (spanGaps=false).
                $valoresNorm = array_map(
                    fn ($v) => $v !== null ? $this->normalizarValor($v, $metrica, $def, $p) : null,
                    $valoresReais
                );

                // Constrói a string de valor real para o tooltip (ex: "7,42" ou "1,20 mg/L").
                $valoresReaisTooltip = array_map(function ($v) use ($def) {
                    if ($v === null) {
                        return null;
                    }
                    $formatado = number_format($v, $def['casas'], ',', '');

                    return $def['unidade'] ? "{$formatado} {$def['unidade']}" : $formatado;
                }, $valoresReais);

                $series[] = [
                    'label'     => $def['label'],
                    'cor'       => $def['cor'],
                    // data: valores normalizados 0-100 — usados para desenhar a linha.
                    'data'      => $valoresNorm,
                    // dataReal: valores reais com unidade — usados exclusivamente pelo tooltip.
                    'dataReal'  => $valoresReaisTooltip,
                    // unidade: enviada para o tooltip poder reconstituir a legenda.
                    'unidade'   => $def['unidade'],
                ];
            }

            $grafico = [[
                'modo'   => 'multi-metrica',
                'titulo' => $p->instalacao?->name ? "{$p->instalacao->name} — {$p->name}" : $p->name,
                'labels' => $labels,
                'series' => $series,
                // Eixo Y único normalizado: a banda "conforme" é sempre 0-100%.
                // suggestedMin:-20 / suggestedMax:120 para valores fora de gama visíveis.
                'bandaNormalizada' => ['min' => 0, 'max' => 100],
            ]];

            // Cache: guarda para 30 min.
            $metricsHash = md5(json_encode($metricas) ?: '');
            $cacheService->cacheGraphData($p->id, $grafico, 30);

            return $grafico;
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
                    'data'  => $serieReal($p->id, $metrica, $def),
                ];
            }

            $graficos[] = [
                'modo'   => 'mono-metrica',
                'titulo' => $def['label'],
                'unidade' => $def['unidade'],
                'min'    => $def['min'],
                'max'    => $def['max'],
                'banda'  => $def['banda'],
                'labels' => $labels,
                'series' => $series,
            ];
        }

        return $graficos;
    }
}
