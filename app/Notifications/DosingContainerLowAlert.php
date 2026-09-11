<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\DosingContainer;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class DosingContainerLowAlert extends Notification
{
    public function __construct(
        private readonly DosingContainer $container,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('dosing_low', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('dosing_low', 'mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $piscina = $this->container->piscina?->nomeCompleto() ?? 'Piscina';
        $pct = $this->container->percentagem();
        $pctTxt = $pct !== null ? number_format($pct, 0, ',', '').'%' : 'nível baixo';

        return FilamentNotification::make()
            ->title("Bidão de {$this->container->tipoLabel()} — {$piscina}: repor")
            ->body("Nível a {$pctTxt}. Reabastecer o bidão de {$this->container->tipoLabel()}.")
            ->icon('heroicon-o-beaker')
            ->color('warning')
            ->actions([
                Action::make('ver')
                    ->label('Ver no Stock Hub')
                    ->button()
                    ->url('/admin/stock'),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $piscina = $this->container->piscina?->nomeCompleto() ?? 'Piscina';
        $pct = $this->container->percentagem();
        $pctTxt = $pct !== null ? number_format($pct, 0, ',', '').'%' : 'nível baixo';

        return (new WebPushMessage)
            ->title("Bidão de {$this->container->tipoLabel()} — {$piscina}: repor")
            ->body("Nível a {$pctTxt}. Reabastecer o bidão de {$this->container->tipoLabel()}.")
            ->data(['url' => '/admin/dosing-containers'])
            ->tag("dosing-low-{$this->container->id}");
    }

    public function toMail(object $notifiable): MailMessage
    {
        $piscina = $this->container->piscina?->nomeCompleto() ?? 'Piscina';
        $pct = $this->container->percentagem();
        $pctTxt = $pct !== null ? number_format($pct, 0, ',', '').'%' : 'nível baixo';

        return (new MailMessage)
            ->subject("Alerta: Nível Baixo no Bidão de {$this->container->tipoLabel()} — {$piscina}")
            ->greeting("Olá, {$notifiable->name}.")
            ->line("O bidão de doseamento de {$this->container->tipoLabel()} da {$piscina} atingiu o nível crítico de {$pctTxt}.")
            ->action('Ver Bidões de Doseamento', url('/admin/dosing-containers'))
            ->line('Por favor, efetue o reabastecimento o quanto antes para garantir o tratamento correto da água.')
            ->salutation('Cumprimentos, Equipa MMCrespo');
    }
}
