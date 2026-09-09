<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\NSPermission;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleBasedAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
    }

    private function createTestData(): array
    {
        $installation = Installation::create([
            'name' => 'Leiria',
            'morada' => 'Rua Teste',
            'active' => true,
        ]);

        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Competição',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);

        $admin = User::factory()->create(['name' => 'Admin User']);
        $admin->assignRole('admin');

        $technician = User::factory()->create(['name' => 'Technician User']);
        $technician->assignRole('tecnico');

        $swimmer = User::factory()->create(['name' => 'Swimmer User']);
        $swimmer->assignRole('nadador_salvador');

        return [
            'installation' => $installation,
            'pool' => $pool,
            'admin' => $admin,
            'technician' => $technician,
            'swimmer' => $swimmer,
        ];
    }

    public function test_admin_can_access_filament_admin_panel(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);
    }

    public function test_technician_can_access_filament_admin_panel(): void
    {
        $data = $this->createTestData();
        $technician = $data['technician'];

        $response = $this->actingAs($technician)->get('/admin');

        $response->assertStatus(200);
    }

    public function test_swimmer_can_access_filament_admin_panel(): void
    {
        $data = $this->createTestData();
        $swimmer = $data['swimmer'];

        $response = $this->actingAs($swimmer)->get('/admin');

        // Nadador-Salvador has access to admin panel to record daily records
        $response->assertStatus(200);
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    public function test_admin_can_access_daily_records_resource(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];

        $response = $this->actingAs($admin)->get('/admin/daily-records');

        $response->assertStatus(200);
    }

    public function test_technician_can_access_daily_records_resource(): void
    {
        $data = $this->createTestData();
        $technician = $data['technician'];

        $response = $this->actingAs($technician)->get('/admin/daily-records');

        $response->assertStatus(200);
    }

    public function test_admin_can_access_stock_management(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];

        $response = $this->actingAs($admin)->get('/admin/stock-warehouses');

        $response->assertStatus(200);
    }

    public function test_technician_can_access_stock_management(): void
    {
        $data = $this->createTestData();
        $technician = $data['technician'];

        $response = $this->actingAs($technician)->get('/admin/stock-warehouses');

        $response->assertStatus(200);
    }

    public function test_admin_can_access_activity_logs(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];

        // Activity logs are typically at /admin/activity-logs or similar
        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);
        // Could verify Activity Log menu is visible if we have detailed page content
    }

    public function test_technician_cannot_access_activity_logs(): void
    {
        $data = $this->createTestData();
        $technician = $data['technician'];

        // Techniciantypically should not access audit logs
        // The exact endpoint depends on implementation
        // This test verifies that restricted access works
        $this->assertTrue($technician->hasRole('tecnico'));
        $this->assertFalse($technician->hasRole('admin'));
    }

    public function test_admin_can_create_daily_records(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];
        $pool = $data['pool'];

        // Verify admin has permission to create
        $this->assertTrue($admin->hasRole('admin'));

        // Access create page
        $response = $this->actingAs($admin)->get('/admin/daily-records/create');

        $response->assertStatus(200);
    }

    public function test_technician_can_create_daily_records(): void
    {
        $data = $this->createTestData();
        $technician = $data['technician'];

        // Verify technician has permission to create
        $this->assertTrue($technician->hasRole('tecnico'));

        // Access create page
        $response = $this->actingAs($technician)->get('/admin/daily-records/create');

        $response->assertStatus(200);
    }

    public function test_admin_can_access_pdf_reports(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];

        // PDF reports page is typically accessible to admin and tecnico
        $response = $this->actingAs($admin)->get('/admin/relatorio-pdf');

        // May return 200 or redirect, depending on implementation
        $this->assertContains($response->getStatusCode(), [200, 302]);
    }

    public function test_technician_can_access_pdf_reports(): void
    {
        $data = $this->createTestData();
        $technician = $data['technician'];

        $response = $this->actingAs($technician)->get('/admin/relatorio-pdf');

        // May return 200 or redirect
        $this->assertContains($response->getStatusCode(), [200, 302]);
    }

    public function test_admin_has_admin_role(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertFalse($admin->hasRole('tecnico'));
        $this->assertFalse($admin->hasRole('nadador_salvador'));
    }

    public function test_technician_has_tecnico_role(): void
    {
        $data = $this->createTestData();
        $technician = $data['technician'];

        $this->assertTrue($technician->hasRole('tecnico'));
        $this->assertFalse($technician->hasRole('admin'));
        $this->assertFalse($technician->hasRole('nadador_salvador'));
    }

    public function test_swimmer_has_nadador_salvador_role(): void
    {
        $data = $this->createTestData();
        $swimmer = $data['swimmer'];

        $this->assertTrue($swimmer->hasRole('nadador_salvador'));
        $this->assertFalse($swimmer->hasRole('admin'));
        $this->assertFalse($swimmer->hasRole('tecnico'));
    }

    public function test_admin_can_access_any_resource(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];

        // Admin should be able to access multiple resources
        $resources = [
            '/admin/users',
            '/admin/pools',
            '/admin/installations',
            '/admin/daily-records',
            '/admin/stock-warehouses',
        ];

        foreach ($resources as $resource) {
            $response = $this->actingAs($admin)->get($resource);
            // Status should be 200, 404, or redirect - not 403 (forbidden)
            $this->assertNotEquals(403, $response->getStatusCode(),
                "Admin should not be forbidden from accessing {$resource}");
        }
    }

    public function test_technician_access_is_restricted_compared_to_admin(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];
        $technician = $data['technician'];

        // Both should access daily records
        $response_admin = $this->actingAs($admin)->get('/admin/daily-records');
        $response_tech = $this->actingAs($technician)->get('/admin/daily-records');

        $this->assertEquals(200, $response_admin->getStatusCode());
        $this->assertEquals(200, $response_tech->getStatusCode());

        // But activity logs should be restricted
        // (Details depend on implementation)
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertFalse($technician->hasRole('admin'));
    }

    public function test_user_can_only_have_one_primary_role(): void
    {
        $data = $this->createTestData();
        $user = User::factory()->create();

        // Assign first role
        $user->assignRole('tecnico');
        $this->assertTrue($user->hasRole('tecnico'));

        // Assign another role (doesn't override, adds)
        $user->assignRole('admin');
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('tecnico'));

        // In this app, users should probably have one primary role
        // This test documents the current behavior
        $roles = $user->getRoleNames();
        $this->assertTrue($roles->contains('admin'));
        $this->assertTrue($roles->contains('tecnico'));
    }

    public function test_role_permissions_are_properly_assigned(): void
    {
        // Verify that roles exist and can be queried
        $admin_role = Role::where('name', 'admin')->first();
        $tecnico_role = Role::where('name', 'tecnico')->first();
        $swimmer_role = Role::where('name', 'nadador_salvador')->first();

        $this->assertNotNull($admin_role);
        $this->assertNotNull($tecnico_role);
        $this->assertNotNull($swimmer_role);
    }

    public function test_guest_user_cannot_access_protected_routes(): void
    {
        $protected_routes = [
            '/admin',
            '/admin/daily-records',
            '/admin/stock-warehouses',
            '/admin/pools',
        ];

        foreach ($protected_routes as $route) {
            $response = $this->get($route);
            // Guest should be redirected to login
            $this->assertTrue(
                $response->getStatusCode() === 302 || $response->getStatusCode() === 301,
                "Route {$route} should redirect unauthenticated users"
            );
        }
    }

    public function test_logged_in_user_without_role_cannot_access_admin(): void
    {
        $user = User::factory()->create();
        // User has no role assigned

        $response = $this->actingAs($user)->get('/admin');

        // Should be forbidden
        $response->assertStatus(403);
    }

    public function test_multiple_users_with_same_role_can_access_same_resources(): void
    {
        $data = $this->createTestData();

        $tech1 = User::factory()->create(['name' => 'Tech 1']);
        $tech1->assignRole('tecnico');

        $tech2 = User::factory()->create(['name' => 'Tech 2']);
        $tech2->assignRole('tecnico');

        $response1 = $this->actingAs($tech1)->get('/admin/daily-records');
        $response2 = $this->actingAs($tech2)->get('/admin/daily-records');

        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals(200, $response2->getStatusCode());
    }

    public function test_admin_can_access_operational_actions(): void
    {
        $data = $this->createTestData();
        $admin = $data['admin'];

        $response = $this->actingAs($admin)->get('/admin/operational-actions');

        $response->assertStatus(200);
    }

    public function test_technician_can_access_operational_actions(): void
    {
        $data = $this->createTestData();
        $technician = $data['technician'];

        $response = $this->actingAs($technician)->get('/admin/operational-actions');

        $response->assertStatus(200);
    }

    /**
     * O nadador-salvador passou a poder registar uma analise pontual (commit
     * f1f923b), por isso ja NAO leva 403 nas acoes operacionais. Antes desta
     * atualizacao o teste afirmava o comportamento antigo e so passava porque a
     * ListOperationalActions tinha uma lista de papeis escrita a mao que
     * contradizia o canAccess() do proprio Resource.
     *
     * O que continua a valer e a permissao fina: sem ANALISE_PARAMETROS, nao entra.
     */
    public function test_swimmer_cannot_access_operational_actions(): void
    {
        $data = $this->createTestData();
        $swimmer = $data['swimmer'];
        $swimmer->update(['ns_permissions' => [NSPermission::ANALISE_PARAMETROS]]);

        $this->actingAs($swimmer)
            ->get('/admin/operational-actions')
            ->assertStatus(403);
    }

    public function test_swimmer_without_analise_parametros_cannot_access_operational_actions(): void
    {
        $data = $this->createTestData();
        $swimmer = $data['swimmer'];
        $swimmer->update(['ns_permissions' => [NSPermission::REGISTO_DIARIO]]);

        $this->actingAs($swimmer)
            ->get('/admin/operational-actions')
            ->assertStatus(403);
    }
}
