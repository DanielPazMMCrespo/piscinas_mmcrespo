<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// A app é o painel Filament — a raiz vai direta para lá.
Route::redirect('/', '/admin');

Route::get('/convite/{token}', [\App\Http\Controllers\InvitationController::class, 'show'])
    ->name('invitation.show');

Route::post('/convite/{token}', [\App\Http\Controllers\InvitationController::class, 'store'])
    ->name('invitation.store')
    ->middleware('throttle:10,1');

Route::middleware('auth')->group(function (): void {
    Route::get('/primeiro-acesso', [\App\Http\Controllers\PasswordChangeController::class, 'show'])
        ->name('password-change.show');
    Route::post('/primeiro-acesso', [\App\Http\Controllers\PasswordChangeController::class, 'store'])
        ->name('password-change.store')
        ->middleware('throttle:5,1');

    Route::post('/admin/update-haptic-preference', function (\Illuminate\Http\Request $request) {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $user->update(['haptic_enabled' => $validated['enabled']]);
        return response()->json(['status' => 'success']);
    })->name('admin.update-haptic-preference');
});
