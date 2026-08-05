<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\RelatorioPdf;
use ReflectionMethod;
use Tests\TestCase;

class RelatorioPdfCsvInjectionTest extends TestCase
{
    private function sanitizar(mixed $valor): mixed
    {
        $method = new ReflectionMethod(RelatorioPdf::class, 'sanitizarCelulaCsv');
        $method->setAccessible(true);

        return $method->invoke(null, $valor);
    }

    public function test_celula_com_formula_ganha_prefixo_de_aspa_simples(): void
    {
        $this->assertSame("'=cmd|'/c calc'!A1", $this->sanitizar("=cmd|'/c calc'!A1"));
        $this->assertSame("'+1+1", $this->sanitizar('+1+1'));
        $this->assertSame("'-1-1", $this->sanitizar('-1-1'));
        $this->assertSame("'@SUM(A1)", $this->sanitizar('@SUM(A1)'));
    }

    public function test_celula_normal_fica_inalterada(): void
    {
        $this->assertSame('Leiria - Competição', $this->sanitizar('Leiria - Competição'));
        $this->assertSame(7.4, $this->sanitizar(7.4));
        $this->assertNull($this->sanitizar(null));
    }
}
