<?php declare(strict_types=1);

namespace App\Enums;

enum EstadoConformidade: string
{
    case VERDE = 'verde';
    case AMARELO = 'amarelo';
    case VERMELHO = 'vermelho';
    case NEUTRO = 'neutro';

    public function colorClass(): string
    {
        return match ($this) {
            self::VERDE => 'text-success-600',
            self::AMARELO => 'text-warning-600',
            self::VERMELHO => 'text-danger-600',
            self::NEUTRO => 'text-gray-400',
        };
    }
}
