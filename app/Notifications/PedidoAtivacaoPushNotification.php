<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;
use NotificationChannels\WebPush\WebPushChannel;

class PedidoAtivacaoPushNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Ativar notificações',
            'body' => 'O administrador pediu que ative as notificações push. Isto é essencial para receber avisos importantes sobre incidentes e operações.',
            'format' => 'filament',
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage())
            ->title('Ativar notificações')
            ->body('O administrador pediu que ative as notificações push. Isto é essencial para comunicação e resolução de incidentes.')
            ->action('Ativar', '/admin/notificacoes')
            ->icon('/images/icon-192x192.png');
    }
}
