<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\DailyRecordResource;
use App\Models\TapAlert;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Notifications\Actions\Action;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Disparado quando uma torneira fica aberta (agua_modo='on_com_agua') por mais
 * tempo do que o limite configurado — uma vez por episódio (TapAlert.notified_at).
 */
class TorneiraAbertaNotification extends Notification
{
    public function __construct(
        private readonly TapAlert $tap,
        private readonly string $nomePiscina,
        private readonly int $horasAberta,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('operacao', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('operacao', 'mail')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Torneira aberta há mais de {$this->horasAberta}h — {$this->nomePiscina}")
            ->body('Aberta desde '.$this->tap->opened_at->format('d/m H:i').'.')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->actions([
                Action::make('resolve')
                    ->label('Registar Fecho')
                    ->button()
                    ->url(DailyRecordResource::getUrl('create')),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title("Torneira aberta há mais de {$this->horasAberta}h — {$this->nomePiscina}")
            ->body('Aberta desde '.$this->tap->opened_at->format('d/m H:i').'.')
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->action('abrir_registo', 'Ver Registo')
            ->tag("tap-{$this->tap->id}")
            ->vibrate([200, 100, 200])
            ->data(['url' => DailyRecordResource::getUrl('create')]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("Alerta: Torneira Aberta há mais de {$this->horasAberta}h")
            ->greeting("Atenção,")
            ->line("Foi detetado que a torneira da piscina **{$this->nomePiscina}** está aberta há mais de {$this->horasAberta} horas.")
            ->line("Aberta desde: " . $this->tap->opened_at->format('d/m H:i'))
            ->action('Registar Fecho / Ver Detalhes', DailyRecordResource::getUrl('create'));
    }
}
