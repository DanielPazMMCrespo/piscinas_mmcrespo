<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Listeners\LogUserAuthentication;
use App\Models\DailyRecord;
use App\Models\DosingContainerLog;
use App\Models\Incident;
use App\Models\OperationalAction;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\StockWarehouseLog;
use App\Observers\DailyRecordObserver;
use App\Observers\IncidentObserver;
use App\Observers\MovimentoStockObserver;
use App\Observers\OperationalActionObserver;
use App\Observers\StockInstallationObserver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

        // Espelha os movimentos de stock/bidões no activity_log, para a página
        // de auditoria ser a linha do tempo completa.
        StockWarehouseLog::observe(MovimentoStockObserver::class);
        StockInstallationLog::observe(MovimentoStockObserver::class);
        DosingContainerLog::observe(MovimentoStockObserver::class);

        Event::listen(Login::class, [LogUserAuthentication::class, 'handleLogin']);
        Event::listen(Logout::class, [LogUserAuthentication::class, 'handleLogout']);
        Event::listen(Failed::class, [LogUserAuthentication::class, 'handleFailed']);
        Event::listen(Lockout::class, [LogUserAuthentication::class, 'handleLockout']);
        Event::listen(PasswordReset::class, [LogUserAuthentication::class, 'handlePasswordReset']);

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

        // Limitador para rotas públicas, com chave em REMOTE_ADDR (não request()->ip(),
        // que confia em X-Forwarded-For e é contornável).
        RateLimiter::for('publico', function (Request $request) {
            return Limit::perMinute(30)->by((string) $request->server('REMOTE_ADDR'));
        });
    }
}
