<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A sincronização Hanna não consegue autenticar na Hanna Cloud.
 *
 * Sem este aviso o sistema fica cego: as sondas aparecem "em falha" na página
 * Sensores Hanna sem que ninguém saiba que a causa é a credencial, e as
 * leituras ficam paradas durante dias.
 */
class HannaSyncFalhouNotification extends Notification
{
    public function __construct(
        private readonly string $erro,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsNotification('hanna_sync_falhou', 'push')) {
            $channels[] = WebPushChannel::class;
        }
        if ($notifiable->wantsNotification('hanna_sync_falhou', 'mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Sondas Hanna: login recusado')
            ->body('A Hanna Cloud recusou as credenciais. As leituras estão paradas. Erro: '.$this->erro)
            ->icon('heroicon-o-signal-slash')
            ->color('danger')
            ->actions([
                Action::make('ver')
                    ->label('Ver Sensores')
                    ->button()
                    ->url('/admin/hanna-devices'),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Sondas Hanna sem sincronização')
            ->body('Login na Hanna Cloud recusado. As leituras estão paradas.')
            ->data(['url' => '/admin/hanna-devices'])
            ->tag('hanna-sync-falhou');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sondas Hanna sem sincronização: login recusado')
            ->greeting("Olá, {$notifiable->name}.")
            ->line('A sincronização automática das sondas Hanna não consegue autenticar na Hanna Cloud.')
            ->line('Enquanto isto durar, nenhuma leitura nova entra no sistema e as sondas aparecem como "em falha" no painel.')
            ->line("Erro devolvido pela Hanna Cloud: {$this->erro}")
            ->action('Ver Sensores Hanna', url('/admin/hanna-devices'))
            ->line('Verifique as credenciais da conta Hanna Cloud (HANNA_CLOUD_EMAIL e HANNA_CLOUD_PASSWORD).')
            ->salutation('Cumprimentos, Equipa MMCrespo');
    }
}
