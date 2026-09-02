<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TimerPush;
use App\Notifications\TimerFinishedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Envia o push do timer da retrolavagem quando fire_at é atingido.
 *
 * Corre em polling curto (~50s) e é agendado everyMinute (withoutOverlapping) no
 * schedule:work do container web — mesmo padrão do queue:work já usado no repo.
 * Evita um serviço worker dedicado no Railway: a latência de entrega do APNs/FCM
 * domina de qualquer forma.
 */
class FireDueTimersCommand extends Command
{
    protected $signature = 'timers:fire-due {--max-time=50 : Segundos máximos a correr} {--sleep=5 : Segundos entre ciclos}';

    protected $description = 'Dispara notificações push dos timers de retrolavagem vencidos';

    /**
     * Um push de timer só vale nos minutos em volta do evento. Passado este
     * atraso, "a lavagem acabou" já não ajuda ninguém e é o aviso que faz a
     * equipa achar que a app está avariada — acontece quando o registo nunca
     * foi gravado, ou quando o container esteve em baixo na altura de disparar.
     * Nesse caso o timer é dado como cancelado, sem notificação.
     */
    public const TOLERANCIA_ATRASO_MIN = 15;

    public function handle(): int
    {
        $deadline = Carbon::now()->addSeconds((int) $this->option('max-time'));
        $sleep = max(1, (int) $this->option('sleep'));

        do {
            $this->dispararVencidos();

            if (Carbon::now()->addSeconds($sleep)->greaterThan($deadline)) {
                break;
            }

            sleep($sleep);
        } while (Carbon::now()->lessThan($deadline));

        return self::SUCCESS;
    }

    private function dispararVencidos(): void
    {
        $agora = Carbon::now();
        $limiteAtraso = $agora->copy()->subMinutes(self::TOLERANCIA_ATRASO_MIN);

        TimerPush::query()
            ->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->where('fire_at', '<=', $agora)
            ->with(['user', 'pool'])
            ->get()
            ->each(function (TimerPush $timer) use ($agora, $limiteAtraso): void {
                if ($timer->fire_at->lessThan($limiteAtraso)) {
                    $timer->update(['cancelled_at' => $agora]);

                    return;
                }

                $timer->user?->notify(
                    new TimerFinishedNotification($timer->fase, $timer->pool?->name)
                );

                $timer->update(['sent_at' => $agora]);
            });
    }
}
