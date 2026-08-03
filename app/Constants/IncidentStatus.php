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
}
