<?php declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Disparado pelo servidor quando o timer da retrolavagem/enxaguamento chega a
 * zero — chega ao push do telemóvel mesmo com a app fechada (o técnico está na
 * casa das máquinas com o ecrã bloqueado).
 */
class TimerFinishedNotification extends Notification
{
    public function __construct(
        private readonly string $fase,
        private readonly ?string $piscina = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = [];
        if ($notifiable->wantsNotification('operacao', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('operacao', 'mail')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $faseLabel = $this->fase === 'enxaguamento' ? 'Enxaguamento' : 'Retrolavagem';
        $titulo = $this->piscina
            ? "{$faseLabel} terminada — {$this->piscina}"
            : "{$faseLabel} terminada";

        return (new WebPushMessage())
            ->title($titulo)
            ->body('O tempo definido terminou. Pode passar à fase seguinte.')
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->action('abrir_registo', 'Abrir Registo')
            ->tag("timer-{$this->fase}")
            ->requireInteraction()
            ->vibrate([300, 150, 300])
            ->data(['url' => '/admin/daily-records/create']);
    }
}
