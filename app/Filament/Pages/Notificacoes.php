<?php declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Models\TestPush;
use App\Notifications\TestPushNotification;
use Filament\Notifications\Notification;
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

    public function podeTestar(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    /** @return list<string> */
    public function tiposDeTeste(): array
    {
        return TestPushNotification::tiposValidos();
    }

    public function testar(string $tipo): void
    {
        if (! $this->podeTestar() || ! in_array($tipo, TestPushNotification::tiposValidos(), true)) {
            return;
        }

        TestPush::create([
            'user_id' => auth()->id(),
            'tipo' => $tipo,
            'fire_at' => now()->addSeconds(5),
        ]);

        Notification::make()
            ->title('Push de teste agendado')
            ->body('Chega daqui a ~5 segundos — já podes bloquear o ecrã.')
            ->success()
            ->send();
    }
}
