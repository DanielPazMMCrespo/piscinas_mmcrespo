<?php declare(strict_types=1);
namespace App\Observers;


use App\Models\StockInstallation;
use App\Services\CacheService;

/**
 * Observer para StockInstallation: invalida cache quando stock muda.
 *
 * Triggers:
 * - created/updated/deleted: mudança de quantidade ou limite_minimo
 *
 * Invalida:
 * - Alertas (alerta de stock baixo pode ter mudado)
 * - Painel de piscinas (dados relacionados)
 */
class StockInstallationObserver
{
    private CacheService $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    public function created(StockInstallation $stock): void
    {
        $this->invalidateCache();
    }

    public function updated(StockInstallation $stock): void
    {
        $this->invalidateCache();
    }

    public function deleted(StockInstallation $stock): void
    {
        $this->invalidateCache();
    }

    private function invalidateCache(): void
    {
        // Stock baixo afeta os alertas.
        $this->cacheService->invalidateAllAlerts();

        // Painel pode mostrar status de stock.
        $this->cacheService->invalidatePoolData();
    }
}
