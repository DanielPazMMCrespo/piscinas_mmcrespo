<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\StockHub;
use App\Models\Installation;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\StockWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockHubTest extends TestCase
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

    public function test_admin_acede_e_ve_uma_linha_por_produto(): void
    {
        $installation = Installation::create(['name' => 'Leiria', 'active' => true]);
        $produto = Product::create(['name' => 'Hipoclorito de Sódio', 'unidade' => 'L', 'categoria' => 'Desinfeção', 'active' => true]);
        StockWarehouse::create(['product_id' => $produto->id, 'quantity' => 100]);
        StockInstallation::create(['installation_id' => $installation->id, 'product_id' => $produto->id, 'quantity' => 2, 'limite_minimo' => 10]);

        $admin = $this->utilizador('admin');

        $this->actingAs($admin)
            ->get('/admin/stock')
            ->assertSuccessful()
            ->assertSee('Hipoclorito de Sódio')
            ->assertSee('Leiria');
    }

    public function test_nadador_salvador_nao_acede(): void
    {
        $this->actingAs($this->utilizador('nadador_salvador'))
            ->get('/admin/stock')
            ->assertForbidden();
    }

    public function test_gestor_ve_mas_nao_movimenta(): void
    {
        $installation = Installation::create(['name' => 'Leiria', 'active' => true]);
        $produto = Product::create(['name' => 'Germicida', 'unidade' => 'L', 'categoria' => 'Desinfeção', 'active' => true]);
        StockWarehouse::create(['product_id' => $produto->id, 'quantity' => 50]);
        StockInstallation::create(['installation_id' => $installation->id, 'product_id' => $produto->id, 'quantity' => 5, 'limite_minimo' => 10]);

        $gestor = $this->utilizador('gestor');

        $this->actingAs($gestor)
            ->get('/admin/stock')
            ->assertSuccessful()
            ->assertSee('Germicida');

        $this->assertFalse($gestor->can('transferStock', StockWarehouse::first()));
        $this->assertFalse($gestor->can('updateStock', StockWarehouse::first()));
    }

    public function test_transferencia_do_armazem_debita_e_credita(): void
    {
        $installation = Installation::create(['name' => 'Leiria', 'active' => true]);
        $produto = Product::create(['name' => 'Algicida', 'unidade' => 'L', 'categoria' => 'Tratamento', 'active' => true]);
        $armazem = StockWarehouse::create(['product_id' => $produto->id, 'quantity' => 100]);

        $tecnico = $this->utilizador('tecnico');

        Livewire::actingAs($tecnico)
            ->test(StockHub::class)
            ->callTableAction('armazem_acao', $produto, data: [
                'tipo' => 'transferir',
                'installation_id' => $installation->id,
                'quantidade' => '15',
            ]);

        $this->assertEquals(85, (float) $armazem->fresh()->quantity);

        $stockInstalacao = StockInstallation::where('installation_id', $installation->id)
            ->where('product_id', $produto->id)
            ->first();
        $this->assertNotNull($stockInstalacao);
        $this->assertEquals(15, (float) $stockInstalacao->quantity);
    }

    public function test_entrada_direta_na_instalacao_cria_linha_de_stock(): void
    {
        $installation = Installation::create(['name' => 'Maceira', 'active' => true]);
        $produto = Product::create(['name' => 'Tricloro Granulado', 'unidade' => 'kg', 'categoria' => 'Desinfeção', 'active' => true]);

        $tecnico = $this->utilizador('tecnico');

        Livewire::actingAs($tecnico)
            ->test(StockHub::class)
            ->callTableAction("inst_acao_{$installation->id}", $produto, data: [
                'tipo' => 'entrada',
                'quantidade' => '8',
            ]);

        $stock = StockInstallation::where('installation_id', $installation->id)
            ->where('product_id', $produto->id)
            ->first();

        $this->assertNotNull($stock);
        $this->assertEquals(8, (float) $stock->quantity);
    }

    public function test_consumo_manual_nao_deixa_ficar_negativo(): void
    {
        $installation = Installation::create(['name' => 'Caranguejeira', 'active' => true]);
        $produto = Product::create(['name' => 'Floculante Líquido', 'unidade' => 'L', 'categoria' => 'Clarificação', 'active' => true]);
        StockInstallation::create(['installation_id' => $installation->id, 'product_id' => $produto->id, 'quantity' => 3, 'limite_minimo' => 1]);

        $tecnico = $this->utilizador('tecnico');

        Livewire::actingAs($tecnico)
            ->test(StockHub::class)
            ->callTableAction("inst_acao_{$installation->id}", $produto, data: [
                'tipo' => 'consumo',
                'quantidade' => '3',
            ]);

        $stock = StockInstallation::where('installation_id', $installation->id)
            ->where('product_id', $produto->id)
            ->first();

        $this->assertEquals(0, (float) $stock->quantity);
    }

    public function test_filtro_abaixo_do_minimo(): void
    {
        $installation = Installation::create(['name' => 'Leiria', 'active' => true]);

        $baixo = Product::create(['name' => 'Produto Baixo', 'unidade' => 'L', 'active' => true]);
        StockInstallation::create(['installation_id' => $installation->id, 'product_id' => $baixo->id, 'quantity' => 1, 'limite_minimo' => 10]);

        $ok = Product::create(['name' => 'Produto OK', 'unidade' => 'L', 'active' => true]);
        StockInstallation::create(['installation_id' => $installation->id, 'product_id' => $ok->id, 'quantity' => 50, 'limite_minimo' => 10]);

        Livewire::actingAs($this->utilizador('admin'))
            ->test(StockHub::class)
            ->filterTable('abaixo_minimo', true)
            ->assertCanSeeTableRecords([$baixo])
            ->assertCanNotSeeTableRecords([$ok]);
    }
}
