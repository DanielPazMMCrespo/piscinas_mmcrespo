<?php declare(strict_types=1);
namespace App\Observers;


use App\Models\DailyRecord;
use App\Services\CacheService;

/**
 * Observer para DailyRecord: invalida cache quando registos são criados/atualizados.
 *
 * Triggers:
 * - created: novo registo ou correção
 * - updated: edição de registo
 *
 * Invalida:
 * - Gráficos da piscina (14 dias de histórico)
 * - Alertas (possivelmente conformidade alterada)
 * - Painel de piscinas (valores + status)
 */
class DailyRecordObserver
{
    private CacheService $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    public function created(DailyRecord $record): void
    {
        $this->invalidateCache($record);
    }

    public function updated(DailyRecord $record): void
    {
        $this->invalidateCache($record);
    }

    private function invalidateCache(DailyRecord $record): void
    {
        // Invalida gráficos da piscina afetada.
        $this->cacheService->invalidateGraphCache((int) $record->pool_id);

        // Invalida alertas globais em background (para não bloquear o Request HTTP)
        \App\Jobs\InvalidateAlertsJob::dispatch();

        // Invalida o cache local temporário do utilizador autenticado
        if (auth()->check()) {
            \Illuminate\Support\Facades\Cache::forget('alertas_' . auth()->id());
        }

        // Invalida painel de piscinas (valores atualizados).
        $this->cacheService->invalidatePoolData();
    }
}
