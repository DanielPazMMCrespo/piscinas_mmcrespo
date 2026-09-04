<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\User;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Actions\ActionContainer;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A sugestao de dose e o unico sitio da app que responde a "quanto produto
 * ponho?". Duas coisas estiveram erradas ao mesmo tempo:
 *
 * 1. O `visible()` do banner lia `$get("pools.{id}.ns_ph")` dentro de uma
 *    Section que ja tem esse statePath. Resolvia para pools.1.pools.1.ns_ph e
 *    devolvia null sem erro: o banner nunca aparecia. (Corrigido em 395f2d7.)
 * 2. Mesmo depois disso, o banner vivia dentro de "Quimicos e Observacoes",
 *    que vem fechada. O semaforo dizia que o pH estava fora dos limites, mas a
 *    dose estava uma seccao abaixo, atras de um toque -- e aplica-la a mao
 *    custava oito gestos.
 *
 * Agora vive junto das leituras e tem um botao que a aplica. O botao escreve a
 * quantidade na unidade em que o stock esta, nao em mililitros: e o mesmo
 * campo que desconta stock e entra no livro sanitario.
 */
class DailyRecordSugestaoDosagemTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    private Installation $leiria;

    private Pool $competicao;

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
    }

    private function criarProdutoDeCloro(string $unidade = 'L'): Product
    {
        return Product::create([
            'name' => 'Hipoclorito de Sódio',
            'unidade' => $unidade,
            'categoria' => 'Desinfeção',
            'concentracao_cl' => 12.5,
            'active' => true,
        ]);
    }

    private function criarProdutoDePh(string $unidade = 'L'): Product
    {
        return Product::create([
            'name' => 'Redutor de pH',
            'unidade' => $unidade,
            'categoria' => 'Correção pH',
            'concentracao_cl' => 0.01,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $leituras
     */
    private function abrirCom(array $leituras): Testable
    {
        $prefixo = "pools.{$this->competicao->id}";
        $estado = ['installation_id' => $this->leiria->id];

        foreach ($leituras as $campo => $valor) {
            $estado["{$prefixo}.{$campo}"] = $valor;
        }

        return Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm($estado);
    }

    private function componente(Testable $pagina, string $classe, string $nome): mixed
    {
        foreach ($pagina->instance()->getForm('form')->getFlatComponents(withHidden: true) as $componente) {
            if ($componente instanceof $classe && $componente->getName() === $nome) {
                return $componente;
            }
        }

        return null;
    }

    /**
     * As acoes de formulario nao aparecem no schema plano como componentes: cada
     * uma vive dentro de um ActionContainer proprio.
     */
    private function acao(Testable $pagina, string $nome): ?Action
    {
        foreach ($pagina->instance()->getForm('form')->getFlatComponents(withHidden: true) as $componente) {
            if (! $componente instanceof ActionContainer) {
                continue;
            }

            foreach ($componente->getActions() as $acao) {
                if ($acao->getName() === $nome) {
                    return $acao;
                }
            }
        }

        return null;
    }

    /**
     * Chama a acao diretamente em vez de pela plumbing de chaves do Livewire:
     * o que se quer provar e o efeito do closure (a linha que fica no estado),
     * nao o mecanismo de montagem do Filament.
     */
    private function aplicarDoseDeCloro(Testable $pagina): Testable
    {
        $acao = $this->acao($pagina, "aplicar_dose_cloro_livre_{$this->competicao->id}");

        $this->assertNotNull($acao, 'A acao de aplicar dose tem de existir.');
        $acao->call();

        return $pagina;
    }

    private function banner(Testable $pagina): ?Placeholder
    {
        return $this->componente($pagina, Placeholder::class, "sugestao_dosagem_banner_{$this->competicao->id}");
    }

    // --- O banner só aparece quando há mesmo algo a sugerir ------------------

    public function test_banner_aparece_com_cloro_baixo_e_produto_disponivel(): void
    {
        $this->criarProdutoDeCloro();

        $banner = $this->banner($this->abrirCom(['ns_ph' => 7.2, 'ns_cloro_livre' => 0.1]));

        $this->assertNotNull($banner, 'O placeholder tem de existir no schema.');
        $this->assertTrue($banner->isVisible(), 'Com cloro a 0,1 e produto em catálogo, há dose a sugerir.');
    }

    public function test_banner_nao_aparece_sem_produto_em_catalogo(): void
    {
        // Sem produto não há dose possível. Antes o banner aparecia vazio, um
        // bloco em branco a gastar ecrã.
        $banner = $this->banner($this->abrirCom(['ns_ph' => 7.2, 'ns_cloro_livre' => 0.1]));

        $this->assertFalse($banner->isVisible());
    }

    public function test_banner_nao_aparece_com_leituras_dentro_dos_limites(): void
    {
        $this->criarProdutoDeCloro();
        $this->criarProdutoDePh();

        $banner = $this->banner($this->abrirCom(['ns_ph' => 7.2, 'ns_cloro_livre' => 1.0]));

        $this->assertFalse($banner->isVisible(), 'Água conforme não precisa de correção.');
    }

    public function test_banner_nao_aparece_sem_leituras(): void
    {
        $this->criarProdutoDeCloro();

        $this->assertFalse($this->banner($this->abrirCom([]))->isVisible());
    }

    // --- O botão que poupa os sete gestos -----------------------------------

    public function test_o_botao_de_aplicar_esta_visivel_quando_ha_dose(): void
    {
        $this->criarProdutoDeCloro();

        $acao = $this->acao($this->abrirCom(['ns_cloro_livre' => 0.1]), "aplicar_dose_cloro_livre_{$this->competicao->id}");

        $this->assertNotNull($acao, 'A ação tem de existir no schema.');
        $this->assertTrue($acao->isVisible());
    }

    public function test_aplicar_escreve_a_adicao_com_produto_quantidade_e_acao_corretiva(): void
    {
        $produto = $this->criarProdutoDeCloro();

        $pagina = $this->aplicarDoseDeCloro($this->abrirCom(['ns_cloro_livre' => 0.1]));

        $adicoes = data_get($pagina->get('data'), "pools.{$this->competicao->id}.adicoes");

        $this->assertIsArray($adicoes);
        $this->assertCount(1, $adicoes, 'Um toque tem de criar exatamente uma linha.');

        $linha = array_values($adicoes)[0];

        $this->assertSame($produto->id, $linha['product_id']);
        $this->assertNotEmpty($linha['acao_corretiva'], 'A ação corretiva é um campo legal — não pode ficar vazia.');
        $this->assertStringContainsString('cloro livre', $linha['acao_corretiva']);
        $this->assertStringContainsString($produto->name, $linha['acao_corretiva']);
    }

    /**
     * O erro de 1000x: a dose vem em ml, o stock está em litros. Um produto em
     * litros nunca pode receber uma quantidade de três ou quatro dígitos para
     * uma piscina de 900 m³ — isso seriam milhares de litros de cloro.
     */
    public function test_a_quantidade_aplicada_vem_na_unidade_do_stock_e_nao_em_mililitros(): void
    {
        $this->criarProdutoDeCloro('L');

        $pagina = $this->aplicarDoseDeCloro($this->abrirCom(['ns_cloro_livre' => 0.1]));

        $adicoes = data_get($pagina->get('data'), "pools.{$this->competicao->id}.adicoes");
        $quantidade = (float) array_values($adicoes)[0]['quantity'];

        $this->assertGreaterThan(0, $quantidade);
        $this->assertLessThan(
            100,
            $quantidade,
            "Aplicou {$quantidade} L de cloro numa piscina de 900 m³. É a dose em mililitros escrita num campo que o stock lê em litros."
        );
    }

    /**
     * Um produto vendido à unidade (pastilhas) não tem equivalência com uma
     * dose em mililitros. Nesse caso não se oferece o botão.
     */
    public function test_nao_se_aplica_dose_a_produto_vendido_a_unidade(): void
    {
        $this->criarProdutoDeCloro('un');

        $acao = $this->acao($this->abrirCom(['ns_cloro_livre' => 0.1]), "aplicar_dose_cloro_livre_{$this->competicao->id}");

        $this->assertNotNull($acao);
        $this->assertFalse($acao->isVisible(), 'Sem conversão definida, não se sugere um número inventado.');
    }
}
