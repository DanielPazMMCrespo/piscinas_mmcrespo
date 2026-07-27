<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    public function test_user_wants_notification_returns_defaults(): void
    {
        $user = User::factory()->create();

        // Defaults: push is true, mail is false for incident_created
        $this->assertTrue($user->wantsNotification('incident_created', 'push'));
        $this->assertFalse($user->wantsNotification('incident_created', 'mail'));

        // Defaults: mail is true for nao_conformidade
        $this->assertTrue($user->wantsNotification('nao_conformidade', 'mail'));
    }

    public function test_user_can_update_notification_preferences(): void
    {
        $user = User::factory()->create();
        $user->assignRole('tecnico');

        $user->update([
            'notification_preferences' => [
                'incident_created' => ['push' => false, 'mail' => true],
            ],
        ]);

        $this->assertFalse($user->wantsNotification('incident_created', 'push'));
        $this->assertTrue($user->wantsNotification('incident_created', 'mail'));
    }

    public function test_admin_compliance_notifications_are_mandatory(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Even if admin turns push/mail off in preferences array, wantsNotification returns true for compliance
        $admin->update([
            'notification_preferences' => [
                'nao_conformidade' => ['push' => false, 'mail' => false],
            ],
        ]);

        $this->assertTrue($admin->wantsNotification('nao_conformidade', 'push'));
        $this->assertTrue($admin->wantsNotification('nao_conformidade', 'mail'));
    }
}
