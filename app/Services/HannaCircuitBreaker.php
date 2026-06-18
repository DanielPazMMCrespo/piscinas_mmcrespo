<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SensorCommunicationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit breaker para a API Hanna Cloud.
 *
 * Implementa a máquina de estados: closed → open → half-open → closed.
 *
 * Estados:
 *   - CLOSED: tudo normal, requisições passam.
 *   - OPEN: depois de 5 falhas em 5 min, rejeita automaticamente; retorna cached data.
 *   - HALF_OPEN: timeout de 1 min passado, deixa passar 1 tentativa (prova).
 *
 * Fallback: se circuit está aberto, retorna o último sensor reading do cache (database).
 */
class HannaCircuitBreaker
{
    private const CACHE_KEY = 'hanna:circuit:state';
    private const FAILURES_KEY = 'hanna:circuit:failures';
    private const LAST_ATTEMPT_KEY = 'hanna:circuit:last_attempt';
    private const OPENED_AT_KEY = 'hanna:circuit:opened_at';

    // Configuração do circuit breaker (segundos, contadores).
    private const FAILURE_THRESHOLD = 5;          // falhas antes de abrir
    private const TIME_WINDOW = 5 * 60;            // 5 minutos
    private const OPEN_TIMEOUT = 60;               // 1 minuto antes de tentar half-open

    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half-open';

    /** @return 'closed'|'open'|'half-open' */
    public static function state(): string
    {
        return Cache::get(self::CACHE_KEY, self::STATE_CLOSED);
    }

    /**
     * Registar uma falha. Se threshold atingido, abre o circuito.
     */
    public static function recordFailure(): void
    {
        $failures = Cache::get(self::FAILURES_KEY, []);
        $now = now()->timestamp;

        // Remove falhas fora da janela de tempo (5 min).
        $failures = array_filter(
            $failures,
            fn (int $ts) => ($now - $ts) < self::TIME_WINDOW
        );
        $failures[] = $now;

        Cache::put(self::FAILURES_KEY, $failures, self::TIME_WINDOW + 60);

        // Se threshold atingido, abre o circuito.
        if (count($failures) >= self::FAILURE_THRESHOLD) {
            self::open();
        }
    }

    /** Registar um sucesso — reseta contador de falhas e fecha o circuito (se half-open). */
    public static function recordSuccess(): void
    {
        if (self::state() === self::STATE_HALF_OPEN) {
            self::close();
            Log::notice('Hanna circuit breaker: fechado (prova bem-sucedida).');
        }
        Cache::delete(self::FAILURES_KEY);
    }

    /**
     * Verifica se uma chamada deve ser permitida.
     * Se open + timeout passado, volta para half-open (deixa passar 1 prova).
     *
     * @return bool True se a chamada deve prosseguir; false se deve usar fallback.
     */
    public static function allow(): bool
    {
        $state = self::state();

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            $openedAt = Cache::get(self::OPENED_AT_KEY);
            if ($openedAt === null) {
                // Circuito acabou de abrir, registar o momento.
                Cache::put(self::OPENED_AT_KEY, now()->timestamp, 300);
                return false; // Nega imediatamente.
            }

            if ((now()->timestamp - $openedAt) >= self::OPEN_TIMEOUT) {
                // Timeout passado, volta para half-open.
                self::halfOpen();
                return true; // Deixa passar a prova.
            }
            return false; // Ainda em open, nega.
        }

        // STATE_HALF_OPEN: deixa passar para provar.
        return true;
    }

    /** Executa uma ação com circuit breaker. */
    public static function execute(callable $fn, callable $fallback): mixed
    {
        if (! self::allow()) {
            // Circuit aberto e timeout não passado — usa fallback.
            Log::warning('Hanna circuit breaker: aberto. Usando fallback.');
            return $fallback();
        }

        Cache::put(self::LAST_ATTEMPT_KEY, now()->timestamp, 120);

        try {
            $result = $fn();
            self::recordSuccess();
            return $result;
        } catch (SensorCommunicationException $e) {
            if ($e->shouldRetry()) {
                self::recordFailure();
            }
            throw $e;
        }
    }

    // ---- Privadas ----

    private static function open(): void
    {
        Cache::put(self::CACHE_KEY, self::STATE_OPEN, 600);
        Log::warning('Hanna circuit breaker: ABERTO (5 falhas em 5 min). Fallback activo.');
    }

    private static function halfOpen(): void
    {
        Cache::put(self::CACHE_KEY, self::STATE_HALF_OPEN, 120);
        Log::notice('Hanna circuit breaker: HALF-OPEN (testando...).', [
            'timeout_passado' => true,
        ]);
    }

    private static function close(): void
    {
        Cache::put(self::CACHE_KEY, self::STATE_CLOSED, 600);
        Cache::delete(self::FAILURES_KEY);
        Cache::delete(self::LAST_ATTEMPT_KEY);
        Cache::delete(self::OPENED_AT_KEY);
    }
}
