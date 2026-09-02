<?php

declare(strict_types=1);

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OfflineSyncController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\PoolAccessController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\TimerPushController;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Support\Facades\Route;

// A app é o painel Filament — a raiz vai direta para lá.
Route::redirect('/', '/admin');

// Prevenção de MethodNotAllowedHttpException no login:
// Quando gestores de palavras-passe (ex.: Bitwarden, 1Password, Chrome Autofill)
// ou utilizadores submetem o formulário antes de o JS do Livewire inicializar,
// é enviado um POST tradicional para /admin/login. Redirecionamos para o GET /admin/login.
Route::match(['POST', 'PUT', 'PATCH', 'DELETE'], '/admin/login', fn () => redirect()->to('/admin/login'));
Route::any('/login', fn () => redirect()->to('/admin/login'));

Route::get('/convite/{token}', [InvitationController::class, 'handleRedirect'])
    ->name('invitation.show');

Route::get('/convite', [InvitationController::class, 'show'])
    ->name('invitation.form');

Route::post('/convite/accept', [InvitationController::class, 'store'])
    ->name('invitation.store')
    ->middleware('throttle:publico');

Route::middleware(['auth', RequirePasswordChange::class])->group(function (): void {
    Route::get('/primeiro-acesso', [PasswordChangeController::class, 'show'])
        ->name('password-change.show');
    Route::post('/primeiro-acesso', [PasswordChangeController::class, 'store'])
        ->name('password-change.store')
        ->middleware('throttle:5,1');

    Route::get('/piscinas-encerradas', [PoolAccessController::class, 'show'])
        ->name('pool-access.show');
    Route::post('/piscinas-encerradas/pedir-acesso', [PoolAccessController::class, 'store'])
        ->name('pool-access.store')
        ->middleware('throttle:5,1');

    // Web Push: subscrição do dispositivo e timers de retrolavagem.
    //
    // 60/min e não 5/min: a app chama estes endpoints sozinha, muito mais do
    // que um humano. O push.js sincroniza a subscrição a cada `window load`, e
    // este painel não tem SPA mode, logo é uma chamada por página visitada.
    // O timer chama em iniciar, parar e reiniciar. Com 5/min um técnico a
    // passar pela sidebar ou a mexer no cronómetro levava 429 — e as duas
    // chamadas acabam em `.catch(() => {})`, logo a recusa era invisível: não
    // recebia o aviso do fim da lavagem e nunca sabia porquê.
    // O throttle do Laravel já é por utilizador, logo 60 não abre nada.
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])
        ->name('push.subscribe')
        ->middleware('throttle:60,1');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroyAll'])
        ->name('push.unsubscribe.all')
        ->middleware('throttle:60,1');
    Route::post('/push/timer', [TimerPushController::class, 'store'])
        ->name('push.timer.store')
        ->middleware('throttle:60,1');
    Route::delete('/push/timer', [TimerPushController::class, 'destroy'])
        ->name('push.timer.destroy')
        ->middleware('throttle:60,1');

    // Sincronização offline: um dispositivo que esteve sem rede pode ter vários
    // lotes em fila e envia-os seguidos.
    Route::post('/offline-sync/daily-records', [OfflineSyncController::class, 'storeDailyRecords'])
        ->name('offline-sync.daily-records')
        ->middleware('throttle:20,1');
    Route::post('/offline-sync/operational-actions', [OfflineSyncController::class, 'storeOperationalActions'])
        ->name('offline-sync.operational-actions')
        ->middleware('throttle:20,1');
});
