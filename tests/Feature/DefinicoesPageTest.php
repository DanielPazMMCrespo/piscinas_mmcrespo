<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Pages\Definicoes;
use App\Models\CustomBroadcast;
use App\Models\User;
use App\Notifications\CustomBroadcastNotification;
use App\Notifications\PedidoAtivacaoPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use NotificationChannels\WebPush\PushSubscription;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DefinicoesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::GESTOR, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::TECNICO, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::NADADOR_SALVADOR, 'guard_name' => 'web']);
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

        $response = $this->get('/admin/definicoes');
        $response->assertStatus(200);
    }

    public function test_admin_can_create_broadcast(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $this->actingAs($admin);

        Livewire::test(Definicoes::class)
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

        Notification::fake();

        Livewire::test(Definicoes::class)
            ->set('destinoTipo', 'cargo')
            ->set('destinoCargo', UserRole::TECNICO)
            ->set('manualTitulo', 'Teste Manual')
            ->set('manualCorpo', 'Corpo do Teste Manual')
            ->call('enviarManual')
            ->assertHasNoErrors();

        Notification::assertSentTo(
            $tecnico,
            CustomBroadcastNotification::class
        );
    }

    public function test_admin_can_send_manual_notification_to_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $this->actingAs($admin);

        Notification::fake();

        Livewire::test(Definicoes::class)
            ->set('destinoTipo', 'utilizador')
            ->set('destinoUtilizador', $tecnico->id)
            ->set('manualTitulo', 'Teste Individual')
            ->set('manualCorpo', 'Corpo do Teste Individual')
            ->call('enviarManual')
            ->assertHasNoErrors();

        Notification::assertSentTo(
            $tecnico,
            CustomBroadcastNotification::class
        );
    }

    public function test_admin_can_request_activation_from_inactive_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $this->actingAs($admin);

        Notification::fake();

        Livewire::test(Definicoes::class)
            ->call('pedirAtivacao', $tecnico->id)
            ->assertHasNoErrors();

        Notification::assertSentTo($tecnico, PedidoAtivacaoPushNotification::class);
    }

    public function test_admin_cannot_request_activation_from_already_active_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);
        $tecnico->updatePushSubscription('https://push.example/endpoint', 'p256dh-key', 'auth-key');

        $this->actingAs($admin);

        Notification::fake();

        Livewire::test(Definicoes::class)
            ->call('pedirAtivacao', $tecnico->id)
            ->assertHasNoErrors();

        Notification::assertNotSentTo($tecnico, PedidoAtivacaoPushNotification::class);
    }

    public function test_admin_can_clear_subscriptions_of_a_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);
        $tecnico->updatePushSubscription('https://push.example/endpoint', 'p256dh-key', 'auth-key');

        $this->actingAs($admin);

        Livewire::test(Definicoes::class)
            ->call('limparSubscricoesUtilizador', $tecnico->id)
            ->assertHasNoErrors();

        $this->assertSame(0, PushSubscription::where('subscribable_id', $tecnico->id)->count());
    }

    public function test_swimmer_only_sees_system_broadcast_preferences(): void
    {
        $swimmer = User::factory()->create();
        $swimmer->assignRole(UserRole::NADADOR_SALVADOR);
        $swimmer->update([
            'notification_preferences' => [
                'incident_created' => ['push' => true, 'mail' => false],
                'custom_broadcast' => ['push' => false, 'mail' => false],
            ],
        ]);

        $this->actingAs($swimmer);

        Livewire::test(Definicoes::class)
            ->assertFormFieldExists('notification_preferences.custom_broadcast.push', 'preferencesForm')
            ->assertFormFieldDoesNotExist('notification_preferences.incident_created.push', 'preferencesForm')
            ->fillForm([
                'notification_preferences' => [
                    'custom_broadcast' => ['push' => true, 'mail' => true],
                ],
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
