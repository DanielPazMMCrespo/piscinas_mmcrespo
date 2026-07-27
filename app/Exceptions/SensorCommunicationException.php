<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Lançada quando a comunicação com a API Hanna Cloud falha.
 *
 * Causas comuns: timeout, 5xx, rede indisponível, credenciais expiradas.
 * O circuit breaker deve abrir após 5 falhas em 5 minutos.
 * Fallback: retorna último reading cached; UI mostra "dados indisponíveis".
 *
 * Contexto debug: `device_id`, `attempt`, `status_code`, `original_error`.
 */
class SensorCommunicationException extends \Exception
{
    public function __construct(
        public readonly string $deviceId,
        public readonly int $attempt = 1,
        public readonly ?int $statusCode = null,
        public readonly ?\Throwable $originalError = null,
    ) {
        $msg = "Sensor {$this->deviceId} indisponível (tentativa {$this->attempt})";
        if ($this->statusCode) {
            $msg .= " — HTTP {$this->statusCode}";
        }
        if ($this->originalError?->getMessage()) {
            $msg .= " — {$this->originalError->getMessage()}";
        }
        parent::__construct($msg);
    }

    /** Mensagem amigável para o UI. */
    public function friendlyMessage(): string
    {
        return 'Leitura de sensores indisponível. '
            .'A app continua funcional — mostra dados guardados anteriormente.';
    }

    /** Booleano: deve triggerar retry automático? */
    public function shouldRetry(): bool
    {
        // Retry em falhas de rede (sem status code) e 5xx.
        return $this->statusCode === null || $this->statusCode >= 500;
    }
}
