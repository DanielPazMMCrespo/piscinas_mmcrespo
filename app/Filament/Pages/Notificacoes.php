<?php declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Ativação de notificações push neste dispositivo. No iPhone o push exige que a
 * app esteja instalada no ecrã inicial (PWA) — a página deteta isso e mostra
 * instruções em vez do botão.
 */
class Notificacoes extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Notificações';

    protected static ?string $title = 'Notificações';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.notificacoes';

    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }
}
