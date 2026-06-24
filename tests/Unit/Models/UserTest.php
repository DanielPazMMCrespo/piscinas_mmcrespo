<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

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

    public function test_user_has_haptic_enabled_by_default(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->haptic_enabled);
    }

    public function test_user_can_disable_haptic(): void
    {
        $user = User::factory()->create();
        $user->update(['haptic_enabled' => false]);
        $this->assertFalse($user->haptic_enabled);
    }
}
