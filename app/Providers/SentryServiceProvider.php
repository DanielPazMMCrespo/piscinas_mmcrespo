<?php declare(strict_types=1);
namespace App\Providers;


use Illuminate\Support\ServiceProvider;
use Sentry\Laravel\Integration;

class SentryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (!$this->app['config']->get('sentry.dsn')) {
            return;
        }

        Integration::handles(
            exceptionHandler: true,
            errorHandler: true,
            fatalErrorHandler: true,
        );
    }
}
