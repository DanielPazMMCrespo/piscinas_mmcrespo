<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\UserRole;
use App\Models\TapAlert;
use App\Models\User;
use App\Notifications\TorneiraAbertaNotification;
use App\Services\SettingsService;
use App\Support\JanelaSilencio;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa admin+técnico quando uma torneira fica aberta há mais do que o limite
 * configurado (torneira_aberta_horas_aviso). Dispara uma vez por episódio
 * (marca notified_at) — não repete a cada verificação.
 */
class CheckOpenTapsCommand extends Command
{
    protected $signature = 'torneiras:verificar-abertas';

    protected $description = 'Notifica torneiras abertas há mais tempo do que o limite configurado';

    public function handle(SettingsService $settings): int
    {
        $limiteHoras = $settings->getInt('torneira_aberta_horas_aviso', 4);

        if (app(JanelaSilencio::class)->ativa()) {
            return self::SUCCESS;
        }

        $destinatarios = User::role([UserRole::ADMIN, UserRole::TECNICO])->get();
        if ($destinatarios->isEmpty()) {
            return self::SUCCESS;
        }

        TapAlert::query()
            ->whereNull('resolved_at')
            ->whereNull('notified_at')
            ->where('opened_at', '<=', Carbon::now()->subHours($limiteHoras))
            ->with('piscina')
            ->get()
            ->each(function (TapAlert $tap) use ($destinatarios, $limiteHoras): void {
                $nome = $tap->piscina?->nome_completo ?? 'piscina';

                Notification::send($destinatarios, new TorneiraAbertaNotification($tap, $nome, $limiteHoras));

                $tap->update(['notified_at' => Carbon::now()]);
            });

        return self::SUCCESS;
    }
}
