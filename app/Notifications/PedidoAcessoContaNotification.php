<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\PoolAccessRequestResource;
use App\Models\PoolAccessRequest;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Avisa o administrador de que um nadador-salvador bloqueado (piscina
 * encerrada) pediu acesso à app.
 */
class PedidoAcessoContaNotification extends Notification
{
    public function __construct(private readonly PoolAccessRequest $pedido) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->wantsNotification('pedido_acesso', 'push')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Pedido de acesso à app')
            ->body("{$this->pedido->user?->name} pediu acesso enquanto a piscina está encerrada: \"{$this->pedido->motivo}\"")
            ->icon('heroicon-o-key')
            ->color('warning')
            ->actions([
                Action::make('ver')
                    ->label('Ver pedido')
                    ->button()
                    ->url(PoolAccessRequestResource::getUrl()),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Pedido de acesso à app')
            ->body("{$this->pedido->user?->name} pediu acesso enquanto a piscina está encerrada.")
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->action('ver_pedido', 'Ver')
            ->tag("pedido-acesso-{$this->pedido->id}")
            ->data(['url' => PoolAccessRequestResource::getUrl()]);
    }
}
