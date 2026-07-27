<?php

declare(strict_types=1);

namespace App\Constants;

final class UserRole
{
    public const ADMIN = 'admin';

    public const GESTOR = 'gestor';

    public const TECNICO = 'tecnico';

    public const NADADOR_SALVADOR = 'nadador_salvador';

    public const INATIVO = 'inativo';

    public const LABELS = [
        self::ADMIN => 'Administrador',
        self::GESTOR => 'Gestor',
        self::TECNICO => 'Técnico',
        self::NADADOR_SALVADOR => 'Nadador-Salvador',
        self::INATIVO => 'Inativo',
    ];

    private function __construct()
    {
        // This class cannot be instantiated
    }

    /**
     * Get all available roles as array.
     */
    public static function all(): array
    {
        return [
            self::ADMIN,
            self::GESTOR,
            self::TECNICO,
            self::NADADOR_SALVADOR,
        ];
    }

    /**
     * Check if a role is valid.
     */
    public static function isValid(string $role): bool
    {
        return in_array($role, self::all(), true);
    }
}
