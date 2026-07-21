<?php declare(strict_types=1);
namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Score de Conformidade: percentagem de registos sem violações legais nos
 * últimos 7 dias. KPI de gestão para auditorias CN 14/DA — mede a
 * qualidade da água.
 */
class ScoreConformidadeWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    protected function getStats(): array
    {
        $inicio = now()->subDays(7);

        $registos = DailyRecord::query()
            ->where('e_correcao', false)
            ->where('registado_em', '>=', $inicio)
            ->with('piscina')
            ->get()
            ->filter(fn (DailyRecord $r) => $r->piscina !== null);

        if ($registos->isEmpty()) {
            return [
                Stat::make('Conformidade geral (7 dias)', '—')
                    ->description('Sem registos no período')
                    ->color('gray'),
            ];
        }

        $conformes = $registos->filter(fn (DailyRecord $r) => empty($r->listarViolacoes()));
        $scoreGeral = round(($conformes->count() / $registos->count()) * 100, 1);

        return [
            Stat::make('Conformidade geral (7 dias)', number_format($scoreGeral, 1, ',', '') . '%')
                ->description($registos->count() . ' registo(s) avaliado(s)')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($scoreGeral >= 95 ? 'success' : ($scoreGeral >= 85 ? 'warning' : 'danger')),
        ];
    }
}
