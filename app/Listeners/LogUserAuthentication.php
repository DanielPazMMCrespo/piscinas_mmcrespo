<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class LogUserAuthentication
{
    /**
     * Handle the Login event.
     */
    public function handleLogin(Login $event): void
    {
        if ($event->user) {
            activity('auth')
                ->causedBy($event->user)
                ->log('Iniciou sessão no sistema.');
        }
    }

    /**
     * Handle the Logout event.
     */
    public function handleLogout(Logout $event): void
    {
        if ($event->user) {
            activity('auth')
                ->causedBy($event->user)
                ->log('Terminou sessão no sistema.');
        }
    }
}
