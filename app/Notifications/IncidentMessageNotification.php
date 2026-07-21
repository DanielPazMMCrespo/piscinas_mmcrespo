<?php declare(strict_types=1);

namespace App\Notifications;

use App\Constants\NotificationType;
use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Disparado a cada mensagem nova na thread de um incidente — mensagem de
 * chat ou mudança de estado gerada pelo sistema (ex: resolução).
 */
class IncidentMessageNotification extends Notification
{
    public function __construct(
        private readonly Incident $incident,
        private readonly User $autor,
        private readonly string $texto,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable->querNotificacao(NotificationType::INCIDENTE)) {
            return [];
        }

        // TODO: adicionar 'mail' aqui quando o SMTP estiver configurado.
        return ['database', WebPushChannel::class];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';

        return new DatabaseMessage([
            'title' => "Incidente — {$instalacao}: {$this->autor->name}",
            'body' => $this->texto,
            'format' => 'filament',
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'color' => 'warning',
        ]);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';

        return (new WebPushMessage())
            ->title("Incidente — {$instalacao}: {$this->autor->name}")
            ->body($this->texto)
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag("incident-{$this->incident->id}-chat")
            ->vibrate([200, 100, 200])
            ->data(['url' => IncidentResource::getUrl('view', ['record' => $this->incident->id])]);
    }
}
