<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AlertState;
use App\Services\AlertasService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AlertHousekeepingCommand extends Command
{
    protected $signature = 'alerts:housekeeping';

    protected $description = 'Prune old alert states (>7 days) and auto-resolve expired alerts, and clean up notifications table';

    public function handle(AlertasService $alertasService): int
    {
        // Um "resolvido" manual cuja condição ainda esteja ativa não pode ser
        // podado — perderia-se a prova de quem tratou o alerta sem a violação
        // ter sido corrigida, e o cartão reapareceria no quadro como pendente
        // do zero (ver CLAUDE.md, "estado preso" BUG-03).
        $ativos = $alertasService->calcular(null)['alertas'];

        $pruned = AlertState::query()
            ->where('moved_at', '<', now()->subDays(7))
            ->get()
            ->reject(fn (AlertState $estado) => $estado->status === 'resolvido' && isset($ativos[$estado->alert_key]))
            ->each(fn (AlertState $estado) => $estado->delete())
            ->count();

        if ($pruned > 0) {
            $this->info("Pruned $pruned old alert states");
        }

        // Poda da tabela notifications do Filament
        $notificacoesLidasRemovidas = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        $notificacoesAntigasRemovidas = DB::table('notifications')
            ->whereNull('read_at')
            ->where('created_at', '<', now()->subDays(60))
            ->delete();

        if ($notificacoesLidasRemovidas > 0 || $notificacoesAntigasRemovidas > 0) {
            $this->info("Podadas {$notificacoesLidasRemovidas} notificações lidas (>30d) e {$notificacoesAntigasRemovidas} não lidas antigas (>60d).");
        }

        return self::SUCCESS;
    }
}
