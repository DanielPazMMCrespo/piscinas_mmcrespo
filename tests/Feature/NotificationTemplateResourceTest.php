<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\NotificationTemplateResource\Pages\CreateNotificationTemplate;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationTemplateResourceTest extends TestCase
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

    public function test_admin_pode_criar_um_modelo_de_mensagem(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $this->actingAs($admin);

        Livewire::test(CreateNotificationTemplate::class)
            ->fillForm([
                'nome' => 'Aviso de manutenção',
                'titulo' => 'Manutenção agendada',
                'corpo' => 'A piscina fecha às 18h para manutenção.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('notification_templates', [
            'nome' => 'Aviso de manutenção',
            'titulo' => 'Manutenção agendada',
        ]);
    }

    public function test_tecnico_nao_pode_aceder_ao_resource(): void
    {
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $this->assertFalse(\App\Filament\Resources\NotificationTemplateResource::canAccess());
        $this->actingAs($tecnico);
        $this->assertFalse(\App\Filament\Resources\NotificationTemplateResource::canAccess());
    }
}
