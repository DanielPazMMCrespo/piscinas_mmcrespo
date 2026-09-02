<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AlertState;
use App\Services\AlertasService;
use Illuminate\Console\Command;

class AlertHousekeepingCommand extends Command
{
    protected $signature = 'alerts:housekeeping';

    protected $description = 'Prune old alert states (>7 days) and auto-resolve expired alerts';

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

        return self::SUCCESS;
    }
}
