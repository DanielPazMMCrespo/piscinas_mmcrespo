<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Resumo periódico (horários configuráveis em Definições do Sistema) do estado
 * de conformidade das piscinas — só é enviado quando há alguma não conforme.
 */
class ResumoConformidadeNotification extends Notification
{
    /**
     * @param  list<string>  $linhas  uma linha por piscina não conforme
     */
    public function __construct(
        private readonly array $linhas,
        private readonly string $horario,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('resumo_conformidade', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('resumo_conformidade', 'mail')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    private function titulo(): string
    {
        $n = count($this->linhas);

        return $n === 1
            ? "Resumo {$this->horario} — 1 piscina não conforme"
            : "Resumo {$this->horario} — {$n} piscinas não conformes";
    }

    private function corpo(): string
    {
        return implode(' · ', $this->linhas);
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'title' => $this->titulo(),
            'body' => $this->corpo(),
            'format' => 'filament',
            'icon' => 'heroicon-o-clipboard-document-check',
            'color' => 'danger',
        ]);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title($this->titulo())
            ->body($this->corpo())
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag('digest-'.now()->toDateString().'-'.$this->horario)
            ->vibrate([200, 100, 200])
            ->data(['url' => '/admin']);
    }
}
