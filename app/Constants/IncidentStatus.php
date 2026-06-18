<?php

declare(strict_types=1);

namespace App\Constants;

final class IncidentStatus
{
    public const ABERTO = 'aberto';
    public const RESOLVIDO = 'resolvido';

    private function __construct()
    {
        // This class cannot be instantiated
    }

    /**
     * Get all available statuses as array.
     */
    public static function all(): array
    {
        return [
            self::ABERTO,
            self::RESOLVIDO,
        ];
    }

    /**
     * Check if a status is valid.
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
