<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Disparado quando um incidente é reportado — chega ao sino de Admin/Técnico
 * para que ajam sem depender de WhatsApp/telefone.
 */
class IncidentCreatedNotification extends Notification
{
    public function __construct(
        private readonly Incident $incident,
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
        $reportante = $this->incident->utilizador?->name ?? 'Utilizador';

        return new DatabaseMessage([
            'title' => "Novo incidente — {$instalacao}",
            'body' => "{$reportante}: {$this->incident->descricao}",
            'format' => 'filament',
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'danger',
        ]);
    }
}
