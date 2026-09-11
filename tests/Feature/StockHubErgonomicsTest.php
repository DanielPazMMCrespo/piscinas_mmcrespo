<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\StockHub;
use App\Models\DosingContainer;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\StockWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockHubErgonomicsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'gestor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
    }

    private function utilizador(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_stock_hub_renders_kpis_and_segmented_tabs(): void
    {
        $admin = $this->utilizador('admin');
        $installation = Installation::create(['name' => 'Piscina Municipal', 'active' => true]);
        $produto = Product::create(['name' => 'Hipoclorito', 'unidade' => 'L', 'categoria' => 'Desinfeção', 'active' => true]);
        StockWarehouse::create(['product_id' => $produto->id, 'quantity' => 120]);
        StockInstallation::create(['installation_id' => $installation->id, 'product_id' => $produto->id, 'quantity' => 4, 'limite_minimo' => 10]);

        $this->actingAs($admin)
            ->get('/admin/stock')
            ->assertSuccessful()
            ->assertSee('Alertas de Reposição')
            ->assertSee('Armazém Central')
            ->assertSee('Bidões na Sala de Máquinas')
            ->assertSee('Inventário')
            ->assertSee('Bidões Ativos')
            ->assertSee('Últimos Movimentos');
    }

    public function test_modais_das_acoes_sao_impressos_fora_do_separador_do_inventario(): void
    {
        $admin = $this->utilizador('admin');

        $html = Livewire::actingAs($admin)
            ->test(StockHub::class)
            ->mountAction('criarProduto')
            ->html();

        $separadorInventario = strpos($html, 'x-show="tab === \'inventario\'"');
        $modal = strpos($html, 'Adicionar Novo Produto Químico');

        $this->assertNotFalse($separadorInventario, 'O separador do inventário deixou de existir na vista.');
        $this->assertNotFalse($modal, 'O modal de novo produto não foi impresso.');

        // A tabela (e, com ela, os modais das ações) vive dentro do separador do
        // inventário; nos outros separadores fica display:none e nada abre.
        $this->assertLessThan(
            $separadorInventario,
            $modal,
            'Os modais das ações estão dentro do separador do inventário: fora dele não abrem.'
        );
    }

    public function test_reabastecer_bidao_action_refills_container_and_debits_stock(): void
    {
        $admin = $this->utilizador('admin');
        $installation = Installation::create(['name' => 'Complexo Aquatico', 'active' => true]);
        $pool = Pool::create([
            'name' => 'Tanque Principal',
            'installation_id' => $installation->id,
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);
        $produto = Product::create(['name' => 'Cloro Liquido 25L', 'unidade' => 'L', 'categoria' => 'Desinfeção', 'active' => true]);

        $stockInst = StockInstallation::create([
            'installation_id' => $installation->id,
            'product_id' => $produto->id,
            'quantity' => 50,
            'limite_minimo' => 10,
        ]);

        $container = DosingContainer::create([
            'pool_id' => $pool->id,
            'product_id' => $produto->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 25000,
            'restante_ml' => 3000,
            'alerta_percent' => 20,
        ]);

        Livewire::actingAs($admin)
            ->test(StockHub::class)
            ->callAction('reabastecerBidao', data: [
                'container_id' => $container->id,
                'quantidade_l' => 25,
                'nota' => 'Substituicao por bidao novo de 25L',
            ])
            ->assertHasNoActionErrors();

        $container->refresh();
        $this->assertEquals(25000, (float) $container->restante_ml);
        $this->assertNotNull($container->reabastecido_em);
        $this->assertEquals($admin->id, $container->reabastecido_por);

        $stockInst->refresh();
        $this->assertEquals(25, (float) $stockInst->quantity);

        $this->assertDatabaseHas('dosing_container_logs', [
            'dosing_container_id' => $container->id,
            'tipo_movimento' => 'reabastecimento',
            'user_id' => $admin->id,
            'nota' => 'Substituicao por bidao novo de 25L',
        ]);
    }

    public function test_stock_hub_native_tabs_filter_critical_products(): void
    {
        $installation = Installation::create(['name' => 'Centro Desportivo', 'active' => true]);

        $baixo = Product::create(['name' => 'Redutor de pH', 'unidade' => 'L', 'active' => true]);
        StockInstallation::create(['installation_id' => $installation->id, 'product_id' => $baixo->id, 'quantity' => 2, 'limite_minimo' => 10]);

        $ok = Product::create(['name' => 'Sulfato de Cobre', 'unidade' => 'kg', 'active' => true]);
        StockInstallation::create(['installation_id' => $installation->id, 'product_id' => $ok->id, 'quantity' => 100, 'limite_minimo' => 10]);

        Livewire::actingAs($this->utilizador('admin'))
            ->test(StockHub::class)
            ->filterTable('abaixo_minimo', true)
            ->assertCanSeeTableRecords([$baixo])
            ->assertCanNotSeeTableRecords([$ok]);
    }
}
