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

    public string $tipoSelecionado = 'incidente';

    public string $tituloTeste = '';

    public string $corpoTeste = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }

    public function mount(): void
    {
        $this->preencherDefaults();
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

    public function updatedTipoSelecionado(): void
    {
        $this->preencherDefaults();
    }

    private function preencherDefaults(): void
    {
        $defaults = TestPushNotification::defaults($this->tipoSelecionado);
        $this->tituloTeste = $defaults['title'];
        $this->corpoTeste = $defaults['body'];
    }

    public function testar(): void
    {
        if (! $this->podeTestar() || ! in_array($this->tipoSelecionado, TestPushNotification::tiposValidos(), true)) {
            return;
        }

        TestPush::create([
            'user_id' => auth()->id(),
            'tipo' => $this->tipoSelecionado,
            'titulo' => trim($this->tituloTeste) ?: null,
            'corpo' => trim($this->corpoTeste) ?: null,
            'fire_at' => now()->addSeconds(5),
        ]);

        Notification::make()
            ->title('Push de teste agendado')
            ->body('Chega daqui a ~5 segundos — já podes bloquear o ecrã.')
            ->success()
            ->send();
    }
}
