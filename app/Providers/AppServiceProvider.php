<?php declare(strict_types=1);
namespace App\Providers;

namespace App\Providers;

use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\StockInstallation;
use App\Observers\DailyRecordObserver;
use App\Observers\IncidentObserver;
use App\Observers\StockInstallationObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registra observers para invalidação automática de cache.
        DailyRecord::observe(DailyRecordObserver::class);
        StockInstallation::observe(StockInstallationObserver::class);
        Incident::observe(IncidentObserver::class);
    }
}
