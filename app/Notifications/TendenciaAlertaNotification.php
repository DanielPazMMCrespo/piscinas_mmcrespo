<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TendenciaAlertaNotification extends Notification
{
    /**
     * @param  array{estado: string, inicial: float, final: float, delta: float}|null  $orp
     *                                                                                       contraprova da sonda; null quando o parâmetro não é confirmável por ORP (pH)
     */
    public function __construct(
        private readonly string $nomePiscina,
        private readonly string $parametro,
        private readonly array $valores,
        private readonly float $previsao,
        private readonly float $limite,
        private readonly ?array $orp = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('tendencia_alerta', 'push')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $valoresStr = implode(' → ', array_map(fn ($v) => number_format($v, 2, ',', ''), $this->valores));
        $corpo = "Últimos registos: {$valoresStr}. Previsão: ".number_format($this->previsao, 2, ',', '').' (limite: '.number_format($this->limite, 2, ',', '').')'.$this->notaOrp();

        return FilamentNotification::make()
            ->title("Tendência degradante — {$this->nomePiscina} — {$this->label()}")
            ->body($corpo)
            ->icon('heroicon-o-arrow-trending-down')
            ->color($this->confirmadaPorOrp() ? 'danger' : 'warning')
            ->actions([
                Action::make('ver')
                    ->label('Ver Análise')
                    ->button()
                    ->url('/admin/analise-parametros'),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        // same data as toDatabase but in WebPush format
        $valoresStr = implode(' → ', array_map(fn ($v) => number_format($v, 2, ',', ''), $this->valores));

        return (new WebPushMessage)
            ->title("Tendência degradante — {$this->nomePiscina}")
            ->body("{$this->label()}: {$valoresStr}. Previsão: ".number_format($this->previsao, 2, ',', '').$this->notaOrp())
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag('tendencia-'.md5($this->nomePiscina.$this->parametro))
            ->vibrate([200, 100, 200])
            ->data(['url' => '/admin/analise-parametros']);
    }

    private function label(): string
    {
        return match ($this->parametro) {
            'ph' => 'pH',
            'cloro_livre' => 'Cloro livre',
            default => $this->parametro,
        };
    }

    private function confirmadaPorOrp(): bool
    {
        return ($this->orp['estado'] ?? null) === 'confirma';
    }

    /**
     * O que a sonda diz sobre a mesma janela. Sem esta linha, uma descida de
     * cloro livre nas análises manuais lê-se como degradação quando muitas
     * vezes é só o controlador a dosar entre registos.
     */
    private function notaOrp(): string
    {
        if ($this->orp === null) {
            return '';
        }

        if (! $this->confirmadaPorOrp()) {
            return ' Sem ORP da sonda nesta janela para confirmar — verificar no local.';
        }

        $inicial = number_format($this->orp['inicial'], 0, ',', '');
        $final = number_format($this->orp['final'], 0, ',', '');

        return " ORP confirma a descida: {$inicial} → {$final} mV.";
    }
}
