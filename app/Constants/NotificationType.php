<?php declare(strict_types=1);
namespace App\Constants;

/**
 * Tipos de alerta automático que podem ser individualmente ligados/desligados
 * por utilizador (UserResource) — controla o sino e o push do telemóvel.
 * Não cobre avisos manuais (CustomBroadcastNotification) nem feedback pessoal
 * (TimerFinishedNotification), que não são "tipos" que se possam desligar.
 */
final class NotificationType
{
    public const NAO_CONFORMIDADE = 'nao_conformidade';
    public const TORNEIRA_ABERTA = 'torneira_aberta';
    public const INCIDENTE = 'incidente';
    public const STOCK_BAIXO = 'stock_baixo';
    public const RESUMO_DIARIO = 'resumo_diario';
    public const HANNA = 'hanna';

    private function __construct()
    {
        // This class cannot be instantiated
    }

    public static function all(): array
    {
        return [
            self::NAO_CONFORMIDADE,
            self::TORNEIRA_ABERTA,
            self::INCIDENTE,
            self::STOCK_BAIXO,
            self::RESUMO_DIARIO,
            self::HANNA,
        ];
    }

    public static function labels(): array
    {
        return [
            self::NAO_CONFORMIDADE => 'Parâmetros fora dos limites (CN 14/DA)',
            self::TORNEIRA_ABERTA => 'Torneira de água aberta',
            self::INCIDENTE => 'Incidentes (criação e mensagens)',
            self::STOCK_BAIXO => 'Stock insuficiente',
            self::RESUMO_DIARIO => 'Resumo diário de conformidade',
            self::HANNA => 'Alertas de sensores Hanna',
        ];
    }
}
