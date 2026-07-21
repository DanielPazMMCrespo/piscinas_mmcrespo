<?php declare(strict_types=1);

namespace App\Notifications;

use App\Constants\NotificationType;
use App\Models\HannaDevice;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

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
        if (! $notifiable->querNotificacao(NotificationType::HANNA)) {
            return [];
        }

        return ['database'];
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
}
