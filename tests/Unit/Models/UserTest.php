<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Constants\NotificationType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_user_can_have_admin_role(): void
    {
        $user = User::factory()->create();

        $user->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('tecnico'));
        $this->assertFalse($user->hasRole('nadador_salvador'));
    }

    public function test_user_can_have_tecnico_role(): void
    {
        $user = User::factory()->create();

        $user->assignRole('tecnico');

        $this->assertTrue($user->hasRole('tecnico'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('nadador_salvador'));
    }

    public function test_user_can_have_nadador_salvador_role(): void
    {
        $user = User::factory()->create();

        $user->assignRole('nadador_salvador');

        $this->assertTrue($user->hasRole('nadador_salvador'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('tecnico'));
    }

    public function test_user_can_have_multiple_roles(): void
    {
        $user = User::factory()->create();

        $user->assignRole(['admin', 'tecnico']);

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('tecnico'));
        $this->assertFalse($user->hasRole('nadador_salvador'));
    }

    public function test_user_can_sync_roles(): void
    {
        $user = User::factory()->create();

        $user->assignRole(['admin', 'tecnico']);
        $this->assertEquals(2, $user->roles()->count());

        $user->syncRoles('nadador_salvador');
        $this->assertEquals(1, $user->roles()->count());
        $this->assertTrue($user->hasRole('nadador_salvador'));
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_user_fillable_fields(): void
    {
        $user = User::create([
            'name' => 'João Silva',
            'email' => 'joao@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->assertEquals('João Silva', $user->name);
        $this->assertEquals('joao@example.com', $user->email);
    }

    public function test_user_email_is_unique(): void
    {
        $user1 = User::factory()->create(['email' => 'unique@example.com']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::create([
            'name' => 'Outro Utilizador',
            'email' => 'unique@example.com',
            'password' => bcrypt('password123'),
        ]);
    }

    public function test_quer_notificacao_defaults_to_true_when_unset(): void
    {
        $user = User::factory()->create(['notification_preferences' => null]);

        foreach (NotificationType::all() as $tipo) {
            $this->assertTrue($user->querNotificacao($tipo));
        }
    }

    public function test_quer_notificacao_respects_opt_out(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [NotificationType::INCIDENTE, NotificationType::STOCK_BAIXO],
        ]);

        $this->assertTrue($user->querNotificacao(NotificationType::INCIDENTE));
        $this->assertTrue($user->querNotificacao(NotificationType::STOCK_BAIXO));
        $this->assertFalse($user->querNotificacao(NotificationType::NAO_CONFORMIDADE));
        $this->assertFalse($user->querNotificacao(NotificationType::TORNEIRA_ABERTA));
    }
}
