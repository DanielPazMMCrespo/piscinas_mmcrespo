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

    public function test_resolving_incident_posts_system_message_and_notifies_reporter(): void
    {
        Notification::fake();

        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'aberto',
        ]);

        $this->actingAs($tecnico);

        Livewire::test(\App\Filament\Resources\IncidentResource\Pages\ListIncidents::class)
            ->callTableAction('resolver', $incidente, data: [
                'resolucao' => 'Junta do filtro substituída, sem fugas após 30 min de teste.',
            ]);

        $incidente->refresh();
        $this->assertSame('resolvido', $incidente->status);

        $mensagem = $incidente->mensagens()->latest('id')->first();
        $this->assertSame(IncidentMessage::TIPO_SISTEMA, $mensagem->tipo);
        $this->assertStringContainsString('Resolvido', $mensagem->texto);
        $this->assertStringContainsString('Junta do filtro substituída', $mensagem->texto);

        Notification::assertSentTo($ns, \App\Notifications\IncidentMessageNotification::class);
    }

    public function test_reporter_message_notifies_admin_and_tecnico_not_other_ns(): void
    {
        Notification::fake();

        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $outroNs = User::factory()->create();
        $outroNs->assignRole(UserRole::NADADOR_SALVADOR);
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'aberto',
        ]);

        $this->actingAs($ns);

        Livewire::test(\App\Filament\Widgets\IncidentChatWidget::class, ['record' => $incidente])
            ->set('texto', 'A situação está a agravar-se.')
            ->call('enviarMensagem');

        $this->assertSame(1, $incidente->mensagens()->count());

        Notification::assertSentTo($admin, \App\Notifications\IncidentMessageNotification::class);
        Notification::assertNotSentTo($outroNs, \App\Notifications\IncidentMessageNotification::class);
    }

    public function test_new_message_reopens_resolved_incident(): void
    {
        Notification::fake();

        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'resolvido',
            'resolvido_em' => now(),
            'resolvido_por' => $tecnico->id,
            'resolucao' => 'Junta substituída.',
        ]);

        $this->actingAs($ns);

        Livewire::test(\App\Filament\Widgets\IncidentChatWidget::class, ['record' => $incidente])
            ->set('texto', 'Voltou a haver fuga.')
            ->call('enviarMensagem');

        $incidente->refresh();

        $this->assertSame('aberto', $incidente->status);
        $this->assertNull($incidente->resolvido_em);
        $this->assertNull($incidente->resolvido_por);
        $this->assertNull($incidente->resolucao);

        $textos = $incidente->mensagens()->pluck('texto')->all();
        $this->assertContains('Voltou a haver fuga.', $textos);
        $this->assertContains('Reaberto automaticamente após nova mensagem.', $textos);
    }

    public function test_blank_message_is_ignored(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'outro',
            'descricao' => 'Problema qualquer',
            'status' => 'aberto',
        ]);

        $this->actingAs($ns);

        Livewire::test(\App\Filament\Widgets\IncidentChatWidget::class, ['record' => $incidente])
            ->set('texto', '   ')
            ->call('enviarMensagem');

        $this->assertSame(0, $incidente->mensagens()->count());
    }

    public function test_delete_incident_works(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'aberto',
        ]);

        IncidentMessage::create([
            'incident_id' => $incidente->id,
            'user_id' => $ns->id,
            'tipo' => IncidentMessage::TIPO_MENSAGEM,
            'texto' => 'Mensagem de teste',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $this->actingAs($admin);

        $incidente->delete();
        $this->assertDatabaseMissing('incidents', ['id' => $incidente->id]);
        $this->assertDatabaseMissing('incident_messages', ['incident_id' => $incidente->id]);
    }

    public function test_delete_incident_via_filament_action_works(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $ns->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'aberto',
        ]);

        IncidentMessage::create([
            'incident_id' => $incidente->id,
            'user_id' => $ns->id,
            'tipo' => IncidentMessage::TIPO_MENSAGEM,
            'texto' => 'Mensagem de teste',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $this->actingAs($admin);

        Livewire::test(\App\Filament\Resources\IncidentResource\Pages\EditIncident::class, [
            'record' => $incidente->getKey(),
        ])
        ->callAction('delete');

        $this->assertDatabaseMissing('incidents', ['id' => $incidente->id]);
        $this->assertDatabaseMissing('incident_messages', ['incident_id' => $incidente->id]);
    }
}
