<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\DailyRecordFormBuilder;
use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Filament\Forms\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * O problema de scroll: em Leiria sao tres piscinas num so formulario, e o
 * cartao de cada uma nao era colapsavel. Para chegar a Lazer o tecnico fazia
 * scroll pelo cartao inteiro da Competicao -- a auditoria contou cerca de 8
 * ecras de 844 px para as tres.
 *
 * Agora o cartao fecha-se sozinho quando as quatro leituras legais dessa
 * piscina estao escritas, e o cabecalho passa a mostrar o resumo do que ficou
 * registado. A piscina seguinte sobe para o topo do ecra sem um unico gesto.
 *
 * Estes testes guardam uma excecao a regra 9 do CLAUDE.md, encontrada aqui: os
 * closures do PROPRIO cartao (description, collapsed) correm no container PAI,
 * nao no dele. O statePath que o cartao declara aplica-se aos filhos, nao a si
 * mesmo -- por isso, so nesta posicao, o caminho certo e o absoluto
 * ("pools.{id}.ns_ph"). Com o nome curto, $get devolve null em silencio e o
 * cartao nunca fecha. E o mesmo mecanismo que manteve o banner de dose
 * invisivel durante meses, do lado inverso.
 */
class CartaoPiscinaFechaQuandoFeitoTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    private Installation $leiria;

    private Pool $competicao;

    private Pool $lazer;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
            'volume' => 900.0,
        ]);
        $this->lazer = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Lazer',
            'volume' => 600.0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function abrirCom(array $estado = []): Testable
    {
        return Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm(array_merge(['installation_id' => $this->leiria->id], $estado));
    }

    /**
     * @param  array<string, mixed>  $leituras
     * @return array<string, mixed>
     */
    private function leiturasDe(Pool $pool, array $leituras): array
    {
        $prefixo = "pools.{$pool->id}";
        $estado = [];

        foreach ($leituras as $campo => $valor) {
            $estado["{$prefixo}.{$campo}"] = $valor;
        }

        return $estado;
    }

    /**
     * @return array<string, mixed>
     */
    private function leiturasCompletasDe(Pool $pool): array
    {
        return $this->leiturasDe($pool, [
            'ns_ph' => 7.4,
            'ns_cloro_livre' => 1.1,
            'ns_cloro_total' => 1.3,
            'ns_temperatura' => 27.5,
        ]);
    }

    private function cartao(Testable $pagina, Pool $pool): ?Section
    {
        foreach ($pagina->instance()->getForm('form')->getFlatComponents(withHidden: true) as $componente) {
            if (! $componente instanceof Section) {
                continue;
            }

            if ($componente->getHeading() === "🏊 {$pool->name}") {
                return $componente;
            }
        }

        return null;
    }

    public function test_o_cartao_e_colapsavel(): void
    {
        $cartao = $this->cartao($this->abrirCom(), $this->competicao);

        $this->assertNotNull($cartao, 'O cartão da piscina tem de existir.');
        $this->assertTrue(
            $cartao->isCollapsible(),
            'Sem ser colapsável não há forma de fechar a Competição para dar espaço à Lazer.'
        );
    }

    public function test_fica_aberto_enquanto_faltam_leituras(): void
    {
        $cartao = $this->cartao($this->abrirCom(), $this->competicao);

        $this->assertFalse($cartao->isCollapsed(), 'Um cartão por preencher tem de estar aberto.');
    }

    public function test_fica_aberto_com_leituras_a_meio(): void
    {
        $pagina = $this->abrirCom($this->leiturasDe($this->competicao, [
            'ns_ph' => 7.4,
            'ns_cloro_livre' => 1.1,
        ]));

        $this->assertFalse(
            $this->cartao($pagina, $this->competicao)->isCollapsed(),
            'Faltam duas leituras legais: não pode fechar-se e dar a piscina por feita.'
        );
    }

    /**
     * O que fecha o cartao no browser e o evento `collapse-section`, que a
     * seccao do Filament ouve por id. O `collapsed()` do schema so e lido ao
     * MONTAR -- verificado em staging: com so o closure, a descricao mudava
     * para o resumo mas o cartao ficava aberto.
     *
     * Por isso este teste segue o evento, nao o valor calculado.
     */
    public function test_despacha_o_fecho_quando_a_quarta_leitura_entra(): void
    {
        $prefixo = "pools.{$this->competicao->id}";

        $this->abrirCom($this->leiturasDe($this->competicao, [
            'ns_ph' => 7.4,
            'ns_cloro_livre' => 1.1,
            'ns_cloro_total' => 1.3,
        ]))
            // A quarta entra sozinha, para o `$old` do campo estar vazio.
            ->set("data.{$prefixo}.ns_temperatura", 27.5)
            ->assertDispatched(
                'collapse-section',
                id: DailyRecordFormBuilder::ID_CARTAO_PISCINA.$this->competicao->id,
            );
    }

    public function test_nao_despacha_o_fecho_com_leituras_a_meio(): void
    {
        $prefixo = "pools.{$this->competicao->id}";

        $this->abrirCom($this->leiturasDe($this->competicao, ['ns_ph' => 7.4]))
            ->set("data.{$prefixo}.ns_cloro_livre", 1.1)
            ->assertNotDispatched('collapse-section');
    }

    /**
     * Corrigir um valor ja escrito nao pode fechar o cartao na cara de quem o
     * esta a corrigir.
     */
    public function test_corrigir_uma_leitura_ja_escrita_nao_fecha_o_cartao(): void
    {
        $prefixo = "pools.{$this->competicao->id}";

        $this->abrirCom($this->leiturasCompletasDe($this->competicao))
            ->set("data.{$prefixo}.ns_ph", 7.6)
            ->assertNotDispatched('collapse-section');
    }

    /**
     * Guarda o id: se mudar, o evento deixa de encontrar a seccao e o cartao
     * nunca mais fecha -- sem erro nenhum.
     */
    public function test_o_cartao_tem_o_id_que_o_evento_procura(): void
    {
        $cartao = $this->cartao($this->abrirCom(), $this->competicao);

        $this->assertSame(
            DailyRecordFormBuilder::ID_CARTAO_PISCINA.$this->competicao->id,
            $cartao->getId(),
        );
    }

    /**
     * Um formulario remontado com as leituras ja preenchidas abre fechado.
     */
    public function test_remontado_com_as_leituras_feitas_ja_vem_fechado(): void
    {
        $pagina = $this->abrirCom($this->leiturasCompletasDe($this->competicao));

        $this->assertTrue($this->cartao($pagina, $this->competicao)->isCollapsed());
    }

    /**
     * O caso que mais importa: fechar uma piscina não pode fechar as outras.
     * Se o `$get` do `collapsed()` estivesse a ler o caminho errado, ou fechava
     * tudo ou não fechava nada.
     */
    public function test_fechar_uma_piscina_nao_fecha_a_outra(): void
    {
        $pagina = $this->abrirCom($this->leiturasCompletasDe($this->competicao));

        $this->assertTrue(
            $this->cartao($pagina, $this->competicao)->isCollapsed(),
            'A Competição está feita.'
        );
        $this->assertFalse(
            $this->cartao($pagina, $this->lazer)->isCollapsed(),
            'A Lazer ainda não foi medida — tem de continuar aberta.'
        );
    }

    /**
     * Fechado, o cabeçalho é a única coisa visível. Tem de dizer o que ficou
     * registado, senão o técnico não consegue reconferir sem reabrir.
     */
    public function test_o_cabecalho_mostra_o_resumo_quando_fechado(): void
    {
        $pagina = $this->abrirCom($this->leiturasCompletasDe($this->competicao));
        $descricao = (string) $this->cartao($pagina, $this->competicao)->getDescription();

        $this->assertStringContainsString('pH 7,40', $descricao);
        $this->assertStringContainsString('1,10', $descricao, 'O cloro livre medido.');
        $this->assertStringContainsString('27,5', $descricao, 'A temperatura medida.');
    }

    public function test_o_cabecalho_mostra_o_volume_enquanto_esta_aberto(): void
    {
        $descricao = (string) $this->cartao($this->abrirCom(), $this->competicao)->getDescription();

        $this->assertStringContainsString('900', $descricao, 'O volume é o que serve para conferir a dose.');
    }
}
