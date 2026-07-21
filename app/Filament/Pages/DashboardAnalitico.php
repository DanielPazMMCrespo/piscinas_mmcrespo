<?php declare(strict_types=1);
namespace App\Filament\Pages;

use App\Constants\UserRole;
use Filament\Pages\Page;

/**
 * Dashboard Analítico — visão de gestão (não operacional): heatmap semanal
 * de conformidade, tempo médio de resposta a incidentes (MTTR) e consumo de
 * químicos por piscina/mês. Complementa o CloroPhChartWidget (tendências
 * detalhadas por piscina, em Análise de Parâmetros).
 */
class DashboardAnalitico extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Dados';

    protected static ?string $navigationLabel = 'Dashboard Analítico';

    protected static ?string $title = 'Dashboard Analítico';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.dashboard-analitico';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    public function mount(): void
    {
        activity('analise')
            ->causedBy(auth()->user())
            ->log('Acedeu ao Dashboard Analítico.');
    }
}
