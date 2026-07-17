<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\CustomBroadcastResource\Pages\CreateCustomBroadcast;
use App\Models\CustomBroadcast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomBroadcastResourceTest extends TestCase
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

    public function test_admin_pode_criar_um_envio_unico(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $this->actingAs($admin);

        Livewire::test(CreateCustomBroadcast::class)
            ->fillForm([
                'titulo' => 'Manutenção agendada',
                'corpo' => 'A piscina fecha às 18h.',
                'cargos' => [UserRole::ADMIN, UserRole::TECNICO],
                'tipo_agendamento' => CustomBroadcast::TIPO_UNICO,
                'enviar_em' => now()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $broadcast = CustomBroadcast::first();
        $this->assertNotNull($broadcast);
        $this->assertSame($admin->id, $broadcast->created_by);
        $this->assertSame([UserRole::ADMIN, UserRole::TECNICO], $broadcast->cargos);
    }

    public function test_admin_pode_criar_um_envio_diario(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $this->actingAs($admin);

        Livewire::test(CreateCustomBroadcast::class)
            ->fillForm([
                'titulo' => 'Bom dia',
                'corpo' => 'Lembrete diário.',
                'cargos' => [UserRole::NADADOR_SALVADOR],
                'tipo_agendamento' => CustomBroadcast::TIPO_DIARIO,
                'hora_diaria' => '08:00',
                'ativo' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('custom_broadcasts', [
            'tipo_agendamento' => CustomBroadcast::TIPO_DIARIO,
            'ativo' => true,
        ]);
    }
}
