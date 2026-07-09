<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

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
        // TODO: adicionar 'mail' aqui quando o SMTP estiver configurado.
        return ['database'];
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
}
