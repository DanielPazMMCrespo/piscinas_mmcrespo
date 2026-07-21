<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Notifications\Actions\Action;
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
        $channels = ['database'];
        if ($notifiable->wantsNotification('nao_conformidade', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('nao_conformidade', 'mail')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Parâmetros fora dos limites — '.$this->nomePiscina)
            ->body(implode(' · ', $this->violacoes).'.')
            ->icon('heroicon-o-exclamation-circle')
            ->color('danger')
            ->actions([
                Action::make('view')
                    ->label('Ver Registo')
                    ->button()
                    ->url(DailyRecordResource::getUrl('edit', ['record' => $this->registo->id])),
            ])
            ->getDatabaseMessage();
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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("🔴 Parâmetros fora dos limites: {$this->nomePiscina}")
            ->greeting('Atenção,')
            ->line("Foram detetados parâmetros fora dos limites legais na **{$this->nomePiscina}**:")
            ->line(implode(' · ', $this->violacoes))
            ->action('Ver Registo', DailyRecordResource::getUrl('edit', ['record' => $this->registo->id]))
            ->line('Por favor, verifique a situação o mais breve possível.');
    }
}
