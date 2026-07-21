<?php declare(strict_types=1);
namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Models\Incident;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tempo médio de resposta (MTTR): tempo entre a ocorrência de um incidente
 * e a sua resolução. KPI de auditoria CN 14/DA — mede a rapidez da ação
 * corretiva, não apenas a conformidade dos valores.
 */
class TempoRespostaWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    protected function getStats(): array
    {
        $resolvidos30 = Incident::query()
            ->where('status', 'resolvido')
            ->whereNotNull('resolvido_em')
            ->where('ocorreu_em', '>=', now()->subDays(30))
            ->get(['ocorreu_em', 'resolvido_em']);

        $mediaMinutos30 = $resolvidos30->isNotEmpty()
            ? $resolvidos30->avg(fn (Incident $i) => $i->ocorreu_em->diffInMinutes($i->resolvido_em))
            : null;

        $resolvidosTotal = Incident::query()
            ->where('status', 'resolvido')
            ->whereNotNull('resolvido_em')
            ->get(['ocorreu_em', 'resolvido_em']);

        $mediaMinutosTotal = $resolvidosTotal->isNotEmpty()
            ? $resolvidosTotal->avg(fn (Incident $i) => $i->ocorreu_em->diffInMinutes($i->resolvido_em))
            : null;

        $abertos = Incident::query()->where('status', 'aberto')->count();

        return [
            Stat::make('Tempo médio de resposta (30 dias)', $this->formatarDuracao($mediaMinutos30))
                ->description($resolvidos30->count() . ' incidente(s) resolvido(s)')
                ->descriptionIcon('heroicon-m-clock')
                ->color($mediaMinutos30 !== null && $mediaMinutos30 > 1440 ? 'danger' : 'success'),

            Stat::make('Tempo médio de resposta (histórico)', $this->formatarDuracao($mediaMinutosTotal))
                ->description($resolvidosTotal->count() . ' incidente(s) resolvido(s) no total')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('gray'),

            Stat::make('Incidentes em aberto', (string) $abertos)
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($abertos > 0 ? 'warning' : 'success'),
        ];
    }

    private function formatarDuracao(?float $minutos): string
    {
        if ($minutos === null) {
            return '—';
        }
        if ($minutos < 60) {
            return round($minutos) . ' min';
        }
        if ($minutos < 1440) {
            return number_format($minutos / 60, 1, ',', '') . ' h';
        }
        return number_format($minutos / 1440, 1, ',', '') . ' dias';
    }
}
