<?php declare(strict_types=1);
namespace App\Constants;

namespace App\Constants;

final class UserRole
{
    public const ADMIN = 'admin';
    public const TECNICO = 'tecnico';
    public const NADADOR_SALVADOR = 'nadador_salvador';

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
