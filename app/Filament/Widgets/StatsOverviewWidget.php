<?php

namespace App\Filament\Widgets;

use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\Pool;
use App\Models\StockInstallation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $registosHoje = DailyRecord::whereDate('registado_em', today())->count();
        $incidentesSemana = Incident::where('ocorreu_em', '>=', now()->subDays(7))->count();
        $stockBaixo = StockInstallation::whereColumn('quantity', '<=', 'limite_minimo')->count();
        $piscinasAtivas = Pool::where('active', true)->count();

        return [
            Stat::make('Registos Hoje', $registosHoje)
                ->description('Registos diários submetidos hoje')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('primary'),

            Stat::make('Incidentes (7 dias)', $incidentesSemana)
                ->description('Incidentes registados nos últimos 7 dias')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($incidentesSemana > 0 ? 'danger' : 'success'),

            Stat::make('Stock Baixo', $stockBaixo)
                ->description('Produtos abaixo do limite mínimo')
                ->icon('heroicon-o-archive-box-x-mark')
                ->color($stockBaixo > 0 ? 'warning' : 'success'),

            Stat::make('Piscinas Ativas', $piscinasAtivas)
                ->description('Piscinas em operação')
                ->icon('heroicon-o-building-office-2')
                ->color('gray'),
        ];
    }
}
