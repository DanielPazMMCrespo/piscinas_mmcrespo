<?php declare(strict_types=1);
namespace App\Filament\Pages;


use App\Constants\NSPermission;
use Filament\Pages\Page;

/**
 * Página dedicada à análise de parâmetros da água em tamanho grande.
 * Reutiliza o CloroPhChartWidget (mesmo motor de gráficos do dashboard), mas
 * aqui com o ecrã todo para analisar a evolução com mais detalhe.
 */
class AnaliseParametros extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Dados';

    protected static ?string $navigationLabel = 'Análise de Parâmetros';

    protected static ?string $title = 'Análise de Parâmetros';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.analise-parametros';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->podeVer(NSPermission::ANALISE_PARAMETROS);
    }
}
