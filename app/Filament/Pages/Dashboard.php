<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\CloroPhChartWidget;
use App\Filament\Widgets\PainelPiscinasWidget;
use App\Filament\Widgets\QuadroOperacionalWidget;
use App\Filament\Widgets\StockBaixoWidget;
use App\Filament\Widgets\EstabilidadeMedicoesWidget;

/**
 * [AI_CONTEXT]
 *
 * IDEALIZADO:
 * Painel principal da aplicação. Desenhado com a filosofia "Exception-First": o técnico só
 * deve ver o que precisa de atenção, sem métricas vaidosas (vanity metrics).
 *
 * IMPLEMENTADO:
 * - Apenas contém widgets essenciais: PainelPiscinasWidget, CloroPhChartWidget,
 *   QuadroOperacionalWidget (Kanban) e StockBaixoWidget.
 * - QuadroOperacional centraliza as exceções (alertas, incidentes) para resolução imediata.
 * - Componentes legados foram removidos em prol de utilitarismo operacional puro.
 *
 * EM FALTA (ROADMAP):
 * - N/A
 */
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
            PainelPiscinasWidget::class,
            CloroPhChartWidget::class,
            QuadroOperacionalWidget::class,
            StockBaixoWidget::class,
            EstabilidadeMedicoesWidget::class,
        ];
    }
}
