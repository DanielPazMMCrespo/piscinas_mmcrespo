<?php declare(strict_types=1);
 
namespace Tests\Feature;
 
use App\Constants\UserRole;
use App\Models\User;
use App\Models\CustomBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;
use App\Filament\Pages\Notificacoes;
 
class NotificacoesPageTest extends TestCase
{
    use RefreshDatabase;
 
    protected function setUp(): void
    {
        parent::setUp();
 
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => UserRole::GESTOR, 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => UserRole::TECNICO, 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => UserRole::NADADOR_SALVADOR, 'guard_name' => 'web']);
    }
 
    public function test_admin_can_open_notificacoes_page_with_broadcasts(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
 
        // Create a broadcast
        CustomBroadcast::create([
            'titulo' => 'Aviso Teste',
            'corpo' => 'Corpo do aviso',
            'cargos' => [UserRole::ADMIN],
            'tipo_agendamento' => CustomBroadcast::TIPO_UNICO,
            'enviar_em' => now()->addDay(),
        ]);
 
        $this->actingAs($admin);
 
        $response = $this->get('/admin/notificacoes');
        $response->assertStatus(200);
    }
 
    public function test_admin_can_create_broadcast(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
 
        $this->actingAs($admin);
 
        Livewire::test(Notificacoes::class)
            ->callTableAction('novo_aviso', null, [
                'titulo' => 'Novo de Teste',
                'corpo' => 'Mensagem de teste',
                'cargos' => [UserRole::GESTOR],
                'tipo_agendamento' => CustomBroadcast::TIPO_UNICO,
                'enviar_em' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertHasNoErrors();
 
        $this->assertDatabaseHas('custom_broadcasts', [
            'titulo' => 'Novo de Teste',
        ]);
    }

    public function test_admin_can_send_manual_notification_to_role(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $this->actingAs($admin);

        \Illuminate\Support\Facades\Notification::fake();

        Livewire::test(Notificacoes::class)
            ->set('destinoTipo', 'cargo')
            ->set('destinoCargo', UserRole::TECNICO)
            ->set('manualTitulo', 'Teste Manual')
            ->set('manualCorpo', 'Corpo do Teste Manual')
            ->call('enviarManual')
            ->assertHasNoErrors();

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $tecnico,
            \App\Notifications\CustomBroadcastNotification::class
        );
    }

    public function test_admin_can_send_manual_notification_to_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $this->actingAs($admin);

        \Illuminate\Support\Facades\Notification::fake();

        Livewire::test(Notificacoes::class)
            ->set('destinoTipo', 'utilizador')
            ->set('destinoUtilizador', $tecnico->id)
            ->set('manualTitulo', 'Teste Individual')
            ->set('manualCorpo', 'Corpo do Teste Individual')
            ->call('enviarManual')
            ->assertHasNoErrors();

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $tecnico,
            \App\Notifications\CustomBroadcastNotification::class
        );
    }

    public function test_swimmer_only_sees_system_broadcast_preferences(): void
    {
        $swimmer = User::factory()->create();
        $swimmer->assignRole(UserRole::NADADOR_SALVADOR);
        $swimmer->update([
            'notification_preferences' => [
                'incident_created' => ['push' => true, 'mail' => false],
                'custom_broadcast' => ['push' => false, 'mail' => false],
            ]
        ]);

        $this->actingAs($swimmer);

        Livewire::test(Notificacoes::class)
            ->assertFormFieldExists('notification_preferences.custom_broadcast.push', 'preferencesForm')
            ->assertFormFieldDoesNotExist('notification_preferences.incident_created.push', 'preferencesForm')
            ->fillForm([
                'notification_preferences' => [
                    'custom_broadcast' => ['push' => true, 'mail' => true]
                ]
            ], 'preferencesForm')
            ->call('savePreferences')
            ->assertHasNoErrors();

        $swimmer->refresh();
        
        $this->assertTrue($swimmer->notification_preferences['custom_broadcast']['push']);
        $this->assertTrue($swimmer->notification_preferences['custom_broadcast']['mail']);
        
        $this->assertTrue($swimmer->notification_preferences['incident_created']['push']);
        $this->assertFalse($swimmer->notification_preferences['incident_created']['mail']);
    }
}
