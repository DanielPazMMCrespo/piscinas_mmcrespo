<?php declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $this->rateLimit(5);

        $data     = $this->form->getState();
        $email    = $data['email'];
        $password = (string) ($data['password'] ?? '');

        $user = User::where('email', $email)->first();

        if ($user) {
            // Auto-detect PIN: input is 4–6 digits only
            $isPinAttempt = ctype_digit($password)
                && strlen($password) >= 4
                && strlen($password) <= 6;

            if ($isPinAttempt && $user->pin && Hash::check($password, $user->pin)) {
                $this->clearRateLimiter();
                Auth::login($user, $data['remember'] ?? false);

                return app(LoginResponse::class);
            }

            // Standard password authentication
            if ($user->password && Hash::check($password, $user->password)) {
                $this->clearRateLimiter();
                Auth::login($user, $data['remember'] ?? false);

                return app(LoginResponse::class);
            }
        }

        $this->hitRateLimiter();

        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
