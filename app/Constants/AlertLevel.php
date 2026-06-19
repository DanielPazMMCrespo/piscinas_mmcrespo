<?php declare(strict_types=1);
namespace App\Constants;


final class AlertLevel
{
    public const VERMELHO = 'vermelho';
    public const AMARELO = 'amarelo';
    public const NEUTRO = 'neutro';

    private function __construct()
    {
        // This class cannot be instantiated
    }

    /**
     * Get all available alert levels as array.
     */
    public static function all(): array
    {
        return [
            self::VERMELHO,
            self::AMARELO,
            self::NEUTRO,
        ];
    }

    /**
     * Get priority weight for sorting (lower = higher priority).
     */
    public static function weight(string $level): int
    {
        return match ($level) {
            self::VERMELHO => 0,
            self::AMARELO => 1,
            self::NEUTRO => 2,
            default => 99,
        };
    }

    /**
     * Check if a level is valid.
     */
    public static function isValid(string $level): bool
    {
        return in_array($level, self::all(), true);
    }
}
