<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\HannaDevice;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class HannaThresholdAlert extends Notification
{
    public function __construct(
        private readonly HannaDevice $device,
        private readonly array $violacoes,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('hanna_threshold', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('hanna_threshold', 'mail')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $poolName = $this->device->piscina?->name ?? $this->device->name;
        $lista = implode('; ', $this->violacoes);

        return new DatabaseMessage([
            'title' => "Sensor Hanna — {$poolName}: parâmetros fora dos limites",
            'body' => $lista,
            'format' => 'filament',
            'icon' => 'heroicon-o-beaker',
            'color' => 'danger',
        ]);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $poolName = $this->device->piscina?->name ?? $this->device->name;
        $lista = implode('; ', $this->violacoes);

        return (new WebPushMessage())
            ->title("Sensor Hanna — {$poolName}: fora dos limites")
            ->body($lista)
            ->data(['url' => '/admin'])
            ->tag("hanna-threshold-{$this->device->id}");
    }

    public function toMail(object $notifiable): MailMessage
    {
        $poolName = $this->device->piscina?->name ?? $this->device->name;
        $lista = implode('; ', $this->violacoes);

        return (new MailMessage())
            ->subject("Alerta Sonda: Parâmetros Fora dos Limites — {$poolName}")
            ->greeting("Olá, {$notifiable->name}.")
            ->line("O controlador automático da piscina {$poolName} reportou violações nos parâmetros de qualidade da água:")
            ->line($lista)
            ->action('Ver Painel de Controlo', url('/admin'))
            ->line('Por favor, efetue uma verificação local para repor os parâmetros dentro dos limites regulamentares.')
            ->salutation('Cumprimentos, Equipa MMCrespo');
    }
}
