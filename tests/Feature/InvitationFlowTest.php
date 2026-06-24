<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInvitation;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin',           'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico',         'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador-salvador','guard_name' => 'web']);
    }

    public function test_admin_can_create_invitation(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service    = app(InvitationService::class);
        $invitation = $service->send('novo@test.pt', 'tecnico', $admin);

        $this->assertDatabaseHas('user_invitations', [
            'email' => 'novo@test.pt',
            'role'  => 'tecnico',
        ]);
        $this->assertNull($invitation->accepted_at);
        $this->assertTrue($invitation->isPending());
    }

    public function test_send_throws_if_email_already_exists_as_user(): void
    {
        Mail::fake();

        $admin    = User::factory()->create();
        $admin->assignRole('admin');
        $existing = User::factory()->create(['email' => 'existe@test.pt']);

        $this->expectException(\RuntimeException::class);

        app(InvitationService::class)->send('existe@test.pt', 'tecnico', $admin);
    }

    public function test_send_throws_if_pending_invitation_already_exists(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service = app(InvitationService::class);
        $service->send('novo@test.pt', 'tecnico', $admin);

        $this->expectException(\RuntimeException::class);

        $service->send('novo@test.pt', 'tecnico', $admin);
    }

    public function test_accepting_invitation_creates_user_with_correct_role(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service    = app(InvitationService::class);
        $invitation = $service->send('novo@test.pt', 'tecnico', $admin);

        $user = $service->accept($invitation, [
            'first_name' => 'Ana',
            'last_name'  => 'Silva',
            'password'   => 'password123',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'novo@test.pt']);
        $this->assertTrue($user->hasRole('tecnico'));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_accepting_invitation_marks_it_as_accepted(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service    = app(InvitationService::class);
        $invitation = $service->send('novo@test.pt', 'tecnico', $admin);

        $service->accept($invitation, [
            'first_name' => 'Ana',
            'last_name'  => 'Silva',
        ]);

        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertFalse($invitation->fresh()->isPending());
    }

    public function test_accepted_user_can_login(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service    = app(InvitationService::class);
        $invitation = $service->send('novo@test.pt', 'tecnico', $admin);

        $service->accept($invitation, [
            'first_name' => 'Ana',
            'last_name'  => 'Silva',
            'password'   => 'password123',
        ]);

        $response = $this->post('/admin/login', [
            'email'    => 'novo@test.pt',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
    }

    public function test_findValid_returns_null_for_expired_token(): void
    {
        $invitation = UserInvitation::create([
            'email'          => 'old@test.pt',
            'role'           => 'tecnico',
            'token'          => hash('sha256', 'expiredtoken'),
            'invited_by_id'  => User::factory()->create()->id,
            'expires_at'     => now()->subHour(),
        ]);

        $this->assertNull(UserInvitation::findValid('expiredtoken'));
    }
}
