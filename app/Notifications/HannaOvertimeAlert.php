<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\HannaDevice;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Disparado uma única vez por episódio, quando o pH se mantém fora da banda
 * proporcional do controlador Hanna (setpoint ± banda) por mais tempo que o
 * "Overtime" configurado no próprio dispositivo — a dosagem deixou de corrigir.
 */
class HannaOvertimeAlert extends Notification
{
    public function __construct(
        private readonly HannaDevice $device,
        private readonly float $ph,
        private readonly array $dosingSettings,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('hanna_overtime', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('hanna_overtime', 'mail')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $poolName = $this->device->piscina?->name ?? $this->device->name;
        $horas = intdiv($this->dosingSettings['overtimeMinutes'], 60);
        $fmt = fn (float $v): string => number_format($v, 2, ',', '');

        return new DatabaseMessage([
            'title' => "Sensor Hanna — {$poolName}: pH em overtime",
            'body' => "pH {$fmt($this->ph)} fora do setpoint {$fmt($this->dosingSettings['setpoint'])} ± {$fmt($this->dosingSettings['band'])} há mais de {$horas}h — a dosagem não está a corrigir.",
            'format' => 'filament',
            'icon' => 'heroicon-o-beaker',
            'color' => 'danger',
        ]);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $poolName = $this->device->piscina?->name ?? $this->device->name;
        $horas = intdiv($this->dosingSettings['overtimeMinutes'], 60);
        $fmt = fn (float $v): string => number_format($v, 2, ',', '');

        return (new WebPushMessage())
            ->title("Sensor Hanna — {$poolName}: pH em overtime")
            ->body("pH {$fmt($this->ph)} fora do setpoint {$fmt($this->dosingSettings['setpoint'])} ± {$fmt($this->dosingSettings['band'])} há mais de {$horas}h.")
            ->data(['url' => '/admin'])
            ->tag("hanna-overtime-{$this->device->id}");
    }

    public function toMail(object $notifiable): MailMessage
    {
        $poolName = $this->device->piscina?->name ?? $this->device->name;
        $horas = intdiv($this->dosingSettings['overtimeMinutes'], 60);
        $fmt = fn (float $v): string => number_format($v, 2, ',', '');

        return (new MailMessage())
            ->subject("Alerta Crítico Hanna: pH em Overtime — {$poolName}")
            ->greeting("Olá, {$notifiable->name}.")
            ->line("O controlador automático da piscina {$poolName} está a reportar pH em overtime há mais de {$horas}h.")
            ->line("Valor Atual: {$fmt($this->ph)}")
            ->line("Setpoint Configurado: {$fmt($this->dosingSettings['setpoint'])} ± {$fmt($this->dosingSettings['band'])}")
            ->action('Ver Painel de Controlo', url('/admin'))
            ->line('Isto indica que a dosagem automática não está a conseguir corrigir o desvio. Por favor, verifique as bombas doseadoras e os níveis de produto químico.')
            ->salutation('Cumprimentos, Equipa MMCrespo');
    }
}
