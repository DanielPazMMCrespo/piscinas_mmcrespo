<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\StockInstallationLog;
use App\Models\User;
use App\Notifications\ComparacaoSemanalNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class SendWeeklyComparisonCommand extends Command
{
    protected $signature = 'notificacoes:comparacao-semanal';

    protected $description = 'Envia comparação semanal de conformidade (segunda-feira)';

    public function handle(): int
    {
        // Passou de domingo para segunda: ao domingo a janela de silêncio cobre
        // o dia inteiro e o aviso nunca chegaria ao dispositivo. Como agora
        // corre já com a semana fechada, as duas janelas são semanas completas
        // em vez de "a semana a meio" contra "a semana passada".
        if (! now()->isMonday()) {
            return self::SUCCESS;
        }

        $weekNumber = now()->weekOfYear;
        $cacheKey = "comparacao_semanal_{$weekNumber}";

        if (Cache::has($cacheKey)) {
            return self::SUCCESS;
        }

        Cache::put($cacheKey, true, 86400 * 7);

        $thisWeekStart = now()->subWeek()->startOfWeek();
        $thisWeekEnd = now()->startOfWeek();

        $lastWeekStart = now()->subWeeks(2)->startOfWeek();
        $lastWeekEnd = now()->subWeek()->startOfWeek();

        $linhas = [];
        $pools = Pool::operacionais()->get();

        foreach ($pools as $pool) {
            $thisWeekRecords = DailyRecord::where('pool_id', $pool->id)
                ->whereBetween('registado_em', [$thisWeekStart, $thisWeekEnd])
                ->whereDoesntHave('correcoes')
                ->get();

            $lastWeekRecords = DailyRecord::where('pool_id', $pool->id)
                ->whereBetween('registado_em', [$lastWeekStart, $lastWeekEnd])
                ->whereDoesntHave('correcoes')
                ->get();

            $avgPhThis = round((float) $thisWeekRecords->avg('ph_efetivo') ?: 0, 2);
            $avgPhLast = round((float) $lastWeekRecords->avg('ph_efetivo') ?: 0, 2);

            $avgClThis = round((float) $thisWeekRecords->avg('cloro_livre_efetivo') ?: 0, 2);
            $avgClLast = round((float) $lastWeekRecords->avg('cloro_livre_efetivo') ?: 0, 2);

            $violationsThis = $thisWeekRecords->filter(fn ($r) => ! empty($r->listarViolacoes()))->count();
            $violationsLast = $lastWeekRecords->filter(fn ($r) => ! empty($r->listarViolacoes()))->count();

            $phArrow = $avgPhThis === $avgPhLast ? '→' : ($avgPhThis > $avgPhLast ? '↑' : '↓');
            $clArrow = $avgClThis === $avgClLast ? '→' : ($avgClThis > $avgClLast ? '↑' : '↓');

            $linhas[] = "{$pool->nome_completo}: pH {$avgPhThis}→{$avgPhLast} {$phArrow} | Cl {$avgClThis}→{$avgClLast} {$clArrow} | {$violationsThis} violações (vs {$violationsLast})";
        }

        $stockThisWeek = StockInstallationLog::where('tipo_movimento', 'consumo')
            ->whereBetween('created_at', [$thisWeekStart, $thisWeekEnd])
            ->sum('quantity');

        $stockLastWeek = StockInstallationLog::where('tipo_movimento', 'consumo')
            ->whereBetween('created_at', [$lastWeekStart, $lastWeekEnd])
            ->sum('quantity');

        $linhas[] = "Consumo total de químicos: {$stockThisWeek} (vs {$stockLastWeek})";

        $destinatarios = User::role([UserRole::ADMIN, UserRole::GESTOR])->get();

        if ($destinatarios->isNotEmpty()) {
            Notification::send($destinatarios, new ComparacaoSemanalNotification($linhas));
        }

        return self::SUCCESS;
    }
}
