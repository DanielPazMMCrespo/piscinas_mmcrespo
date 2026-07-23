<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
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
        Role::firstOrCreate(['name' => 'gestor',          'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico',         'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
    }

    public function test_admin_can_create_invitation(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service = app(InvitationService::class);
        $invitation = $service->send('novo@test.pt', 'tecnico', $admin);

        $this->assertDatabaseHas('user_invitations', [
            'email' => 'novo@test.pt',
            'role' => 'tecnico',
        ]);
        $this->assertNull($invitation->accepted_at);
        $this->assertTrue($invitation->isPending());
    }

    public function test_send_throws_if_email_already_exists_as_user(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
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

        $service = app(InvitationService::class);
        $invitation = $service->send('novo@test.pt', 'tecnico', $admin);

        $user = $service->accept($invitation, [
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'password' => 'password123',
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

        $service = app(InvitationService::class);
        $invitation = $service->send('novo@test.pt', 'tecnico', $admin);

        $service->accept($invitation, [
            'first_name' => 'Ana',
            'last_name' => 'Silva',
        ]);

        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertFalse($invitation->fresh()->isPending());
    }

    public function test_accepted_user_can_login(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service = app(InvitationService::class);
        $invitation = $service->send('novo@test.pt', 'tecnico', $admin);

        $user = $service->accept($invitation, [
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'password' => 'password123',
        ]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'novo@test.pt', 'password' => 'password123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_send_stores_pool_ids_for_nadador_salvador(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $instalacao = Installation::factory()->create(['name' => 'Leiria']);
        $poolA = Pool::factory()->create(['installation_id' => $instalacao->id, 'name' => 'Competição']);
        $poolB = Pool::factory()->create(['installation_id' => $instalacao->id, 'name' => 'Lazer']);

        $invitation = app(InvitationService::class)->send(
            'ns@test.pt',
            'nadador_salvador',
            $admin,
            [$poolA->id, $poolB->id],
        );

        $this->assertEqualsCanonicalizing([$poolA->id, $poolB->id], $invitation->pool_ids);
    }

    public function test_accept_syncs_pools_to_user(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $instalacao = Installation::factory()->create(['name' => 'Leiria']);
        $poolA = Pool::factory()->create(['installation_id' => $instalacao->id, 'name' => 'Competição']);
        $poolB = Pool::factory()->create(['installation_id' => $instalacao->id, 'name' => 'Lazer']);

        $service = app(InvitationService::class);
        $invitation = $service->send('ns@test.pt', 'nadador_salvador', $admin, [$poolA->id, $poolB->id]);

        $user = $service->accept($invitation, [
            'first_name' => 'Rui',
            'last_name' => 'Costa',
            'pin' => '1234',
        ]);

        $this->assertEqualsCanonicalizing(
            [$poolA->id, $poolB->id],
            $user->piscinas()->pluck('pools.id')->all(),
        );
    }

    public function test_pool_ids_null_for_non_ns_role(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $instalacao = Installation::factory()->create(['name' => 'Leiria']);
        $pool = Pool::factory()->create(['installation_id' => $instalacao->id, 'name' => 'Competição']);

        $service = app(InvitationService::class);
        $invitation = $service->send('tec@test.pt', 'tecnico', $admin, [$pool->id]);

        $this->assertNull($invitation->pool_ids);

        $user = $service->accept($invitation, [
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'password' => 'password123',
        ]);

        $this->assertCount(0, $user->piscinas()->get());
    }

    public function test_acao_convidar_monta_para_admin_e_visivel_para_gestor(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Montar a ação exercita o schema do form (CheckboxList de piscinas + Get)
        // e apanha erros de import/render na página.
        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertActionVisible('convidar')
            ->mountAction('convidar')
            ->assertActionMounted('convidar');

        $gestor = User::factory()->create();
        $gestor->assignRole('gestor');

        Livewire::actingAs($gestor)
            ->test(ListUsers::class)
            ->assertActionVisible('convidar');
    }

    public function test_find_valid_returns_null_for_expired_token(): void
    {
        $invitation = UserInvitation::create([
            'email' => 'old@test.pt',
            'role' => 'tecnico',
            'token' => hash('sha256', 'expiredtoken'),
            'invited_by_id' => User::factory()->create()->id,
            'expires_at' => now()->subHour(),
        ]);

        $this->assertNull(UserInvitation::findValid('expiredtoken'));
    }
}
