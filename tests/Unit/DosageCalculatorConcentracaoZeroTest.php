<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Services\DosageCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressao: a formula divide por (concentracao * 10). O `?? 10.0` so apanha
 * null, logo um produto gravado com concentracao 0 rebentava a sugestao de
 * dosagem com um DivisionByZeroError (500) no Registo Diario. O atalho "Novo
 * Produto" do StockHub aceitava esse zero.
 */
class DosageCalculatorConcentracaoZeroTest extends TestCase
{
    use RefreshDatabase;

    private Pool $piscina;

    protected function setUp(): void
    {
        parent::setUp();

        $instalacao = Installation::factory()->create();
        $this->piscina = Pool::factory()->create([
            'installation_id' => $instalacao->id,
            'volume' => 900,
        ]);
    }

    public function test_concentracao_zero_nao_rebenta_e_nao_sugere_dose(): void
    {
        Product::factory()->create([
            'name' => 'Cloro sem concentracao declarada',
            'categoria' => 'Desinfeção',
            'unidade' => 'L',
            'concentracao_cl' => 0,
            'active' => true,
        ]);

        $resultado = app(DosageCalculatorService::class)
            ->calcularDose($this->piscina, 'cloro_livre', 0.1);

        $this->assertNull($resultado);
    }

    public function test_concentracao_negativa_nao_sugere_dose(): void
    {
        Product::factory()->create([
            'name' => 'Cloro com concentracao invalida',
            'categoria' => 'Desinfeção',
            'unidade' => 'L',
            'concentracao_cl' => -5,
            'active' => true,
        ]);

        $resultado = app(DosageCalculatorService::class)
            ->calcularDose($this->piscina, 'cloro_livre', 0.1);

        $this->assertNull($resultado);
    }

    public function test_concentracao_valida_continua_a_calcular_dose(): void
    {
        Product::factory()->create([
            'name' => 'Hipoclorito de sodio 13%',
            'categoria' => 'Desinfeção',
            'unidade' => 'L',
            'concentracao_cl' => 13,
            'active' => true,
        ]);

        $resultado = app(DosageCalculatorService::class)
            ->calcularDose($this->piscina, 'cloro_livre', 0.1);

        $this->assertIsArray($resultado);
        $this->assertGreaterThan(0, $resultado['dose_calculada_ml']);
    }

    public function test_concentracao_nula_usa_o_defeito_de_dez_por_cento(): void
    {
        Product::factory()->create([
            'name' => 'Cloro generico',
            'categoria' => 'Desinfeção',
            'unidade' => 'L',
            'concentracao_cl' => null,
            'active' => true,
        ]);

        $resultado = app(DosageCalculatorService::class)
            ->calcularDose($this->piscina, 'cloro_livre', 0.1);

        $this->assertIsArray($resultado);
        $this->assertGreaterThan(0, $resultado['dose_calculada_ml']);
    }
}
