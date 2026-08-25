<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\PoolClosureResource;
use App\Filament\Resources\PoolClosureResource\Pages\EditPoolClosure;
use App\Models\PoolClosure;
use App\Models\User;
use App\Services\PlanoParagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PlanoParagemAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (UserRole::all() as $cargo) {
            Role::findOrCreate($cargo);
        }
    }

    private function criarUser(string $cargo): User
    {
        $user = User::factory()->create();
        $user->assignRole($cargo);

        return $user;
    }

    public function test_nadador_salvador_nao_tem_acesso_ao_plano_de_paragem(): void
    {
        $ns = $this->criarUser(UserRole::NADADOR_SALVADOR);
        $this->actingAs($ns);

        $closure = PoolClosure::factory()->create();

        $this->get(PoolClosureResource::getUrl('edit', ['record' => $closure]))
            ->assertForbidden();
    }

    public function test_tecnico_tem_acesso_ao_plano_de_paragem_mas_nao_pode_eliminar_o_encerramento(): void
    {
        $tecnico = $this->criarUser(UserRole::TECNICO);
        $this->actingAs($tecnico);

        $closure = PoolClosure::factory()->create();
        app(PlanoParagemService::class)->criarPlano($closure, $tecnico);

        $this->get(PoolClosureResource::getUrl('edit', ['record' => $closure]))
            ->assertSuccessful();

        Livewire::test(EditPoolClosure::class, ['record' => $closure->getRouteKey()])
            ->assertActionHidden('delete');
    }

    public function test_admin_tem_acesso_total_incluindo_eliminar(): void
    {
        $admin = $this->criarUser(UserRole::ADMIN);
        $this->actingAs($admin);

        $closure = PoolClosure::factory()->create();
        app(PlanoParagemService::class)->criarPlano($closure, $admin);

        $this->get(PoolClosureResource::getUrl('edit', ['record' => $closure]))
            ->assertSuccessful();

        Livewire::test(EditPoolClosure::class, ['record' => $closure->getRouteKey()])
            ->assertActionVisible('delete');
    }
}
