<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Jobs\ProcessHannaSync;
use App\Models\SensorReading;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureHannaReadingsAreFresh
{
    /**
     * Janela mínima entre dois dispatches do sync — sem isto, um GET anónimo
     * repetido (ex.: flood a "/" ou "/admin/login") despacha um job por pedido
     * enquanto as leituras estiverem stale, enchendo a fila.
     */
    private const COOLDOWN_SEGUNDOS = 300;

    public function __construct(
        private SettingsService $settings
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Apenas em GET requests (não POST/PUT/DELETE) de utilizadores autenticados.
        if (! $request->isMethod('GET') || ! auth()->check()) {
            return $next($request);
        }

        try {
            // Verifica se há alguma leitura mais recente que 30 minutos.
            $ultimaLeitura = SensorReading::latest('lida_em')->first()?->lida_em;
        } catch (\Throwable) {
            return $next($request);
        }

        $timeoutMinutos = $this->settings->getInt('sensor_timeout_minutos', 30);

        $stale = $ultimaLeitura === null
            || abs((int) now()->diffInMinutes($ultimaLeitura)) > $timeoutMinutos;

        // Cache::add() só grava (e devolve true) se a chave ainda não existir —
        // garante um único dispatch por janela mesmo com pedidos concorrentes.
        if ($stale && Cache::add('hanna_sync_dispatch_cooldown', true, self::COOLDOWN_SEGUNDOS)) {
            try {
                ProcessHannaSync::dispatch()->afterResponse();
            } catch (\Throwable) {
                // Previne crash se falhar o dispatch do job.
            }
        }

        return $next($request);
    }
}
