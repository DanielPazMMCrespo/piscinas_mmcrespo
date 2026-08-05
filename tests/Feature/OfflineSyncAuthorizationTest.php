<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OfflineSyncAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $installation = Installation::factory()->create();
        $this->pool = Pool::factory()->create(['installation_id' => $installation->id]);
    }

    private function payload(): array
    {
        return [
            'records' => [
                [
                    'offline_id' => 'abc-123',
                    'data' => [
                        'registado_em' => now()->toDateString(),
                        'pools' => [
                            $this->pool->id => [
                                'ns_ph' => 7.2,
                                'ns_cloro_livre' => 1.0,
                                'ns_cloro_total' => 1.2,
                                'ns_temperatura' => 27.0,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_gestor_nao_pode_criar_registos_diarios_via_sync_offline(): void
    {
        $gestor = User::factory()->create();
        $gestor->assignRole(UserRole::GESTOR);

        $this->actingAs($gestor)
            ->postJson('/offline-sync/daily-records', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('daily_records', 0);
    }

    public function test_utilizador_sem_cargo_valido_nao_pode_criar_registos_diarios_via_sync_offline(): void
    {
        $semCargo = User::factory()->create();

        $this->actingAs($semCargo)
            ->postJson('/offline-sync/daily-records', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('daily_records', 0);
    }

    public function test_nadador_salvador_sem_permissao_de_registo_diario_nao_pode_sincronizar(): void
    {
        $ns = User::factory()->create([
            'ns_permissions' => [], // explicitamente sem nenhuma permissão
        ]);
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $ns->piscinas()->attach($this->pool->id);

        $this->actingAs($ns)
            ->postJson('/offline-sync/daily-records', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('daily_records', 0);
    }

    public function test_nadador_salvador_com_permissao_e_piscina_atribuida_continua_a_sincronizar(): void
    {
        $ns = User::factory()->create([
            'ns_permissions' => [NSPermission::REGISTO_DIARIO],
        ]);
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $ns->piscinas()->attach($this->pool->id);

        $this->actingAs($ns)
            ->postJson('/offline-sync/daily-records', $this->payload())
            ->assertOk();

        $this->assertDatabaseCount('daily_records', 1);
    }

    public function test_tecnico_com_password_por_mudar_e_redirecionado_em_vez_de_sincronizar(): void
    {
        $tecnico = User::factory()->create(['must_change_password' => true]);
        $tecnico->assignRole(UserRole::TECNICO);

        $this->actingAs($tecnico)
            ->post('/offline-sync/daily-records', $this->payload())
            ->assertRedirect('/primeiro-acesso');

        $this->assertDatabaseCount('daily_records', 0);
    }
}
