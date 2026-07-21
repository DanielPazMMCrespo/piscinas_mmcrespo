<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class RelatorioMensalDisponivelNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $instalacaoNome,
        public string $mesLabel,
        public ?string $url,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (method_exists($notifiable, 'wantsNotification') && $notifiable->wantsNotification('relatorio_mensal', 'push')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title("Relatório mensal disponível — {$this->instalacaoNome} ({$this->mesLabel})")
            ->body('O livro sanitário do mês anterior foi gerado automaticamente.')
            ->icon('heroicon-o-document-chart-bar');

        if ($this->url !== null) {
            $notification->actions([
                Action::make('ver')
                    ->label('Abrir PDF')
                    ->button()
                    ->url($this->url, shouldOpenInNewTab: true),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, object $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("Relatório mensal — {$this->instalacaoNome}")
            ->body("Livro sanitário de {$this->mesLabel} disponível.")
            ->icon('/images/icon-192.png')
            ->options(['tag' => "relatorio-mensal-{$this->instalacaoNome}-{$this->mesLabel}"]);
    }
}
