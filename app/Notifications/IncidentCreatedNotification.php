<?php declare(strict_types=1);

namespace App\Notifications;

use App\Constants\NotificationType;
use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Disparado quando um incidente é reportado — chega ao sino de Admin/Técnico
 * e ao push do telemóvel (mesmo com a app fechada) para que ajam sem depender
 * de WhatsApp/telefone.
 */
class IncidentCreatedNotification extends Notification
{
    public function __construct(
        private readonly Incident $incident,
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
        $reportante = $this->incident->utilizador?->name ?? 'Utilizador';

        return new DatabaseMessage([
            'title' => "Novo incidente — {$instalacao}",
            'body' => "{$reportante}: {$this->incident->descricao}",
            'format' => 'filament',
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'danger',
        ]);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';
        $reportante = $this->incident->utilizador?->name ?? 'Utilizador';

        return (new WebPushMessage())
            ->title("Novo incidente — {$instalacao}")
            ->body("{$reportante}: {$this->incident->descricao}")
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag("incident-{$this->incident->id}")
            ->vibrate([200, 100, 200])
            ->data(['url' => IncidentResource::getUrl('view', ['record' => $this->incident->id])]);
    }
}
