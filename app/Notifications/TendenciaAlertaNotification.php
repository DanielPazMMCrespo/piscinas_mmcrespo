<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Filament\Notifications\Notification as FilamentNotification;

class TendenciaAlertaNotification extends Notification
{
    public function __construct(
        private readonly string $nomePiscina,
        private readonly string $parametro,
        private readonly array $valores,
        private readonly float $previsao,
        private readonly float $limite,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('tendencia_alerta', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $label = match($this->parametro) {
            'ph' => 'pH',
            'cloro_livre' => 'Cloro livre',
            default => $this->parametro,
        };
        
        $valoresStr = implode(' → ', array_map(fn($v) => number_format($v, 2, ',', ''), $this->valores));
        
        return FilamentNotification::make()
            ->title("Tendência degradante — {$this->nomePiscina} — {$label}")
            ->body("Últimos registos: {$valoresStr}. Previsão: " . number_format($this->previsao, 2, ',', '') . " (limite: " . number_format($this->limite, 2, ',', '') . ")")
            ->icon('heroicon-o-arrow-trending-down')
            ->color('warning')
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        // same data as toDatabase but in WebPush format
        $label = match($this->parametro) { 
            'ph' => 'pH', 
            'cloro_livre' => 'Cloro livre', 
            default => $this->parametro 
        };
        $valoresStr = implode(' → ', array_map(fn($v) => number_format($v, 2, ',', ''), $this->valores));
        
        return (new WebPushMessage())
            ->title("Tendência degradante — {$this->nomePiscina}")
            ->body("{$label}: {$valoresStr}. Previsão: " . number_format($this->previsao, 2, ',', ''))
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag('tendencia-' . md5($this->nomePiscina . $this->parametro))
            ->vibrate([200, 100, 200])
            ->data(['url' => '/admin']);
    }
}
