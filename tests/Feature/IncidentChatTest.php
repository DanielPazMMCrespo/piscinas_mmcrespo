<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\IncidentResource\Pages\CreateIncident;
use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\Installation;
use App\Models\User;
use App\Notifications\IncidentCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IncidentChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_creating_incident_posts_system_message_and_notifies_admin_and_tecnico(): void
    {
        Notification::fake();

        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $this->actingAs($ns);

        Livewire::test(CreateIncident::class)
            ->fillForm([
                'installation_id' => $inst->id,
                'user_id' => $ns->id,
                'ocorreu_em' => now(),
                'type' => 'fuga_agua',
                'descricao' => 'Fuga junto ao filtro',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $incidente = Incident::first();
        $this->assertNotNull($incidente);

        $mensagem = IncidentMessage::where('incident_id', $incidente->id)->first();
        $this->assertNotNull($mensagem);
        $this->assertSame(IncidentMessage::TIPO_SISTEMA, $mensagem->tipo);
        $this->assertStringContainsString('Fuga junto ao filtro', $mensagem->texto);

        Notification::assertSentTo($admin, IncidentCreatedNotification::class);
        Notification::assertSentTo($tecnico, IncidentCreatedNotification::class);
        Notification::assertNotSentTo($ns, IncidentCreatedNotification::class);
    }
}
