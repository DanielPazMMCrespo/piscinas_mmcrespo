<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\IncidentStatus;
use App\Constants\IncidentType;
use App\Constants\UserRole;
use App\Filament\Resources\IncidentResource\Pages\CreateIncident;
use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Models\Incident;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IncidentErgonomicsTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    private User $nadador;

    private Installation $instalacao;

    private Pool $piscina;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::TECNICO, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::NADADOR_SALVADOR, 'guard_name' => 'web']);

        $this->instalacao = Installation::create([
            'name' => 'Complexo Aquático Leiria',
            'morada' => 'Av. Principal',
            'active' => true,
        ]);

        $this->piscina = Pool::create([
            'installation_id' => $this->instalacao->id,
            'name' => 'Piscina Competição',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 28.0,
            'volume' => 1000,
            'active' => true,
        ]);

        $this->tecnico = User::factory()->create(['name' => 'Técnico Manutenção']);
        $this->tecnico->assignRole(UserRole::TECNICO);

        $this->nadador = User::factory()->create(['name' => 'Nadador Salvador']);
        $this->nadador->assignRole(UserRole::NADADOR_SALVADOR);
        $this->nadador->piscinas()->attach($this->piscina->id);
    }

    public function test_list_incidents_renders_tabs(): void
    {
        $this->actingAs($this->tecnico);

        Incident::create([
            'installation_id' => $this->instalacao->id,
            'pool_id' => $this->piscina->id,
            'user_id' => $this->tecnico->id,
            'ocorreu_em' => now(),
            'type' => IncidentType::AVARIA_EQUIPAMENTO,
            'descricao' => 'Bomba com ruído anormal',
            'status' => IncidentStatus::ABERTO,
        ]);

        Livewire::test(ListIncidents::class)
            ->assertSuccessful()
            ->assertSee('Abertos')
            ->assertSee('Hoje')
            ->assertSee('Resolvidos')
            ->assertSee('Todos');
    }

    public function test_create_incident_with_quick_type_and_defaults(): void
    {
        $this->actingAs($this->nadador);

        Livewire::test(CreateIncident::class)
            ->assertSuccessful()
            ->assertFormFieldExists('type')
            ->assertFormFieldExists('descricao')
            ->fillForm([
                'type' => IncidentType::QUALIDADE_AGUA,
                'descricao' => 'Contaminação fecal detetada na água. Procedido ao isolamento imediato da piscina.',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('incidents', [
            'type' => IncidentType::QUALIDADE_AGUA,
            'descricao' => 'Contaminação fecal detetada na água. Procedido ao isolamento imediato da piscina.',
            'status' => IncidentStatus::ABERTO,
            'user_id' => $this->nadador->id,
        ]);
    }

    public function test_swimmer_defaults_to_assigned_installation_and_pool(): void
    {
        $this->actingAs($this->nadador);

        Livewire::test(CreateIncident::class)
            ->assertFormSet([
                'installation_id' => $this->instalacao->id,
                'pool_id' => $this->piscina->id,
            ]);
    }

    public function test_resolver_action_marks_incident_as_resolved(): void
    {
        $this->actingAs($this->tecnico);

        $incident = Incident::create([
            'installation_id' => $this->instalacao->id,
            'pool_id' => $this->piscina->id,
            'user_id' => $this->tecnico->id,
            'ocorreu_em' => now(),
            'type' => IncidentType::AVARIA_EQUIPAMENTO,
            'descricao' => 'Disjuntor disparado',
            'status' => IncidentStatus::ABERTO,
        ]);

        Livewire::test(ListIncidents::class)
            ->callTableAction('resolver', $incident, [
                'resolucao' => 'Equipamento inspecionado, reiniciado e a funcionar normalmente.',
            ])
            ->assertHasNoTableActionErrors();

        $incident->refresh();

        $this->assertEquals(IncidentStatus::RESOLVIDO, $incident->status);
        $this->assertNotNull($incident->resolvido_em);
        $this->assertEquals($this->tecnico->id, $incident->resolvido_por);
        $this->assertEquals('Equipamento inspecionado, reiniciado e a funcionar normalmente.', $incident->resolucao);
    }
}
