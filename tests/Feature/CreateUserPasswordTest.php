<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CreateUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ([UserRole::ADMIN, UserRole::TECNICO] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_utilizador_criado_manualmente_nao_recebe_a_password_literal_password(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $tecnicoRole = Role::where('name', UserRole::TECNICO)->firstOrFail();

        Notification::fake();

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Novo',
                'last_name' => 'Técnico',
                'email' => 'novo.tecnico@mmcrespo.pt',
                'roles' => [$tecnicoRole->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $criado = User::where('email', 'novo.tecnico@mmcrespo.pt')->firstOrFail();

        $this->assertFalse(Hash::check('password', $criado->password));
        $this->assertTrue((bool) $criado->must_change_password);

        Notification::assertSentTo($criado, ResetPassword::class);
    }
}
