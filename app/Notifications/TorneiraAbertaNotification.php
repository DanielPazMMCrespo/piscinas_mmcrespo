<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\DailyRecordResource;
use App\Models\TapAlert;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Disparado quando uma torneira fica aberta (agua_modo='on_com_agua') por mais
 * tempo do que o limite configurado — uma vez por episódio (TapAlert.notified_at).
 */
class TorneiraAbertaNotification extends Notification
{
    public function __construct(
        private readonly TapAlert $tap,
        private readonly string $nomePiscina,
        private readonly int $horasAberta,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'title' => "Torneira aberta há mais de {$this->horasAberta}h — {$this->nomePiscina}",
            'body' => 'Aberta desde '.$this->tap->opened_at->format('d/m H:i').'.',
            'format' => 'filament',
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
        ]);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title("Torneira aberta há mais de {$this->horasAberta}h — {$this->nomePiscina}")
            ->body('Aberta desde '.$this->tap->opened_at->format('d/m H:i').'.')
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag("tap-{$this->tap->id}")
            ->vibrate([200, 100, 200])
            ->data(['url' => DailyRecordResource::getUrl('create')]);
    }
}
