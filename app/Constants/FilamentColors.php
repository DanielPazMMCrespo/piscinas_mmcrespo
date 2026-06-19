<?php declare(strict_types=1);
namespace App\Constants;

namespace App\Constants;

final class FilamentColors
{
    public const DANGER = 'danger';
    public const WARNING = 'warning';
    public const SUCCESS = 'success';
    public const INFO = 'info';
    public const PRIMARY = 'primary';
    public const GRAY = 'gray';

    private function __construct()
    {
        // This class cannot be instantiated
    }

    /**
     * Get all available colors as array.
     */
    public static function all(): array
    {
        return [
            self::DANGER,
            self::WARNING,
            self::SUCCESS,
            self::INFO,
            self::PRIMARY,
            self::GRAY,
        ];
    }

    /**
     * Map alert level to Filament color.
     */
    public static function fromAlertLevel(string $level): string
    {
        return match ($level) {
            AlertLevel::VERMELHO => self::DANGER,
            AlertLevel::AMARELO => self::WARNING,
            AlertLevel::NEUTRO => self::INFO,
            default => self::GRAY,
        };
    }

    /**
     * Check if a color is valid.
     */
    public static function isValid(string $color): bool
    {
        return in_array($color, self::all(), true);
    }
}
