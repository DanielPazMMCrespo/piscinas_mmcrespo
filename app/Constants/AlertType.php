<?php

declare(strict_types=1);

namespace App\Constants;

final class AlertType
{
    public const SEM_REGISTO = 'sem_registo';

    public const FORA_LIMITES = 'fora_limites';

    public const TEMPERATURA = 'temp';

    public const TORNEIRA = 'tap';

    public const INCIDENTE = 'incidente';

    public const STOCK = 'stock';

    public const PH_OVERTIME = 'ph_overtime';

    private function __construct()
    {
        // This class cannot be instantiated
    }
}
