<?php

declare(strict_types=1);

namespace Tests\Unit\Constants;

use App\Constants\TrabalhoParagem;
use Tests\TestCase;

class TrabalhoParagemTest extends TestCase
{
    public function test_template_usa_apenas_tipos_validos(): void
    {
        $template = TrabalhoParagem::template();
        $this->assertCount(13, $template);

        foreach ($template as $item) {
            $this->assertArrayHasKey('ordem', $item);
            $this->assertArrayHasKey('tipo', $item);
            $this->assertArrayHasKey('obrigatorio', $item);
            $this->assertTrue(TrabalhoParagem::isValid($item['tipo']));
        }
    }

    public function test_all_contido_nas_chaves_de_labels(): void
    {
        $todos = TrabalhoParagem::all();
        $labels = TrabalhoParagem::labels();

        $this->assertCount(13, $todos);
        $this->assertSame(array_values($todos), array_keys($labels));

        foreach ($todos as $tipo) {
            $this->assertNotEmpty($labels[$tipo]);
        }
    }

    public function test_estados_e_origens_validos(): void
    {
        $estados = TrabalhoParagem::estados();
        $this->assertCount(5, $estados);
        $this->assertTrue(TrabalhoParagem::isValidEstado(TrabalhoParagem::ESTADO_PREVISTO));
        $this->assertTrue(TrabalhoParagem::isValidEstado(TrabalhoParagem::ESTADO_EXECUTADO));
        $this->assertFalse(TrabalhoParagem::isValidEstado('invalido'));

        $origens = TrabalhoParagem::origens();
        $this->assertCount(3, $origens);
        $this->assertTrue(TrabalhoParagem::isValidOrigem(TrabalhoParagem::ORIGEM_DECLARADA));
        $this->assertTrue(TrabalhoParagem::isValidOrigem(TrabalhoParagem::ORIGEM_RECONSTRUIDA));
        $this->assertFalse(TrabalhoParagem::isValidOrigem('invalida'));
    }

    public function test_legionella_e_obrigatoria_no_template(): void
    {
        $template = TrabalhoParagem::template();
        $itemLegionella = null;

        foreach ($template as $item) {
            if ($item['tipo'] === TrabalhoParagem::DESINFECAO_LEGIONELLA) {
                $itemLegionella = $item;
                break;
            }
        }

        $this->assertNotNull($itemLegionella);
        $this->assertTrue($itemLegionella['obrigatorio'], 'Desinfeção de Legionella tem de ser obrigatória.');
        $this->assertTrue(TrabalhoParagem::isObrigatorio(TrabalhoParagem::DESINFECAO_LEGIONELLA));
    }

    public function test_obrigatorios_definidos_corretamente(): void
    {
        $obrigatorios = TrabalhoParagem::obrigatorios();

        $this->assertContains(TrabalhoParagem::LIMPEZA_TANQUE, $obrigatorios);
        $this->assertContains(TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO, $obrigatorios);
        $this->assertContains(TrabalhoParagem::DESINFECAO_LEGIONELLA, $obrigatorios);
        $this->assertContains(TrabalhoParagem::SUPERCLORACAO, $obrigatorios);
        $this->assertContains(TrabalhoParagem::REPOSICAO_CLORO, $obrigatorios);
        $this->assertContains(TrabalhoParagem::VERIFICACAO_PARAMETROS, $obrigatorios);

        $this->assertNotContains(TrabalhoParagem::ESVAZIAMENTO_TANQUE, $obrigatorios);
        $this->assertNotContains(TrabalhoParagem::LIMPEZA_CALEIRAS, $obrigatorios);
        $this->assertNotContains(TrabalhoParagem::MANUTENCAO_FILTROS, $obrigatorios);
        $this->assertNotContains(TrabalhoParagem::ARRANQUE_AQUECIMENTO, $obrigatorios);
        $this->assertNotContains(TrabalhoParagem::OUTRO, $obrigatorios);
    }

    public function test_label_devolve_fallback_para_nulo_ou_desconhecido(): void
    {
        $this->assertSame('—', TrabalhoParagem::label(null));
        $this->assertSame('desconhecido', TrabalhoParagem::label('desconhecido'));
        $this->assertSame('Limpeza e desinfeção do tanque', TrabalhoParagem::label(TrabalhoParagem::LIMPEZA_TANQUE));

        $this->assertSame('—', TrabalhoParagem::estadoLabel(null));
        $this->assertSame('Executado', TrabalhoParagem::estadoLabel(TrabalhoParagem::ESTADO_EXECUTADO));

        $this->assertSame('—', TrabalhoParagem::origemLabel(null));
        $this->assertSame('Reconstruída', TrabalhoParagem::origemLabel(TrabalhoParagem::ORIGEM_RECONSTRUIDA));
    }

    public function test_template_ordem_e_contigua_de_1_a_13(): void
    {
        $template = TrabalhoParagem::template();
        $ordens = array_column($template, 'ordem');

        $this->assertSame(range(1, 13), $ordens);
    }

    public function test_acoes_operacionais_compativeis(): void
    {
        $compativeis = TrabalhoParagem::acoesOperacionaisCompativeis(TrabalhoParagem::SUPERCLORACAO);
        $this->assertSame(['tratamento_choque'], $compativeis);

        $filtros = TrabalhoParagem::acoesOperacionaisCompativeis(TrabalhoParagem::MANUTENCAO_FILTROS);
        $this->assertContains('lavagem_filtro', $filtros);
        $this->assertContains('enxaguamento_filtro', $filtros);

        $this->assertSame([], TrabalhoParagem::acoesOperacionaisCompativeis(TrabalhoParagem::OUTRO));
    }
}
