<?php

declare(strict_types=1);

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OfflineSyncController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\TimerPushController;
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
    ->middleware('throttle:10,1');

Route::middleware('auth')->group(function (): void {
    Route::get('/primeiro-acesso', [PasswordChangeController::class, 'show'])
        ->name('password-change.show');
    Route::post('/primeiro-acesso', [PasswordChangeController::class, 'store'])
        ->name('password-change.store')
        ->middleware('throttle:5,1');

    // Web Push: subscrição do dispositivo e timers de retrolavagem.
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])
        ->name('push.subscribe');
    Route::delete('/push/subscribe', [PushSubscriptionController::class, 'destroy'])
        ->name('push.unsubscribe');
    Route::post('/push/timer', [TimerPushController::class, 'store'])
        ->name('push.timer.store');
    Route::delete('/push/timer', [TimerPushController::class, 'destroy'])
        ->name('push.timer.destroy');

    // Sincronização offline de registos diários e ações operacionais.
    Route::post('/offline-sync/daily-records', [OfflineSyncController::class, 'storeDailyRecords'])
        ->name('offline-sync.daily-records');
    Route::post('/offline-sync/operational-actions', [OfflineSyncController::class, 'storeOperationalActions'])
        ->name('offline-sync.operational-actions');
});
