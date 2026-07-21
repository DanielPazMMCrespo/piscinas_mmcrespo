<?php declare(strict_types=1);

namespace App\Notifications;

use App\Constants\NotificationType;
use App\Models\HannaDevice;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class HannaThresholdAlert extends Notification
{
    public function __construct(
        private readonly HannaDevice $device,
        private readonly array $violacoes,
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
        $lista = implode('; ', $this->violacoes);

        return new DatabaseMessage([
            'title' => "Sensor Hanna — {$poolName}: parâmetros fora dos limites",
            'body' => $lista,
            'format' => 'filament',
            'icon' => 'heroicon-o-beaker',
            'color' => 'danger',
        ]);
    }
}
