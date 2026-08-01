<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Disparado quando um incidente é reportado — chega ao sino de Admin/Técnico
 * e ao push do telemóvel (mesmo com a app fechada) para que ajam sem depender
 * de WhatsApp/telefone.
 */
class IncidentCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Incident $incident,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('incident_created', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('incident_created', 'mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';
        $reportante = $this->incident->utilizador?->name ?? 'Utilizador';

        return FilamentNotification::make()
            ->title("Novo incidente — {$instalacao}")
            ->body("{$reportante}: {$this->incident->descricao}")
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->actions([
                Action::make('view')
                    ->label('Ver Incidente')
                    ->button()
                    ->url(IncidentResource::getUrl('view', ['record' => $this->incident->id])),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';
        $reportante = $this->incident->utilizador?->name ?? 'Utilizador';

        return (new WebPushMessage)
            ->title("Novo incidente — {$instalacao}")
            ->body("{$reportante}: {$this->incident->descricao}")
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag("incident-{$this->incident->id}")
            ->vibrate([200, 100, 200])
            ->data(['url' => IncidentResource::getUrl('view', ['record' => $this->incident->id])]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $instalacao = $this->incident->instalacao?->name ?? 'Instalação';

        return (new MailMessage)
            ->subject("Novo Incidente: {$instalacao}")
            ->greeting('Olá,')
            ->line("Foi reportado um novo incidente na instalação **{$instalacao}**.")
            ->line("**Descrição:** {$this->incident->descricao}")
            ->action('Ver Incidente', IncidentResource::getUrl('view', ['record' => $this->incident->id]));
    }
}
