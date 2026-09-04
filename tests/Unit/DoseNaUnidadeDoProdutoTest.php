<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DosageCalculatorService;
use Tests\TestCase;

/**
 * A dose vem do calculo em ml ou g. O stock e o campo "Quantidade" das adicoes
 * de quimicos estao em L, kg ou un. Escrever uma sem converter a outra da um
 * erro de 1000x no desconto de stock e no livro sanitario -- a mesma classe de
 * erro que a sessao 20 apanhou na sugestao impressa.
 */
class DoseNaUnidadeDoProdutoTest extends TestCase
{
    private function servico(): DosageCalculatorService
    {
        return app(DosageCalculatorService::class);
    }

    public function test_litros_dividem_por_mil(): void
    {
        $this->assertSame(
            2.5,
            $this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => 2500.0, 'unidade' => 'L']),
            '2500 ml sao 2,5 L. Sem esta divisao o stock descia 2500 L.'
        );
    }

    public function test_quilos_dividem_por_mil(): void
    {
        $this->assertSame(
            1.2,
            $this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => 1200.0, 'unidade' => 'kg'])
        );
    }

    public function test_aceita_a_unidade_escrita_por_extenso(): void
    {
        foreach (['l', 'litro', 'litros', ' L '] as $unidade) {
            $this->assertSame(
                0.5,
                $this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => 500.0, 'unidade' => $unidade]),
                "Unidade \"{$unidade}\" devia ser reconhecida como litros."
            );
        }
    }

    public function test_gramas_e_mililitros_ficam_como_estao(): void
    {
        $this->assertSame(
            300.0,
            $this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => 300.0, 'unidade' => 'g'])
        );
    }

    /**
     * O caso que justifica devolver null em vez de um numero.
     */
    public function test_produto_vendido_a_unidade_nao_tem_conversao(): void
    {
        $this->assertNull(
            $this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => 300.0, 'unidade' => 'un']),
            'Uma dose em mililitros nao diz quantas pastilhas por. Nao se inventa.'
        );
    }

    public function test_unidade_desconhecida_ou_ausente_nao_tem_conversao(): void
    {
        $this->assertNull($this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => 300.0, 'unidade' => null]));
        $this->assertNull($this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => 300.0]));
        $this->assertNull($this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => 300.0, 'unidade' => 'caixa']));
    }

    public function test_dose_zero_ou_negativa_nao_se_aplica(): void
    {
        $this->assertNull($this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => 0.0, 'unidade' => 'L']));
        $this->assertNull($this->servico()->doseNaUnidadeDoProduto(['dose_com_fator_ml' => -5.0, 'unidade' => 'L']));
    }
}
