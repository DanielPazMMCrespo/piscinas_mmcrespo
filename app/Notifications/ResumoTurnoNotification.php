<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class ResumoTurnoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $linhas,
        public string $horario,
        public string $status = 'warning'
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (method_exists($notifiable, 'wantsNotification') && $notifiable->wantsNotification('resumo_turno', 'push')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Resumo operacional — {$this->horario}")
            ->body(implode(' · ', $this->linhas))
            ->icon('heroicon-o-document-chart-bar')
            ->color($this->status)
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, object $notification): WebPushMessage
    {
        $date = now()->format('Y-m-d');
        
        return (new WebPushMessage)
            ->title("Resumo operacional — {$this->horario}")
            ->body(implode(' · ', $this->linhas))
            ->icon('/images/icon.png')
            ->options(['tag' => "resumo-turno-{$date}-{$this->horario}"]);
    }
}
