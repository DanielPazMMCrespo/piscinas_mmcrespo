<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Widgets\CloroPhChartWidget;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnaliseParametrosSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $nadador;

    private User $admin;

    private Pool $assignedPool;

    private Pool $unassignedPool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ([UserRole::ADMIN, UserRole::NADADOR_SALVADOR] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $inst = Installation::factory()->create();
        $this->assignedPool = Pool::factory()->create(['installation_id' => $inst->id, 'name' => 'Piscina Nadador']);
        $this->unassignedPool = Pool::factory()->create(['installation_id' => $inst->id, 'name' => 'Piscina Privada']);

        $this->nadador = User::factory()->create();
        $this->nadador->assignRole(UserRole::NADADOR_SALVADOR);
        $this->nadador->piscinas()->attach($this->assignedPool->id);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);

        DailyRecord::factory()->create([
            'pool_id' => $this->unassignedPool->id,
            'user_id' => $this->admin->id,
            'registado_em' => now(),
            'ph' => 7.30,
            'cloro_livre' => 1.50,
        ]);

        Cache::flush();
    }

    public function test_swimmer_cannot_access_unassigned_pool_data_via_idor(): void
    {
        // Nadador tenta manipular poolSelecionada para a piscina não atribuída
        $component = Livewire::actingAs($this->nadador)
            ->test(CloroPhChartWidget::class)
            ->set('poolSelecionada', (string) $this->unassignedPool->id);

        $payload = $component->instance()->getChartPayload();
        $this->assertEmpty($payload, 'O payload deve ser vazio para piscina não atribuída ao nadador-salvador.');

        $tableRows = $component->instance()->getTableRows();
        $this->assertEmpty($tableRows['manual']);
        $this->assertEmpty($tableRows['sensor']);
    }

    public function test_admin_can_access_any_active_pool_data(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(CloroPhChartWidget::class)
            ->set('poolSelecionada', (string) $this->unassignedPool->id);

        $payload = $component->instance()->getChartPayload();
        $this->assertNotEmpty($payload);
        $this->assertEquals($this->unassignedPool->nomeCompleto(' — '), $payload['titulo']);
    }
}
