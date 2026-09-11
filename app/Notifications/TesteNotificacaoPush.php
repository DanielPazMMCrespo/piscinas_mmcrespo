<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Notifications\Actions\Action;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Notificação de teste disparada manualmente pelo próprio utilizador em
 * Definições > Minhas Notificações, para confirmar que o dispositivo está
 * mesmo a receber push. Ignora as preferências (`wantsNotification`) de
 * propósito — é um teste, não deve depender de estar ligado/desligado.
 */
class TesteNotificacaoPush extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return \Filament\Notifications\Notification::make()
            ->title('Notificação de teste')
            ->body('Se recebeu isto, as notificações estão a funcionar perfeitamente neste dispositivo.')
            ->icon('heroicon-o-bell-alert')
            ->color('success')
            ->actions([
                Action::make('ok')
                    ->label('Confirmar')
                    ->button()
                    ->url('/admin'),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Notificação de teste')
            ->body('Se recebeu isto, as notificações estão a funcionar neste dispositivo.')
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag('teste-push-'.time())
            ->vibrate([200, 100, 200])
            ->data(['url' => '/admin']);
    }
}
