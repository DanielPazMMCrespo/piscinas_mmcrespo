<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * Estado de um pedido de acesso de nadador-salvador bloqueado
 * (App\Models\PoolAccessRequest) enquanto a sua piscina está encerrada.
 */
final class PoolAccessRequestStatus
{
    public const PENDENTE = 'pendente';

    public const APROVADO = 'aprovado';

    public const NEGADO = 'negado';

    public const LABELS = [
        self::PENDENTE => 'Pendente',
        self::APROVADO => 'Aprovado',
        self::NEGADO => 'Negado',
    ];

    private function __construct()
    {
        // This class cannot be instantiated
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
