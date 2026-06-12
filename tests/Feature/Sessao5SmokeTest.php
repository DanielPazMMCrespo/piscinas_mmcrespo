<?php

namespace Tests\Feature;

use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\StockWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Smoke tests da Sessão 5: páginas afetadas abrem sem erro e as permissões do
 * novo role "gestor" (só leitura) são respeitadas a nível de rota.
 */
class Sessao5SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'gestor', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function utilizador(string $role): User
    {
        $u = User::create([
            'name' => ucfirst($role),
            'email' => $role.'@teste.pt',
            'password' => bcrypt('password'),
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function piscinaComStock(): Pool
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $inst->id, 'name' => 'Competição', 'type' => 'Interior',
            'temp_min' => 26, 'temp_max' => 27, 'volume' => 900, 'active' => true,
        ]);
        $prod = Product::create([
            'name' => 'Hipoclorito', 'unidade' => 'L', 'categoria' => 'Desinfeção',
            'concentracao' => 13, 'dose_recomendada' => 1.5, 'active' => true,
        ]);
        StockWarehouse::create(['product_id' => $prod->id, 'quantity' => 100]);

        return $pool;
    }

    public function test_tecnico_abre_pagina_criar_registo_diario(): void
    {
        $this->piscinaComStock();
        $tecnico = $this->utilizador('tecnico');

        $this->actingAs($tecnico)
            ->get('/admin/daily-records/create')
            ->assertSuccessful();
    }

    public function test_tecnico_abre_stock_armazem(): void
    {
        $this->piscinaComStock();
        $tecnico = $this->utilizador('tecnico');

        $this->actingAs($tecnico)
            ->get('/admin/stock-warehouses')
            ->assertSuccessful();
    }

    public function test_gestor_nao_pode_criar_registo_diario(): void
    {
        $this->piscinaComStock();
        $gestor = $this->utilizador('gestor');

        $this->actingAs($gestor)
            ->get('/admin/daily-records/create')
            ->assertForbidden();
    }

    public function test_gestor_nao_acede_stock_armazem(): void
    {
        $this->piscinaComStock();
        $gestor = $this->utilizador('gestor');

        $this->actingAs($gestor)
            ->get('/admin/stock-warehouses')
            ->assertForbidden();
    }

    public function test_gestor_ve_lista_de_registos_diarios(): void
    {
        $this->piscinaComStock();
        $gestor = $this->utilizador('gestor');

        // Gestor PODE consultar (só leitura).
        $this->actingAs($gestor)
            ->get('/admin/daily-records')
            ->assertSuccessful();
    }
}
