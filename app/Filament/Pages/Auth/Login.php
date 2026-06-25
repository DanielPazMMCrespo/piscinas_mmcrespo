<?php declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $data     = $this->form->getState();
        $email    = $data['email'] ?? '';
        $password = (string) ($data['password'] ?? '');

        // Auto-detect PIN: input is 4–6 digits only
        $isPinAttempt = ctype_digit($password)
            && strlen($password) >= 4
            && strlen($password) <= 6;

        $throttleKey = 'login_pin:' . strtolower($email) . '|' . request()->ip();

        if ($isPinAttempt) {
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                $seconds = RateLimiter::availableIn($throttleKey);

                Log::warning('PIN login blocked due to rate limiting.', [
                    'email' => $email,
                    'ip' => request()->ip(),
                    'seconds_remaining' => $seconds,
                ]);

                if (function_exists('\Sentry\captureMessage')) {
                    \Sentry\captureMessage('PIN login blocked due to rate limiting for email: ' . $email);
                }

                throw ValidationException::withMessages([
                    'data.password' => 'Demasiadas tentativas de PIN. Por favor tente novamente em ' . $seconds . ' segundos.',
                ]);
            }
        }

        $this->rateLimit(5);

        $user = User::where('email', $email)->first();

        if ($user) {
            if ($isPinAttempt && $user->pin && Hash::check($password, $user->pin)) {
                if ($isPinAttempt) {
                    RateLimiter::clear($throttleKey);
                }
                $this->clearRateLimiter();
                Auth::login($user, $data['remember'] ?? false);

                return app(LoginResponse::class);
            }

            // Standard password authentication
            if ($user->password && Hash::check($password, $user->password)) {
                if ($isPinAttempt) {
                    RateLimiter::clear($throttleKey);
                }
                $this->clearRateLimiter();
                Auth::login($user, $data['remember'] ?? false);

                return app(LoginResponse::class);
            }
        }

        if ($isPinAttempt) {
            RateLimiter::hit($throttleKey, 60);

            $attemptsLeft = RateLimiter::remaining($throttleKey, 5);

            Log::warning('Failed PIN login attempt.', [
                'email' => $email,
                'ip' => request()->ip(),
                'attempts_left' => $attemptsLeft,
            ]);

            if (function_exists('\Sentry\captureMessage')) {
                \Sentry\captureMessage('Failed PIN login attempt for email: ' . $email);
            }
        }

        $this->hitRateLimiter();

        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
