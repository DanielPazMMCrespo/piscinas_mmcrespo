<?php declare(strict_types=1);
namespace App\Constants;

namespace App\Constants;

final class AlertType
{
    public const SEM_REGISTO = 'sem_registo';
    public const FORA_LIMITES = 'fora_limites';
    public const TEMPERATURA = 'temp';
    public const TORNEIRA = 'tap';
    public const INCIDENTE = 'incidente';
    public const STOCK = 'stock';

    private function __construct()
    {
        // This class cannot be instantiated
    }

    /**
     * Get all available alert types as array.
     */
    public static function all(): array
    {
        return [
            self::SEM_REGISTO,
            self::FORA_LIMITES,
            self::TEMPERATURA,
            self::TORNEIRA,
            self::INCIDENTE,
            self::STOCK,
        ];
    }

    /**
     * Check if a type is valid.
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
