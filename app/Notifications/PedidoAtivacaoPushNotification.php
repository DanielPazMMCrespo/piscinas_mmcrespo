<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class PedidoAtivacaoPushNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Ativar notificações push')
            ->body('O administrador solicitou a ativação das notificações no seu dispositivo para receber alertas operacionais e de incidentes.')
            ->icon('heroicon-o-bell-alert')
            ->color('warning')
            ->actions([
                Action::make('definicoes')
                    ->label('Ver Definições')
                    ->button()
                    ->url('/admin/definicoes'),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Ativar notificações')
            ->body('O administrador solicitou a ativação das notificações push.')
            ->action('Ativar', '/admin/definicoes')
            ->icon('/images/icon-192.png');
    }
}
