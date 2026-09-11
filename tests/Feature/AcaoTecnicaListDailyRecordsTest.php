<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource\Pages\ListDailyRecords;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AcaoTecnicaListDailyRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'gestor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);

        $installation = Installation::factory()->create(['name' => 'Complexo Test']);
        $this->pool = Pool::factory()->create([
            'installation_id' => $installation->id,
            'name' => 'Competicao',
            'active' => true,
        ]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);
    }

    public function test_admin_can_render_list_daily_records_page(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ListDailyRecords::class)
            ->assertSuccessful();
    }

    public function test_nova_acao_tecnica_action_exists_and_is_visible(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ListDailyRecords::class)
            ->assertActionExists('novaAcaoTecnica')
            ->assertActionVisible('novaAcaoTecnica');
    }

    public function test_nova_acao_tecnica_can_create_lavagem_filtro(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ListDailyRecords::class)
            ->callAction('novaAcaoTecnica', data: [
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
                'registado_em' => now()->format('Y-m-d H:i'),
                'filtro_nome' => 'Filtro Principal',
                'duracao_min' => 3,
                'pressao_antes_bar' => 1.5,
                'pressao_depois_bar' => 0.8,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
        ]);
    }

    public function test_nova_acao_tecnica_can_create_contador(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ListDailyRecords::class)
            ->callAction('novaAcaoTecnica', data: [
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_CONTADOR,
                'registado_em' => now()->format('Y-m-d H:i'),
                'contador_valor' => 12345.67,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_CONTADOR,
        ]);
    }
}
