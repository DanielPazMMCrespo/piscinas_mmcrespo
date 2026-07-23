<?php declare(strict_types=1);

namespace App\Constants;

/**
 * Limiares de qualidade de água usados para detecção de anomalias e
 * heurísticas de lavagem de filtro. Centralizados para evitar duplicação
 * em RelatorioPdf e outras páginas.
 */
final class WaterQualityThresholds
{
    /**
     * Limiares para detecção de anomalia (Ph/ORP fora dos limites normais).
     * Usado em relatórios para justificar leituras anómalas.
     */
    public const ANOMALY_PH_MIN = 6.0;
    public const ANOMALY_ORP_MIN = 400;
    public const ANOMALY_ORP_MAX = 900;

    /**
     * Limiares para heurística de lavagem de filtro. Quando pH está fora do
     * intervalo [6, 8] E ORP está fora do intervalo [600, 870], o sistema
     * infere que foi necessária uma lavagem de filtro para normalizar.
     */
    public const FILTER_WASH_PH_MIN = 6.0;
    public const FILTER_WASH_PH_MAX = 8.0;
    public const FILTER_WASH_ORP_MIN = 600.0;
    public const FILTER_WASH_ORP_MAX = 870.0;
}
