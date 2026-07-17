<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Anúncio composto por um admin (App\Models\CustomBroadcast), enviado aos
 * cargos escolhidos — envio único numa data/hora exata, ou diário recorrente.
 */
class CustomBroadcastNotification extends Notification
{
    public function __construct(
        private readonly string $titulo,
        private readonly string $corpo,
        private readonly string $tag,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'title' => $this->titulo,
            'body' => $this->corpo,
            'format' => 'filament',
            'icon' => 'heroicon-o-megaphone',
            'color' => 'info',
        ]);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title($this->titulo)
            ->body($this->corpo)
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag($this->tag)
            ->vibrate([200, 100, 200])
            ->data(['url' => '/admin']);
    }
}
