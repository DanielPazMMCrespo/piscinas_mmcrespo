<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\IncidentStatus;
use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\Pool;
use App\Models\StockInstallationLog;
use App\Models\User;
use App\Notifications\ResumoTurnoNotification;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class SendShiftSummaryCommand extends Command
{
    protected $signature = 'notificacoes:resumo-turno';

    protected $description = 'Envia o resumo de fim de turno com estado operacional do dia';

    public function handle(SettingsService $settingsService): int
    {
        $horasConfiguradas = $settingsService->getArray('resumo_turno_horas', ['14:00', '20:00']);
        $horaAtual = now()->format('H:i');

        if (! in_array($horaAtual, $horasConfiguradas)) {
            return self::SUCCESS;
        }

        $date = now()->format('Y-m-d');
        $cacheKey = "resumo_turno_{$date}_{$horaAtual}";

        if (Cache::has($cacheKey)) {
            return self::SUCCESS;
        }

        Cache::put($cacheKey, true, now()->endOfDay());

        $totalPiscinas = Pool::where('active', true)->count();
        $registosHoje = DailyRecord::whereDate('registado_em', today())->whereDoesntHave('correcoes')->count();
        $piscinasComRegisto = DailyRecord::whereDate('registado_em', today())->whereDoesntHave('correcoes')->distinct('pool_id')->count('pool_id');

        $registosHojeRecords = DailyRecord::with('piscina')
            ->whereDate('registado_em', today())
            ->whereDoesntHave('correcoes')
            ->get();

        $violacoes = $registosHojeRecords->filter(fn ($r) => ! empty($r->listarViolacoes()))->count();

        $stockConsumido = StockInstallationLog::where('tipo_movimento', 'consumo')
            ->whereDate('created_at', today())
            ->sum('quantity');

        $incidentesAbertos = Incident::where('status', '!=', IncidentStatus::RESOLVIDO)->count();
        $incidentesResolvidosHoje = Incident::where('status', IncidentStatus::RESOLVIDO)
            ->whereDate('resolvido_em', today())
            ->count();

        $linhas = [];
        $linhas[] = "{$piscinasComRegisto}/{$totalPiscinas} piscinas com registo";
        $linhas[] = "{$registosHoje} registos efetuados";

        if ($violacoes > 0) {
            $linhas[] = "{$violacoes} não-conformidades";
        }
        if ($stockConsumido > 0) {
            $linhas[] = "{$stockConsumido} unid. de químicos consumidos";
        }
        if ($incidentesAbertos > 0) {
            $linhas[] = "{$incidentesAbertos} incidentes abertos";
        }
        if ($incidentesResolvidosHoje > 0) {
            $linhas[] = "{$incidentesResolvidosHoje} incidentes resolvidos hoje";
        }

        $status = ($piscinasComRegisto === $totalPiscinas && $violacoes === 0) ? 'success' : 'warning';

        $destinatarios = User::role([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO])->get();

        if ($destinatarios->isNotEmpty()) {
            Notification::send($destinatarios, new ResumoTurnoNotification($linhas, $horaAtual, $status));
        }

        return self::SUCCESS;
    }
}
