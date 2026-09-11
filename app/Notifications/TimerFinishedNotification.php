<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
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
        $channels = ['database'];
        if ($notifiable->wantsNotification('timer_finished', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('timer_finished', 'mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $faseLabel = $this->fase === 'enxaguamento' ? 'Enxaguamento' : 'Retrolavagem';
        $titulo = $this->piscina
            ? "{$faseLabel} terminada — {$this->piscina}"
            : "{$faseLabel} terminada";

        return FilamentNotification::make()
            ->title($titulo)
            ->body('O tempo definido terminou. Pode passar à fase seguinte.')
            ->icon('heroicon-o-clock')
            ->color('success')
            ->actions([
                Action::make('abrir')
                    ->label('Abrir Registo')
                    ->button()
                    ->url('/admin/daily-records/create'),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $faseLabel = $this->fase === 'enxaguamento' ? 'Enxaguamento' : 'Retrolavagem';
        $titulo = $this->piscina
            ? "{$faseLabel} terminada — {$this->piscina}"
            : "{$faseLabel} terminada";

        return (new WebPushMessage)
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

    public function toMail(object $notifiable): MailMessage
    {
        $faseLabel = $this->fase === 'enxaguamento' ? 'Enxaguamento' : 'Retrolavagem';
        $titulo = $this->piscina
            ? "{$faseLabel} terminada — {$this->piscina}"
            : "{$faseLabel} terminada";

        return (new MailMessage)
            ->subject($titulo)
            ->greeting($titulo)
            ->line('O tempo definido terminou. Pode passar à fase seguinte.')
            ->action('Abrir Registo', url('/admin/daily-records/create'));
    }
}
