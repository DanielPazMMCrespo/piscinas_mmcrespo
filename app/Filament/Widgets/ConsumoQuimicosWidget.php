<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Models\Pool;
use App\Models\RecordAddition;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Consumo de químicos por piscina, últimos 6 meses (barras empilhadas).
 * Fonte: RecordAddition (adições reais registadas no livro sanitário),
 * não os logs de stock (esses são por instalação, não por piscina).
 */
class ConsumoQuimicosWidget extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.widgets.consumo-quimicos';

    private const MESES = 6;

    private const CORES = ['#2b9cd8', '#76b82a', '#e0a800', '#8b5cf6', '#dc2626', '#0e7490', '#d97706', '#059669'];

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    public function getChartPayload(): array
    {
        $inicio = now()->subMonths(self::MESES - 1)->startOfMonth();

        $meses = collect(range(0, self::MESES - 1))
            ->map(fn (int $i) => $inicio->copy()->addMonths($i));

        $piscinas = Pool::query()->where('active', true)->orderBy('installation_id')->orderBy('name')->get();

        $linhas = RecordAddition::query()
            ->whereHas('registoDiario', fn ($q) => $q->whereDoesntHave('correcoes')->where('registado_em', '>=', $inicio))
            ->with(['registoDiario:id,pool_id,registado_em'])
            ->get();

        $porPiscinaMes = $linhas->groupBy(fn (RecordAddition $r) => $r->registoDiario?->pool_id)
            ->map(fn ($grupo) => $grupo->groupBy(fn (RecordAddition $r) => $r->registoDiario->registado_em->format('Y-m'))
                ->map(fn ($g) => (float) $g->sum('quantity')));

        $datasets = $piscinas->values()->map(function (Pool $piscina, int $i) use ($meses, $porPiscinaMes) {
            $dadosPorMes = $porPiscinaMes->get($piscina->id, collect());

            return [
                'label' => $piscina->nomeCompleto(' — '),
                'data' => $meses->map(fn (Carbon $mes) => round($dadosPorMes->get($mes->format('Y-m'), 0.0), 2))->values()->all(),
                'backgroundColor' => self::CORES[$i % count(self::CORES)],
            ];
        })->filter(fn (array $ds) => array_sum($ds['data']) > 0)->values()->all();

        return [
            'labels' => $meses->map(fn (Carbon $m) => ucfirst($m->locale('pt')->isoFormat('MMM/YY')))->all(),
            'datasets' => $datasets,
        ];
    }
}
