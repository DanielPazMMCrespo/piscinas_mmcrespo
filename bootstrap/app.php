<?php

use App\Http\Middleware\EnsureHannaReadingsAreFresh;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateUploadSize;
use App\Jobs\SendErrorEmailJob;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        // O middleware `auth` do Laravel manda os visitantes para route('login'),
        // e neste projeto essa rota não existe: o painel usa
        // filament.admin.auth.login e o atalho /login é anónimo. Sem isto,
        // qualquer visita sem sessão a /primeiro-acesso ou /piscinas-encerradas
        // rebentava com RouteNotFoundException, ou seja 500 — era o que o
        // nadador-salvador com a sessão morta recebia, em vez do login.
        $middleware->redirectGuestsTo('/admin/login');
        // Valida o tamanho dos uploads (máx 5MB) server-side.
        $middleware->append(ValidateUploadSize::class);
        // Cabecalhos de seguranca globais aplicados a todas as respostas.
        $middleware->append(SecurityHeaders::class);
        // Auto-sincroniza sensores Hanna se leitura > 30 min stale.
        $middleware->append(EnsureHannaReadingsAreFresh::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $e): void {
            if (! app()->environment('production')) {
                return;
            }
            // Ignora erros esperados (404, 403, validação, auth)
            if ($e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof HttpException
            ) {
                return;
            }
            // Rate limit: 1 email por erro único a cada 10 minutos
            $key = 'alert_err_'.md5(get_class($e).$e->getFile().$e->getLine());
            if (Cache::has($key)) {
                return;
            }
            Cache::put($key, true, now()->addMinutes(10));

            $to = (string) env('LOG_ALERT_EMAIL', 'daniel.paz@mmcrespo.pt');
            $subject = '[MMCrespo] Erro crítico: '.class_basename($e);
            $body = implode("\n", [
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
                SendErrorEmailJob::dispatch($to, $subject, $body);
            } catch (Throwable) {
                // Não propaga falha ao despachar
            }
        });
    })->create();
