<?php declare(strict_types=1);

namespace App\Filament\Pages;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static ?string $title = 'Painel de Controlo';

    /** O cabecalho fica a cargo do PainelPiscinasWidget (estilo proprio, no topo). */
    public function getHeading(): string
    {
        return '';
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\PainelPiscinasWidget::class,
            \App\Filament\Widgets\CloroPhChartWidget::class,
            \App\Filament\Widgets\QuadroOperacionalWidget::class,
            \App\Filament\Widgets\StockBaixoWidget::class,
        ];
    }
}
