<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Disparado a cada mensagem nova na thread de um incidente — mensagem de
 * chat ou mudança de estado gerada pelo sistema (ex: resolução).
 * Entrega imediata para comunicação em tempo real entre técnicos e nadadores.
 */
class IncidentMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Incident $incident,
        private readonly User $autor,
        private readonly string $texto,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('incident_message', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('incident_message', 'mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';

        return FilamentNotification::make()
            ->title("Incidente — {$instalacao}: {$this->autor->name}")
            ->body($this->texto)
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('warning')
            ->actions([
                Action::make('ver')
                    ->label('Ver Incidente')
                    ->button()
                    ->url(IncidentResource::getUrl('view', ['record' => $this->incident->id])),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';

        return (new WebPushMessage)
            ->title("Incidente — {$instalacao}: {$this->autor->name}")
            ->body($this->texto)
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag("incident-{$this->incident->id}-chat")
            ->vibrate([200, 100, 200])
            ->data(['url' => IncidentResource::getUrl('view', ['record' => $this->incident->id])]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';

        return (new MailMessage)
            ->subject("Incidente — {$instalacao}: {$this->autor->name}")
            ->greeting('Nova mensagem no incidente:')
            ->line($this->texto)
            ->action('Ver Incidente', IncidentResource::getUrl('view', ['record' => $this->incident->id]));
    }
}
