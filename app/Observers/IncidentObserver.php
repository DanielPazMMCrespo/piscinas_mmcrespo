<?php declare(strict_types=1);
namespace App\Observers;


use App\Models\Incident;
use App\Services\CacheService;

/**
 * Observer para Incident: invalida cache quando incidentes mudam.
 *
 * Triggers:
 * - created/updated: novo incidente ou status alterado
 *
 * Invalida:
 * - Alertas (lista de incidentes pode ter mudado ou resolvido automático)
 */
class IncidentObserver
{
    private CacheService $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    public function created(Incident $incident): void
    {
        // Novo incidente afeta a lista de alertas.
        $this->cacheService->invalidateAllAlerts();
    }

    public function updated(Incident $incident): void
    {
        // Mudança de status (ex: resolvido) afeta alertas.
        $this->cacheService->invalidateAllAlerts();
    }
}
