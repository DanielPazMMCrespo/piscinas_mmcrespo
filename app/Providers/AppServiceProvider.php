<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Listeners\LogUserAuthentication;
use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\OperationalAction;
use App\Models\StockInstallation;
use App\Observers\DailyRecordObserver;
use App\Observers\IncidentObserver;
use App\Observers\OperationalActionObserver;
use App\Observers\StockInstallationObserver;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\WebPush\Events\NotificationFailed;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            \Filament\Http\Responses\Auth\Contracts\LoginResponse::class,
            LoginResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') !== 'local') {
            URL::forceScheme('https');
        }

        // Gera um nonce CSP por-pedido; o @vite injeta-o nos <script>/<link> automaticamente.
        // O middleware SecurityHeaders lê este mesmo nonce (Vite::cspNonce()) para a política
        // Content-Security-Policy-Report-Only nonce-based.
        Vite::useCspNonce();

        // Registra observers para invalidação automática de cache.
        DailyRecord::observe(DailyRecordObserver::class);
        StockInstallation::observe(StockInstallationObserver::class);
        Incident::observe(IncidentObserver::class);
        OperationalAction::observe(OperationalActionObserver::class);

        Event::listen(Login::class, [LogUserAuthentication::class, 'handleLogin']);
        Event::listen(Logout::class, [LogUserAuthentication::class, 'handleLogout']);

        // Sem isto, uma falha de envio WebPush (endpoint inválido, encoding
        // errado, etc.) não deixava rasto nenhum — nem log, nem admin visível.
        Event::listen(NotificationFailed::class, function (NotificationFailed $event): void {
            Log::warning('webpush_send_failed', [
                'subscribable_type' => $event->subscription->subscribable_type,
                'subscribable_id' => $event->subscription->subscribable_id,
                'endpoint' => $event->report->getEndpoint(),
                'reason' => $event->report->getReason(),
                'status_code' => $event->report->getResponse()?->getStatusCode(),
            ]);
        });
    }
}
