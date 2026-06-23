<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cabecalhos de seguranca globais aplicados a todas as respostas.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Auto-sincroniza sensores Hanna se leitura > 30 min stale.
        $middleware->append(\App\Http\Middleware\EnsureHannaReadingsAreFresh::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
