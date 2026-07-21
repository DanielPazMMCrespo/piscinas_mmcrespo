<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\DosingContainer;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class DosingContainerLowAlert extends Notification
{
    public function __construct(
        private readonly DosingContainer $container,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $piscina = $this->container->piscina?->nomeCompleto() ?? 'Piscina';
        $pct = $this->container->percentagem();
        $pctTxt = $pct !== null ? number_format($pct, 0, ',', '') . '%' : 'nível baixo';

        return new DatabaseMessage([
            'title' => "Bidão de {$this->container->tipoLabel()} — {$piscina}: repor",
            'body' => "Nível a {$pctTxt}. Reabastecer o bidão de {$this->container->tipoLabel()}.",
            'format' => 'filament',
            'icon' => 'heroicon-o-beaker',
            'color' => 'warning',
        ]);
    }
}
