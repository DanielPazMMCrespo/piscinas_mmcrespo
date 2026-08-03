<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\EncerramentoPiscinas;
use App\Models\PoolClosure;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Avisa a equipa de que uma piscina foi encerrada ou reaberta. Toda a gente que
 * trabalha no local precisa de saber — o nadador-salvador deixa de ter registos
 * a fazer, o técnico deixa de tratar a água (ou passa a tratá-la sem público).
 */
class PiscinaEncerradaNotification extends Notification
{
    public function __construct(
        private readonly PoolClosure $encerramento,
        private readonly string $nomePiscina,
        private readonly bool $reaberta = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->wantsNotification('piscina_encerrada', 'push')) {
            $channels[] = WebPushChannel::class;
        }

        if ($notifiable->wantsNotification('piscina_encerrada', 'mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->titulo())
            ->body($this->corpo())
            ->icon($this->reaberta ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
            ->color($this->reaberta ? 'success' : 'warning')
            ->actions([
                Action::make('ver')
                    ->label('Ver encerramentos')
                    ->button()
                    ->url(EncerramentoPiscinas::getUrl()),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->titulo())
            ->body($this->corpo())
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->action('abrir_encerramentos', 'Ver')
            ->tag("encerramento-{$this->encerramento->id}")
            ->vibrate([200, 100, 200])
            ->data(['url' => EncerramentoPiscinas::getUrl()]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->titulo())
            ->greeting('Atenção,')
            ->line($this->corpo())
            ->action('Ver encerramentos', EncerramentoPiscinas::getUrl());
    }

    private function titulo(): string
    {
        return $this->reaberta
            ? "{$this->nomePiscina} reaberta"
            : "{$this->nomePiscina} encerrada";
    }

    private function corpo(): string
    {
        if ($this->reaberta) {
            return "Encerramento {$this->encerramento->descricao_periodo} — a piscina volta à operação normal.";
        }

        $partes = [
            'Motivo: '.$this->encerramento->motivo_label,
            'Período: '.$this->encerramento->descricao_periodo,
            $this->encerramento->agua_em_tratamento
                ? 'A água continua em tratamento — os registos diários mantêm-se possíveis.'
                : 'Piscina parada — não são esperados registos diários.',
        ];

        return implode(' · ', $partes);
    }
}
