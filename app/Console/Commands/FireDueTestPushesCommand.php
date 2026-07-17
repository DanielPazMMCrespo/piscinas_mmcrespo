<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TestPush;
use App\Notifications\TestPushNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dispara os pushes de teste criados na página Notificações (~5s depois do
 * clique, para testar entrega com a app fechada). Mesmo padrão de polling
 * curto do timers:fire-due — sem worker dedicado.
 */
class FireDueTestPushesCommand extends Command
{
    protected $signature = 'notificacoes:teste-fire-due {--max-time=50 : Segundos máximos a correr} {--sleep=5 : Segundos entre ciclos}';

    protected $description = 'Dispara notificações push de teste vencidas';

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
        TestPush::query()
            ->whereNull('sent_at')
            ->where('fire_at', '<=', Carbon::now())
            ->with('user')
            ->get()
            ->each(function (TestPush $teste): void {
                $teste->user?->notify(new TestPushNotification($teste->tipo, $teste->titulo, $teste->corpo));
                $teste->update(['sent_at' => Carbon::now()]);
            });
    }
}
