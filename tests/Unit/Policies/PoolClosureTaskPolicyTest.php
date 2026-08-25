<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Constants\UserRole;
use App\Models\PoolClosureTask;
use App\Models\User;
use App\Policies\PoolClosureTaskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PoolClosureTaskPolicyTest extends TestCase
{
    use RefreshDatabase;

    private PoolClosureTaskPolicy $policy;

    private PoolClosureTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (UserRole::all() as $cargo) {
            Role::findOrCreate($cargo);
        }

        $this->policy = new PoolClosureTaskPolicy;
        $this->task = PoolClosureTask::factory()->create();
    }

    private function criarUtilizadorCom(string $cargo): User
    {
        $user = User::factory()->create();
        $user->assignRole($cargo);

        return $user;
    }

    public function test_view_any_e_view_permitidos_a_todos_os_cargos(): void
    {
        foreach (UserRole::all() as $cargo) {
            $user = $this->criarUtilizadorCom($cargo);

            $this->assertTrue($this->policy->viewAny($user), "viewAny falhou para {$cargo}");
            $this->assertTrue($this->policy->view($user, $this->task), "view falhou para {$cargo}");
        }
    }

    public function test_create_e_update_permitidos_a_admin_gestor_e_tecnico_e_negados_a_nadador_salvador(): void
    {
        $admin = $this->criarUtilizadorCom(UserRole::ADMIN);
        $gestor = $this->criarUtilizadorCom(UserRole::GESTOR);
        $tecnico = $this->criarUtilizadorCom(UserRole::TECNICO);
        $nadador = $this->criarUtilizadorCom(UserRole::NADADOR_SALVADOR);

        $this->assertTrue($this->policy->create($admin));
        $this->assertTrue($this->policy->update($admin, $this->task));

        $this->assertTrue($this->policy->create($gestor));
        $this->assertTrue($this->policy->update($gestor, $this->task));

        $this->assertTrue($this->policy->create($tecnico));
        $this->assertTrue($this->policy->update($tecnico, $this->task));

        $this->assertFalse($this->policy->create($nadador));
        $this->assertFalse($this->policy->update($nadador, $this->task));
    }

    public function test_delete_e_delete_any_permitidos_apenas_a_admin(): void
    {
        $admin = $this->criarUtilizadorCom(UserRole::ADMIN);
        $gestor = $this->criarUtilizadorCom(UserRole::GESTOR);
        $tecnico = $this->criarUtilizadorCom(UserRole::TECNICO);
        $nadador = $this->criarUtilizadorCom(UserRole::NADADOR_SALVADOR);

        $this->assertTrue($this->policy->delete($admin, $this->task));
        $this->assertTrue($this->policy->deleteAny($admin));

        $this->assertFalse($this->policy->delete($gestor, $this->task));
        $this->assertFalse($this->policy->deleteAny($gestor));

        $this->assertFalse($this->policy->delete($tecnico, $this->task));
        $this->assertFalse($this->policy->deleteAny($tecnico));

        $this->assertFalse($this->policy->delete($nadador, $this->task));
        $this->assertFalse($this->policy->deleteAny($nadador));
    }
}
