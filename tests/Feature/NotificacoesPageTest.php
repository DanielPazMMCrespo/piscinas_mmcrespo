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

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
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
}
