<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\PainelPiscinasWidget;
use App\Filament\Widgets\QuadroOperacionalWidget;
use App\Filament\Widgets\StockBaixoWidget;

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

    /**
     * A ordem deste array é a ordem no ecrã (getVisibleWidgets não ordena pelo
     * $sort dos widgets). Operacional primeiro: com os gráficos antes do quadro
     * de alertas, "o que tenho de fazer a seguir" ficava a 5,4 ecrãs de scroll
     * no telemóvel. O gráfico e a estabilidade também existem, com controlos,
     * na página Análise de Parâmetros.
     */
    public function getWidgets(): array
    {
        return [
            PainelPiscinasWidget::class,
            QuadroOperacionalWidget::class,
            StockBaixoWidget::class,
        ];
    }
}
