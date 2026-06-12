<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Página dedicada à análise de parâmetros da água em tamanho grande.
 * Reutiliza o CloroPhChartWidget (mesmo motor de gráficos do dashboard), mas
 * aqui com o ecrã todo para analisar a evolução com mais detalhe.
 */
class AnaliseParametros extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Análise de Parâmetros';

    protected static ?string $title = 'Análise de Parâmetros';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.analise-parametros';

    // Todos os utilizadores autenticados (incluindo gestor) podem consultar.
    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }
}
