<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\OperationalActionResource;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OperationalActionNadadorSalvadorTest extends TestCase
{
    use RefreshDatabase;

    private User $nadadorSalvador;

    private User $nadadorSalvadorSemPermissao;

    private Pool $pool;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);

        $this->installation = Installation::create([
            'name' => 'Complexo Aquático',
            'morada' => 'Av. Principal',
            'active' => true,
        ]);

        $this->pool = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Piscina Principal',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 28.0,
            'volume' => 1000,
            'active' => true,
        ]);

        // NS com permissão de análise de parâmetros
        $this->nadadorSalvador = User::factory()->create();
        $this->nadadorSalvador->assignRole(UserRole::NADADOR_SALVADOR);
        $this->nadadorSalvador->piscinas()->attach($this->pool->id);
        $this->nadadorSalvador->update([
            'ns_permissions' => [NSPermission::ANALISE_PARAMETROS],
        ]);

        // NS sem permissão de análise de parâmetros
        $this->nadadorSalvadorSemPermissao = User::factory()->create();
        $this->nadadorSalvadorSemPermissao->assignRole(UserRole::NADADOR_SALVADOR);
        $this->nadadorSalvadorSemPermissao->piscinas()->attach($this->pool->id);
        $this->nadadorSalvadorSemPermissao->update([
            'ns_permissions' => [NSPermission::REGISTO_DIARIO],
        ]);
    }

    public function test_ns_with_analise_parametros_cannot_access_operational_action_resource(): void
    {
        $this->actingAs($this->nadadorSalvador);

        $this->assertFalse(OperationalActionResource::canAccess());
        $this->assertFalse(OperationalActionResource::canCreate());
    }

    public function test_ns_without_analise_parametros_cannot_access_operational_action_resource(): void
    {
        $this->actingAs($this->nadadorSalvadorSemPermissao);

        $this->assertFalse(OperationalActionResource::canAccess());
        $this->assertFalse(OperationalActionResource::canCreate());
    }

    public function test_ns_cannot_render_list_page(): void
    {
        $this->actingAs($this->nadadorSalvador);

        Livewire::test(OperationalActionResource\Pages\ListOperationalActions::class)
            ->assertForbidden();
    }

    public function test_ns_cannot_render_create_page(): void
    {
        $this->actingAs($this->nadadorSalvador);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->assertForbidden();
    }

    public function test_ns_without_analise_parametros_cannot_render_list_page(): void
    {
        $this->actingAs($this->nadadorSalvadorSemPermissao);

        Livewire::test(OperationalActionResource\Pages\ListOperationalActions::class)
            ->assertForbidden();
    }

    public function test_ns_without_analise_parametros_cannot_render_create_page(): void
    {
        $this->actingAs($this->nadadorSalvadorSemPermissao);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->assertForbidden();
    }
}
