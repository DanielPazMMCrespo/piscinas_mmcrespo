<?php declare(strict_types=1);
namespace App\Providers;


use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\StockInstallation;
use App\Observers\DailyRecordObserver;
use App\Observers\IncidentObserver;
use App\Observers\StockInstallationObserver;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            \Filament\Http\Responses\Auth\Contracts\LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Gera um nonce CSP por-pedido; o @vite injeta-o nos <script>/<link> automaticamente.
        // O middleware SecurityHeaders lê este mesmo nonce (Vite::cspNonce()) para a política
        // Content-Security-Policy-Report-Only nonce-based.
        Vite::useCspNonce();

        // Registra observers para invalidação automática de cache.
        DailyRecord::observe(DailyRecordObserver::class);
        StockInstallation::observe(StockInstallationObserver::class);
        Incident::observe(IncidentObserver::class);
    }
}
