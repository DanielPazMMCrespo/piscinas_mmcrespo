<?php

declare(strict_types=1);

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
}
