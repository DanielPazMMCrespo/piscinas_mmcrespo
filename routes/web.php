<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// A app é o painel Filament — a raiz vai direta para lá.
Route::redirect('/', '/admin');

// Prevenção de MethodNotAllowedHttpException no login:
// Quando gestores de palavras-passe (ex.: Bitwarden, 1Password, Chrome Autofill)
// ou utilizadores submetem o formulário antes de o JS do Livewire inicializar,
// é enviado um POST tradicional para /admin/login. Redirecionamos para o GET /admin/login.
Route::match(['POST', 'PUT', 'PATCH', 'DELETE'], '/admin/login', fn () => redirect()->to('/admin/login'));
Route::any('/login', fn () => redirect()->to('/admin/login'));

Route::get('/convite/{token}', [\App\Http\Controllers\InvitationController::class, 'handleRedirect'])
    ->name('invitation.show');

Route::get('/convite', [\App\Http\Controllers\InvitationController::class, 'show'])
    ->name('invitation.form');

Route::post('/convite/accept', [\App\Http\Controllers\InvitationController::class, 'store'])
    ->name('invitation.store')
    ->middleware('throttle:10,1');

Route::middleware('auth')->group(function (): void {
    Route::get('/primeiro-acesso', [\App\Http\Controllers\PasswordChangeController::class, 'show'])
        ->name('password-change.show');
    Route::post('/primeiro-acesso', [\App\Http\Controllers\PasswordChangeController::class, 'store'])
        ->name('password-change.store')
        ->middleware('throttle:5,1');

    // Web Push: subscrição do dispositivo e timers de retrolavagem.
    Route::post('/push/subscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'store'])
        ->name('push.subscribe');
    Route::delete('/push/subscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'destroy'])
        ->name('push.unsubscribe');
    Route::post('/push/timer', [\App\Http\Controllers\TimerPushController::class, 'store'])
        ->name('push.timer.store');
    Route::delete('/push/timer', [\App\Http\Controllers\TimerPushController::class, 'destroy'])
        ->name('push.timer.destroy');
});
