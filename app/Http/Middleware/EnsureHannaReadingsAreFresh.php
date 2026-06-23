<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SensorReading;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

class EnsureHannaReadingsAreFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        // Apenas em GET requests (não POST/PUT/DELETE).
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        try {
            // Verifica se há alguma leitura mais recente que 30 minutos.
            $ultimaLeitura = SensorReading::latest('lida_em')->first()?->lida_em;
        } catch (\Throwable) {
            return $next($request);
        }

        if (
            $ultimaLeitura === null
            || now()->diffInMinutes($ultimaLeitura) > 30
        ) {
            // Leituras ausentes ou stale — sincroniza silenciosamente.
            // Timeout 10s para não bloquear a request se a rede falhar.
            try {
                set_time_limit(15);
                Artisan::call('hanna:sync', [], null);
            } catch (\Exception) {
                // Silent fail — continua mesmo que sync falhe.
                // (credenciais ausentes, rede down, etc.)
            }
        }

        return $next($request);
    }
}
