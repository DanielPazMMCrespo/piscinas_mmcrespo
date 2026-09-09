<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\IncidentStatus;
use App\Constants\IncidentType;
use App\Constants\UserRole;
use App\Filament\Resources\IncidentResource;
use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Filament\Widgets\IncidentChatWidget;
use App\Models\Incident;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IncidentPresetsAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $nadador;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ([UserRole::ADMIN, UserRole::TECNICO, UserRole::NADADOR_SALVADOR] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $inst = Installation::factory()->create();
        $this->pool = Pool::factory()->create(['installation_id' => $inst->id]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);

        $this->nadador = User::factory()->create();
        $this->nadador->assignRole(UserRole::NADADOR_SALVADOR);
        $this->nadador->piscinas()->attach($this->pool->id);
    }

    public function test_incident_eloquent_query_eager_loads_relationships(): void
    {
        $this->actingAs($this->admin);
        $query = IncidentResource::getEloquentQuery();
        $eagerLoads = array_keys($query->getEagerLoads());

        $this->assertContains('piscina.encerramentos', $eagerLoads);
        $this->assertContains('piscina.instalacao', $eagerLoads);
        $this->assertContains('utilizador', $eagerLoads);
        $this->assertContains('resolvidoPor', $eagerLoads);
    }

    public function test_swimmer_only_queries_own_incidents(): void
    {
        Incident::create([
            'installation_id' => $this->pool->installation_id,
            'pool_id' => $this->pool->id,
            'user_id' => $this->admin->id,
            'type' => IncidentType::OUTRO,
            'status' => IncidentStatus::ABERTO,
            'descricao' => 'Incidente do admin',
            'ocorreu_em' => now(),
        ]);

        $ownIncident = Incident::create([
            'installation_id' => $this->pool->installation_id,
            'pool_id' => $this->pool->id,
            'user_id' => $this->nadador->id,
            'type' => IncidentType::OUTRO,
            'status' => IncidentStatus::ABERTO,
            'descricao' => 'Incidente do nadador',
            'ocorreu_em' => now(),
        ]);

        $this->actingAs($this->nadador);
        $incidents = IncidentResource::getEloquentQuery()->get();

        $this->assertCount(1, $incidents);
        $this->assertEquals($ownIncident->id, $incidents->first()->id);
    }

    public function test_table_resolver_action_resolves_incident(): void
    {
        $incident = Incident::create([
            'installation_id' => $this->pool->installation_id,
            'pool_id' => $this->pool->id,
            'user_id' => $this->nadador->id,
            'type' => IncidentType::QUALIDADE_AGUA,
            'status' => IncidentStatus::ABERTO,
            'descricao' => 'Água turva',
            'ocorreu_em' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListIncidents::class)
            ->callTableAction('resolver', $incident, [
                'resolucao' => 'Tratamento de choque efetuado e parâmetros normalizados.',
            ]);

        $this->assertEquals(IncidentStatus::RESOLVIDO, $incident->fresh()->status);
        $this->assertEquals($this->admin->id, $incident->fresh()->resolvido_por);
    }

    public function test_chat_widget_allows_messaging(): void
    {
        $incident = Incident::create([
            'installation_id' => $this->pool->installation_id,
            'pool_id' => $this->pool->id,
            'user_id' => $this->admin->id,
            'type' => IncidentType::AVARIA_EQUIPAMENTO,
            'status' => IncidentStatus::ABERTO,
            'descricao' => 'Ruído na bomba',
            'ocorreu_em' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(IncidentChatWidget::class, ['record' => $incident])
            ->set('texto', 'Técnico já a caminho do local.')
            ->call('enviarMensagem')
            ->assertSet('texto', '');

        $this->assertDatabaseHas('incident_messages', [
            'incident_id' => $incident->id,
            'user_id' => $this->admin->id,
            'texto' => 'Técnico já a caminho do local.',
        ]);
    }
}
