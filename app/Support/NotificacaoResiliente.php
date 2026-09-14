<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Envia uma notificação destinatário a destinatário, isolando a falha de um
 * canal externo do trabalho que a originou. Devolve quantos destinatários
 * foram notificados sem erro.
 *
 * Porquê: `Notification::send($colecao, $n)` percorre destinatários e canais
 * em série e deixa a exceção subir. Um único destinatário recusado pelo
 * Resend (domínio por verificar) ou um endpoint de push morto aborta o envio
 * para todos os destinatários seguintes e rebenta com quem chamou — e quem
 * chama é sempre trabalho de negócio: o job que desconta stock, o sync das
 * sondas, o encerramento de uma piscina, o comando que marca `notified_at`.
 * Perdia-se o trabalho (ou repetia-se, em retry) por causa de um e-mail.
 *
 * O `via()` de todas as notificações desta app devolve 'database' primeiro e
 * 'mail' por último, logo o sino do painel fica sempre escrito mesmo quando o
 * e-mail rebenta a seguir.
 *
 * A falha nunca é engolida em silêncio: vai para a Auditoria (permanente e
 * visível no painel, ao contrário de `storage/logs`, que é efémero no
 * Railway).
 */
final class NotificacaoResiliente
{
    /**
     * @param  iterable<int, Model>  $destinatarios
     */
    public static function enviar(iterable $destinatarios, Notification $notificacao, string $contexto): int
    {
        $enviadas = 0;
        $falhas = [];

        foreach ($destinatarios as $destinatario) {
            try {
                NotificationFacade::send([$destinatario], $notificacao);
                $enviadas++;
            } catch (Throwable $e) {
                $falhas[] = [
                    'destinatario_id' => $destinatario->getKey(),
                    'erro' => $e->getMessage(),
                ];
            }
        }

        if ($falhas === []) {
            return $enviadas;
        }

        $classe = $notificacao::class;

        Log::error('notificacao_parcialmente_falhada', [
            'contexto' => $contexto,
            'notificacao' => $classe,
            'enviadas' => $enviadas,
            'falhas' => $falhas,
        ]);

        Auditoria::sistema(
            'Envio de notificação falhou para '.count($falhas).' destinatário(s) ('.$contexto.').',
            [
                'notificacao' => $classe,
                'enviadas' => $enviadas,
                'falhas' => $falhas,
            ],
        );

        return $enviadas;
    }
}
