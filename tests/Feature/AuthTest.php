<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_admin_can_access_filament_panel(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    public function test_post_to_admin_login_redirects_cleanly_to_login_get(): void
    {
        $response = $this->post('/admin/login');

        $response->assertRedirect('/admin/login');
    }

    public function test_generic_login_route_redirects_to_admin_login(): void
    {
        $responseGet = $this->get('/login');
        $responseGet->assertRedirect('/admin/login');

        $responsePost = $this->post('/login');
        $responsePost->assertRedirect('/admin/login');
    }
}
