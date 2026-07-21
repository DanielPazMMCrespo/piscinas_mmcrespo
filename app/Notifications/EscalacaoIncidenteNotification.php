<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;
use NotificationChannels\WebPush\WebPushChannel;
use App\Models\Incident;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Notifications\Actions\Action;
use Illuminate\Support\Str;
use App\Filament\Resources\IncidentResource;

class EscalacaoIncidenteNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Incident $incidente) {}
    
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        
        if (method_exists($notifiable, 'wantsNotification') && $notifiable->wantsNotification('escalacao_incidente', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        
        return $channels;
    }
    
    public function toDatabase(object $notifiable): array
    {
        // Use FilamentNotification format
        return FilamentNotification::make()
            ->title('Incidente sem resposta há 24h — ' . ($this->incidente->instalacao?->name ?? 'Instalação'))
            ->body(Str::limit($this->incidente->descricao, 100))
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->actions([
                Action::make('view')
                    ->label('Ver Incidente')
                    ->button()
                    ->url(IncidentResource::getUrl('view', ['record' => $this->incidente->id]))
            ])
            ->getDatabaseMessage();
    }
    
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title('Incidente sem resposta há 24h')
            ->body(Str::limit($this->incidente->descricao, 100))
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag('escalacao-' . $this->incidente->id)
            ->vibrate([200, 100, 200])
            ->data(['url' => IncidentResource::getUrl('view', ['record' => $this->incidente->id])]);
    }
}
