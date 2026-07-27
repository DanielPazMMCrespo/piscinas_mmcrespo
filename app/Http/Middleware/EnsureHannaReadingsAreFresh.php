<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Jobs\ProcessHannaSync;
use App\Models\SensorReading;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHannaReadingsAreFresh
{
    public function __construct(
        private SettingsService $settings
    ) {}

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

        $timeoutMinutos = $this->settings->getInt('sensor_timeout_minutos', 30);

        // Verifica se há alguma leitura mais recente que o timeout configurado.
        if (
            $ultimaLeitura === null
            || abs((int) now()->diffInMinutes($ultimaLeitura)) > $timeoutMinutos
        ) {
            // Leituras ausentes ou stale — sincroniza silenciosamente em background após a resposta.
            try {
                ProcessHannaSync::dispatch()->afterResponse();
            } catch (\Throwable) {
                // Previne crash se falhar o dispatch do job.
            }
        }

        return $next($request);
    }
}
