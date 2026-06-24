<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway (e qualquer reverse proxy) envia X-Forwarded-Proto: https.
        // Sem isto, o Laravel gera URLs http:// e o browser bloqueia como mixed content.
        $middleware->trustProxies(at: '*');
        // Valida o tamanho dos uploads (máx 5MB) server-side.
        $middleware->append(\App\Http\Middleware\ValidateUploadSize::class);
        // Cabecalhos de seguranca globais aplicados a todas as respostas.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Auto-sincroniza sensores Hanna se leitura > 30 min stale.
        $middleware->append(\App\Http\Middleware\EnsureHannaReadingsAreFresh::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $e): void {
            if (! app()->environment('production')) {
                return;
            }
            // Ignora erros esperados (404, 403, validação, auth)
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
            ) {
                return;
            }
            // Rate limit: 1 email por erro único a cada 10 minutos
            $key = 'alert_err_'.md5(get_class($e).$e->getFile().$e->getLine());
            if (\Illuminate\Support\Facades\Cache::has($key)) {
                return;
            }
            \Illuminate\Support\Facades\Cache::put($key, true, now()->addMinutes(10));

            $to      = (string) env('LOG_ALERT_EMAIL', 'daniel.paz@mmcrespo.pt');
            $subject = '[MMCrespo] Erro crítico: '.class_basename($e);
            $body    = implode("\n", [
                'Ambiente: '.app()->environment(),
                'URL: '.request()->fullUrl(),
                'Erro: '.get_class($e),
                'Mensagem: '.$e->getMessage(),
                'Ficheiro: '.$e->getFile().':'.$e->getLine(),
                '',
                'Stack Trace:',
                $e->getTraceAsString(),
            ]);

            try {
                \Illuminate\Support\Facades\Mail::raw($body, static function ($msg) use ($to, $subject): void {
                    $msg->to($to)->subject($subject);
                });
            } catch (\Throwable) {
                // Não propaga falha de mail
            }
        });
    })->create();
