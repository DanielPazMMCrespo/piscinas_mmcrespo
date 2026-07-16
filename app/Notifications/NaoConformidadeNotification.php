<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Disparado quando um registo diário tem parâmetros fora dos limites legais
 * (CN 14/DA) — chega ao admin mesmo com a app fechada.
 */
class NaoConformidadeNotification extends Notification
{
    /**
     * @param  list<string>  $violacoes
     */
    public function __construct(
        private readonly DailyRecord $registo,
        private readonly array $violacoes,
        private readonly string $nomePiscina,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'title' => 'Parâmetros fora dos limites: '.$this->nomePiscina,
            'body' => implode(' · ', $this->violacoes).'.',
            'format' => 'filament',
            'icon' => 'heroicon-o-beaker',
            'color' => 'danger',
        ]);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title('Parâmetros fora dos limites — '.$this->nomePiscina)
            ->body(implode(' · ', $this->violacoes).'.')
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag("nao-conforme-{$this->registo->id}")
            ->vibrate([200, 100, 200])
            ->data(['url' => DailyRecordResource::getUrl('edit', ['record' => $this->registo->id])]);
    }
}
