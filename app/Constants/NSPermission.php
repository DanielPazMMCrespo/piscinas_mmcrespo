<?php declare(strict_types=1);
namespace App\Constants;

/**
 * Secções da app que podem ser individualmente ligadas/desligadas por
 * utilizador Nadador-Salvador (UserResource). Outros cargos não são afetados.
 */
final class NSPermission
{
    public const REGISTO_DIARIO = 'registo_diario';
    public const INCIDENTES = 'incidentes';
    public const ANALISE_PARAMETROS = 'analise_parametros';

    private function __construct()
    {
        // This class cannot be instantiated
    }

    public static function all(): array
    {
        return [
            self::REGISTO_DIARIO,
            self::INCIDENTES,
            self::ANALISE_PARAMETROS,
        ];
    }

    public static function labels(): array
    {
        return [
            self::REGISTO_DIARIO => 'Registo Diário',
            self::INCIDENTES => 'Incidentes',
            self::ANALISE_PARAMETROS => 'Análise de Parâmetros',
        ];
    }
}
