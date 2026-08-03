<?php

declare(strict_types=1);

namespace App\Constants;

final class IncidentType
{
    public const AVARIA_EQUIPAMENTO = 'avaria_equipamento';

    public const FUGA_AGUA = 'fuga_agua';

    public const QUALIDADE_AGUA = 'qualidade_agua';

    public const OUTRO = 'outro';

    private function __construct()
    {
        // This class cannot be instantiated
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::AVARIA_EQUIPAMENTO => 'Avaria de Equipamento',
            self::FUGA_AGUA => 'Fuga de Água',
            self::QUALIDADE_AGUA => 'Problema na Qualidade da Água',
            self::OUTRO => 'Outro',
        ];
    }

    public static function label(?string $type): string
    {
        return self::options()[$type] ?? ucfirst(str_replace('_', ' ', $type ?? ''));
    }
}
