<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Notificação de teste (página Notificações) — replica o payload de cada
 * notificação real para confirmar entrega com a app fechada, sem criar
 * registos reais (incidentes, registos diários, torneiras) que espalhariam
 * avisos a outros utilizadores.
 */
class TestPushNotification extends Notification
{
    private const CONTEUDO = [
        'incidente' => [
            'title' => 'Novo incidente — Leiria Competição (teste)',
            'body' => 'Ana: Fuga junto ao filtro (teste)',
            'tag' => 'test-incidente',
            'url' => '/admin/incidents',
        ],
        'mensagem' => [
            'title' => 'Incidente — Leiria Competição: Bruno (teste)',
            'body' => 'A caminho. (teste)',
            'tag' => 'test-mensagem',
            'url' => '/admin/incidents',
        ],
        'timer' => [
            'title' => 'Retrolavagem terminada — Leiria Competição (teste)',
            'body' => 'O tempo definido terminou. Pode passar à fase seguinte. (teste)',
            'tag' => 'test-timer',
            'url' => '/admin/daily-records/create',
        ],
        'fora_limites' => [
            'title' => 'Parâmetros fora dos limites — Leiria Competição (teste)',
            'body' => 'pH 8,50 · cloro livre 0,20 mg/L. (teste)',
            'tag' => 'test-fora-limites',
            'url' => '/admin/daily-records',
        ],
        'torneira' => [
            'title' => 'Torneira aberta há mais de 4h — Leiria Competição (teste)',
            'body' => 'Aberta desde 08:00. (teste)',
            'tag' => 'test-torneira',
            'url' => '/admin/daily-records/create',
        ],
        'resumo' => [
            'title' => 'Resumo 08:00 — 2 piscinas não conformes (teste)',
            'body' => 'Leiria Competição: pH 8,50 acima do máximo · Maceira: cloro combinado 0,90 mg/L acima do máximo (teste)',
            'tag' => 'test-resumo',
            'url' => '/admin',
        ],
    ];

    public function __construct(
        private readonly string $tipo,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $conteudo = self::CONTEUDO[$this->tipo] ?? self::CONTEUDO['incidente'];

        return (new WebPushMessage())
            ->title($conteudo['title'])
            ->body($conteudo['body'])
            ->icon('/images/icon-192.png')
            ->badge('/images/icon-192.png')
            ->tag($conteudo['tag'])
            ->vibrate([200, 100, 200])
            ->data(['url' => $conteudo['url']]);
    }

    /** @return list<string> */
    public static function tiposValidos(): array
    {
        return array_keys(self::CONTEUDO);
    }
}
