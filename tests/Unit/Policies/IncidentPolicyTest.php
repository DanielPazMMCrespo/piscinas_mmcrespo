<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Constants\UserRole;
use App\Models\User;
use App\Policies\IncidentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IncidentPolicyTest extends TestCase
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

    public function test_all_four_roles_can_create_incidents(): void
    {
        $policy = new IncidentPolicy();

        foreach (UserRole::all() as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertTrue(
                $policy->create($user),
                "Role '{$role}' deveria conseguir criar incidentes"
            );
        }
    }
}
