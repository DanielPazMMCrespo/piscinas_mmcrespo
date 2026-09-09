<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\IncidentStatus;
use App\Constants\IncidentType;
use App\Constants\UserRole;
use App\Filament\Widgets\PainelPiscinasWidget;
use App\Filament\Widgets\QuadroOperacionalWidget;
use App\Models\Incident;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardErgonomicsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tecnicoAutorizado;

    private User $tecnicoNaoAutorizado;

    private User $nadador;

    private Pool $piscinaA;

    private Pool $piscinaB;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ([UserRole::ADMIN, UserRole::TECNICO, UserRole::NADADOR_SALVADOR] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $inst = Installation::factory()->create();
        $this->piscinaA = Pool::factory()->create(['installation_id' => $inst->id, 'name' => 'Piscina A']);
        $this->piscinaB = Pool::factory()->create(['installation_id' => $inst->id, 'name' => 'Piscina B']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);

        $this->tecnicoAutorizado = User::factory()->create();
        $this->tecnicoAutorizado->assignRole(UserRole::TECNICO);
        $this->tecnicoAutorizado->piscinas()->attach($this->piscinaA->id);

        $this->tecnicoNaoAutorizado = User::factory()->create();
        $this->tecnicoNaoAutorizado->assignRole(UserRole::TECNICO);
        $this->tecnicoNaoAutorizado->piscinas()->attach($this->piscinaB->id);

        $this->nadador = User::factory()->create();
        $this->nadador->assignRole(UserRole::NADADOR_SALVADOR);
        $this->nadador->piscinas()->attach($this->piscinaA->id);

        Cache::flush();
    }

    public function test_admin_can_resolve_incident_from_quadro_operacional(): void
    {
        $incident = Incident::create([
            'installation_id' => $this->piscinaA->installation_id,
            'pool_id' => $this->piscinaA->id,
            'user_id' => $this->admin->id,
            'type' => IncidentType::AVARIA_EQUIPAMENTO,
            'status' => IncidentStatus::ABERTO,
            'descricao' => 'Bomba avariada',
            'ocorreu_em' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(QuadroOperacionalWidget::class)
            ->callAction('resolveIncident', [
                'resolucao' => 'Substituído condensador da bomba.',
            ], [
                'id' => $incident->id,
            ]);

        $this->assertEquals(IncidentStatus::RESOLVIDO, $incident->fresh()->status);
        $this->assertEquals('Substituído condensador da bomba.', $incident->fresh()->resolucao);
        $this->assertEquals($this->admin->id, $incident->fresh()->resolvido_por);
    }

    public function test_unauthorized_technician_cannot_resolve_incident_of_another_pool(): void
    {
        $incident = Incident::create([
            'installation_id' => $this->piscinaA->installation_id,
            'pool_id' => $this->piscinaA->id,
            'user_id' => $this->admin->id,
            'type' => IncidentType::AVARIA_EQUIPAMENTO,
            'status' => IncidentStatus::ABERTO,
            'descricao' => 'Bomba avariada',
            'ocorreu_em' => now(),
        ]);

        Livewire::actingAs($this->tecnicoNaoAutorizado)
            ->test(QuadroOperacionalWidget::class)
            ->callAction('resolveIncident', [
                'resolucao' => 'Tentativa indevida de resolução',
            ], [
                'id' => $incident->id,
            ]);

        // Incidente deve permanecer ABERTO porque o técnico não tem acesso à Piscina A
        $this->assertEquals(IncidentStatus::ABERTO, $incident->fresh()->status);
    }

    public function test_swimmer_cannot_view_quadro_operacional_widget(): void
    {
        $this->actingAs($this->nadador);
        $this->assertFalse(QuadroOperacionalWidget::canView());
    }

    public function test_painel_piscinas_renders_tactile_actions_for_all_pools(): void
    {
        Livewire::actingAs($this->admin)
            ->test(PainelPiscinasWidget::class)
            ->assertSee('Registar Água')
            ->assertSee('Piscina A')
            ->assertSee('Piscina B');
    }
}
