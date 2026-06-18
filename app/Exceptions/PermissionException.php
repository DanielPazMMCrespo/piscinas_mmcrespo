<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Lançada quando um utilizador tenta aceder a um recurso sem permissão.
 *
 * Nota: Esta exception é complementar ao middleware Filament;
 * serve para lógica de negócio que envolve autorização adicional.
 *
 * Contexto debug: `user_id`, `action`, `resource`, `required_role`.
 */
class PermissionException extends \Exception
{
    public function __construct(
        public readonly int $userId,
        public readonly string $action,
        public readonly string $resource,
        public readonly ?string $requiredRole = null,
    ) {
        $msg = "Utilizador #{$this->userId} não autorizado para '{$this->action}' em '{$this->resource}'";
        if ($this->requiredRole) {
            $msg .= " (requer: {$this->requiredRole})";
        }
        parent::__construct($msg);
    }

    /** Mensagem amigável para o UI. */
    public function friendlyMessage(): string
    {
        return "Não tem permissão para realizar esta ação. "
            ."Contacte o administrador se achar que isto é um erro.";
    }
}
