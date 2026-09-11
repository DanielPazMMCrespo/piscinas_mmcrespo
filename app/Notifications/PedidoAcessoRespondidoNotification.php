<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Constants\PoolAccessRequestStatus;
use App\Models\PoolAccessRequest;
use Filament\Notifications\Actions\Action;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Avisa o nadador-salvador da decisão do administrador sobre o pedido de
 * acesso. Dispara sempre, sem gate de preferências: quem está bloqueado
 * precisa de saber que já pode entrar (ou porque não).
 */
class PedidoAcessoRespondidoNotification extends Notification
{
    public function __construct(private readonly PoolAccessRequest $pedido) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        $url = $this->pedido->status === PoolAccessRequestStatus::APROVADO ? '/admin' : '/piscinas-encerradas';

        return \Filament\Notifications\Notification::make()
            ->title($this->titulo())
            ->body($this->corpo())
            ->icon($this->pedido->status === PoolAccessRequestStatus::APROVADO ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
            ->color($this->pedido->status === PoolAccessRequestStatus::APROVADO ? 'success' : 'danger')
            ->actions([
                Action::make('abrir')
                    ->label('Abrir')
                    ->button()
                    ->url($url),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->titulo())
            ->body($this->corpo())
            ->icon('/images/icon-192.png')
            ->action('Ver', $this->pedido->status === PoolAccessRequestStatus::APROVADO ? '/admin' : '/piscinas-encerradas');
    }

    private function titulo(): string
    {
        return $this->pedido->status === PoolAccessRequestStatus::APROVADO
            ? 'Acesso concedido'
            : 'Pedido de acesso negado';
    }

    private function corpo(): string
    {
        $base = $this->pedido->status === PoolAccessRequestStatus::APROVADO
            ? 'O administrador concedeu-lhe acesso à app.'
            : 'O administrador não concedeu acesso à app.';

        return $this->pedido->resposta_admin
            ? "{$base} Nota: {$this->pedido->resposta_admin}"
            : $base;
    }
}
