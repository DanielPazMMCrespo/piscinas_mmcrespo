<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class ComparacaoSemanalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $linhas
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (method_exists($notifiable, 'wantsNotification') && $notifiable->wantsNotification('comparacao_semanal', 'push')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $weekNumber = now()->weekOfYear;
        
        return FilamentNotification::make()
            ->title("Comparação semanal — semana {$weekNumber}")
            ->body(implode(' · ', $this->linhas))
            ->icon('heroicon-o-chart-bar-square')
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, object $notification): WebPushMessage
    {
        $weekNumber = now()->weekOfYear;
        
        return (new WebPushMessage)
            ->title("Comparação semanal — semana {$weekNumber}")
            ->body(implode(' · ', $this->linhas))
            ->icon('/images/icon.png')
            ->options(['tag' => "comparacao-semanal-{$weekNumber}"]);
    }
}
