<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Lançada quando datas/períodos fornecidos são inválidos.
 *
 * Exemplos:
 * - Data fim < data início
 * - Data no futuro quando só passado é permitido
 * - Período > 1 ano (limites de relatório)
 * - Data inválida (formato incorreto)
 *
 * Contexto debug: `field`, `value`, `constraint` (ex: "end_date < start_date").
 */
class InvalidDateException extends \Exception
{
    public function __construct(
        public readonly string $field,
        public readonly ?string $value = null,
        public readonly string $constraint = 'data inválida',
    ) {
        $msg = "Data inválida em '{$this->field}': {$this->constraint}";
        if ($this->value) {
            $msg .= " (valor: '{$this->value}')";
        }
        parent::__construct($msg);
    }

    /** Mensagem amigável para o UI. */
    public function friendlyMessage(): string
    {
        return "O campo '{$this->field}' contém uma data inválida. "
            ."Constraint: {$this->constraint}.";
    }
}
