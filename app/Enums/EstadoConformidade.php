<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoConformidade: string
{
    case VERDE = 'verde';
    case AMARELO = 'amarelo';
    case VERMELHO = 'vermelho';
    case NEUTRO = 'neutro';
}
