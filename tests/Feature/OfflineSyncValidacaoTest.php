<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * O sync offline (`OfflineSyncController::storeDailyRecords`) passa o payload
 * direto para `DailyRecordService::createRecords()`, sem passar pelo wizard do
 * Filament — nenhuma das regras de negócio do formulário (limites por campo,
 * "contador só avança", "cloro total >= cloro livre") corria aqui. Um payload
 * offline entrava no livro sanitário sem nenhuma destas guardas.
 */
class OfflineSyncValidacaoTest extends TestCase
{
    use RefreshDatabase;

    private Pool $pool;

    private User $ns;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $installation = Installation::factory()->create();
        $this->pool = Pool::factory()->create(['installation_id' => $installation->id]);

        $this->ns = User::factory()->create([
            'ns_permissions' => [NSPermission::REGISTO_DIARIO],
        ]);
        $this->ns->assignRole(UserRole::NADADOR_SALVADOR);
        $this->ns->piscinas()->attach($this->pool->id);
    }

    private function payload(array $poolData): array
    {
        return [
            'records' => [
                [
                    'offline_id' => 'abc-123',
                    'data' => [
                        'registado_em' => now()->toDateString(),
                        'pools' => [
                            $this->pool->id => $poolData,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_rejeita_ns_ph_fora_do_intervalo_legal(): void
    {
        $payload = $this->payload([
            'ns_ph' => 99,
            'ns_cloro_livre' => 1.0,
            'ns_cloro_total' => 1.2,
            'ns_temperatura' => 27.0,
        ]);

        $this->actingAs($this->ns)
            ->postJson('/offline-sync/daily-records', $payload)
            ->assertStatus(422);

        $this->assertDatabaseMissing('daily_records', ['pool_id' => $this->pool->id]);
    }

    public function test_rejeita_contador_inferior_a_ultima_leitura(): void
    {
        DailyRecord::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->ns->id,
            'registado_em' => now()->subDay(),
            'contador_valor' => 100.0,
        ]);

        $payload = $this->payload([
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 1.0,
            'ns_cloro_total' => 1.2,
            'ns_temperatura' => 27.0,
            'contador_valor' => 50.0,
        ]);

        $this->actingAs($this->ns)
            ->postJson('/offline-sync/daily-records', $payload)
            ->assertStatus(422);

        $this->assertDatabaseMissing('daily_records', ['pool_id' => $this->pool->id, 'contador_valor' => 50.0]);
    }

    public function test_rejeita_cloro_total_inferior_ao_cloro_livre(): void
    {
        $payload = $this->payload([
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 5.0,
            'ns_cloro_total' => 1.0,
            'ns_temperatura' => 27.0,
        ]);

        $this->actingAs($this->ns)
            ->postJson('/offline-sync/daily-records', $payload)
            ->assertStatus(422);

        $this->assertDatabaseMissing('daily_records', ['pool_id' => $this->pool->id]);
    }
}
