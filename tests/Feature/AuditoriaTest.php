<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\CustomActivitylogResource\Pages\ListActivitylog;
use App\Models\Installation;
use App\Models\Product;
use App\Models\StockWarehouse;
use App\Models\User;
use App\Services\StockService;
use App\Support\Auditoria;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);
    }

    public function test_login_falhado_fica_registado_com_identificador_e_sem_password(): void
    {
        User::factory()->create(['email' => 'tecnico@mmcrespo.pt']);

        auth()->attempt(['email' => 'tecnico@mmcrespo.pt', 'password' => 'password-errada']);

        $registo = Activity::where('log_name', Auditoria::CANAL_AUTH)
            ->where('description', 'Tentativa de início de sessão falhada.')
            ->first();

        $this->assertNotNull($registo);
        $this->assertSame('tecnico@mmcrespo.pt', $registo->properties['identificador']);
        $this->assertStringNotContainsString('password-errada', json_encode($registo->properties->toArray()));
    }

    public function test_login_bem_sucedido_guarda_ip(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::ADMIN);

        $this->actingAs($user);
        event(new Login('web', $user, false));

        $registo = Activity::where('log_name', Auditoria::CANAL_AUTH)
            ->where('description', 'Iniciou sessão no sistema.')
            ->first();

        $this->assertNotNull($registo);
        $this->assertSame($user->id, $registo->causer_id);
        $this->assertArrayHasKey('ip', $registo->properties->toArray());
    }

    public function test_movimento_de_armazem_e_espelhado_no_trilho(): void
    {
        $user = User::factory()->create();
        $produto = Product::create(['name' => 'Cloro granulado', 'unidade' => 'kg', 'active' => true]);
        $armazem = StockWarehouse::create(['product_id' => $produto->id, 'quantity' => 0]);

        app(StockService::class)->addWarehouseStock($armazem->id, 10.0, $user->id);

        $registo = Activity::where('log_name', Auditoria::CANAL_STOCK)->latest('id')->first();

        $this->assertNotNull($registo);
        $this->assertStringContainsString('Cloro granulado', $registo->description);
        $this->assertSame($user->id, $registo->causer_id);
        $this->assertEquals(10.0, $registo->properties['quantidade']);
    }

    public function test_consumo_na_instalacao_e_espelhado_com_nome_da_instalacao(): void
    {
        $user = User::factory()->create();
        $produto = Product::create(['name' => 'pH menos', 'unidade' => 'L', 'active' => true]);
        $instalacao = Installation::create(['name' => 'Leiria']);
        $armazem = StockWarehouse::create(['product_id' => $produto->id, 'quantity' => 20]);

        $stock = app(StockService::class);
        $stock->transferToInstallation($armazem->id, $instalacao->id, 5.0, $user->id);
        $stockInstalacao = $instalacao->stockInstallations()->first();
        $stock->consumeInstallationStock($stockInstalacao->id, 2.0, $user->id);

        $registo = Activity::where('log_name', Auditoria::CANAL_STOCK)
            ->where('description', 'like', '%Consumo%')
            ->first();

        $this->assertNotNull($registo);
        $this->assertStringContainsString('Leiria', $registo->description);
        $this->assertStringContainsString('pH menos', $registo->description);
    }

    /**
     * A coluna "Alterações" formata três formatos diferentes de `properties`
     * (diff de model, log manual, e vazio). Renderizar a página com os três ao
     * mesmo tempo é o que apanha um erro de coluna antes de ele chegar a produção.
     */
    public function test_pagina_de_activity_log_renderiza_todos_os_formatos(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $produto = Product::create(['name' => 'Cloro granulado', 'unidade' => 'kg', 'active' => true]);
        $produto->update(['name' => 'Cloro granulado 90%']);

        Auditoria::sistema('Arquivamento de registos diários falhou.', ['erro' => 'timeout']);
        Auditoria::registar(Auditoria::CANAL_DEFINICOES, 'Alterou as definições do sistema (1 campo).', [
            'old' => ['ph_min' => 6.5],
            'attributes' => ['ph_min' => 6.9],
        ], autor: $admin);
        Auditoria::registar(Auditoria::CANAL_AUTH, 'Iniciou sessão no sistema.', autor: $admin);
        activity()->log('Registo antigo sem propriedades.');

        $this->assertGreaterThanOrEqual(5, Activity::count());

        $this->actingAs($admin);

        Livewire::test(ListActivitylog::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Activity::latest('id')->get())
            ->assertSee('Arquivamento de registos diários falhou.')
            ->assertSee('Nome: Cloro granulado → Cloro granulado 90%')
            ->assertSee('Erro: timeout');
    }

    public function test_alvo_apagado_continua_visivel_no_trilho(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $produto = Product::create(['name' => 'Produto Temporário', 'unidade' => 'kg', 'active' => true]);
        $produto->delete();

        $this->actingAs($admin);

        Livewire::test(ListActivitylog::class)
            ->assertSuccessful()
            ->assertSee('Produto');
    }

    public function test_evento_de_sistema_nao_tem_autor(): void
    {
        Auditoria::sistema('Sincronização Hanna falhou: autenticação recusada.', ['erro' => 'timeout']);

        $registo = Activity::where('log_name', Auditoria::CANAL_SISTEMA)->first();

        $this->assertNotNull($registo);
        $this->assertNull($registo->causer_id);
        $this->assertSame('timeout', $registo->properties['erro']);
    }
}
