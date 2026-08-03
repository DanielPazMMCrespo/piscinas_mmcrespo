<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * Motivos de encerramento temporário de uma piscina (PoolClosure).
 *
 * Não confundir com pools.active: 'active' é estrutural (a piscina existe ou
 * não na operação), o encerramento é temporal e datado.
 */
final class MotivoEncerramento
{
    public const EPOCA_BALNEAR = 'epoca_balnear';

    public const MANUTENCAO = 'manutencao';

    public const OBRA = 'obra';

    public const AVARIA = 'avaria';

    public const ORDEM_AUTORIDADE = 'ordem_autoridade';

    public const OUTRO = 'outro';

    private function __construct()
    {
        // This class cannot be instantiated
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::EPOCA_BALNEAR,
            self::MANUTENCAO,
            self::OBRA,
            self::AVARIA,
            self::ORDEM_AUTORIDADE,
            self::OUTRO,
        ];
    }

    public static function isValid(string $motivo): bool
    {
        return in_array($motivo, self::all(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::EPOCA_BALNEAR => 'Fim de época balnear',
            self::MANUTENCAO => 'Manutenção',
            self::OBRA => 'Obra',
            self::AVARIA => 'Avaria',
            self::ORDEM_AUTORIDADE => 'Ordem da autoridade de saúde',
            self::OUTRO => 'Outro',
        ];
    }

    public static function label(?string $motivo): string
    {
        if ($motivo === null) {
            return 'Sem motivo';
        }

        return self::labels()[$motivo] ?? $motivo;
    }
}
