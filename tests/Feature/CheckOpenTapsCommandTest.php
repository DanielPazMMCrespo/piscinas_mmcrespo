<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\TapAlert;
use App\Models\User;
use App\Notifications\TorneiraAbertaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CheckOpenTapsCommandTest extends TestCase
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

    private function torneiraAberta(int $horasAtras): TapAlert
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::factory()->create(['installation_id' => $inst->id, 'active' => true]);
        $user = User::factory()->create();

        return TapAlert::create([
            'pool_id' => $pool->id,
            'opened_by' => $user->id,
            'opened_at' => now()->subHours($horasAtras),
        ]);
    }

    public function test_notifica_admin_e_tecnico_quando_torneira_excede_o_limite(): void
    {
        Notification::fake();

        $tap = $this->torneiraAberta(5);
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $this->artisan('torneiras:verificar-abertas')->assertExitCode(0);

        Notification::assertSentTo($admin, TorneiraAbertaNotification::class);
        Notification::assertSentTo($tecnico, TorneiraAbertaNotification::class);

        $this->assertNotNull($tap->fresh()->notified_at);
    }

    public function test_nao_notifica_antes_do_limite_configurado(): void
    {
        Notification::fake();

        $this->torneiraAberta(2);
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $this->artisan('torneiras:verificar-abertas')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_nao_notifica_duas_vezes_o_mesmo_episodio(): void
    {
        Notification::fake();

        $this->torneiraAberta(5);
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $this->artisan('torneiras:verificar-abertas')->assertExitCode(0);
        $this->artisan('torneiras:verificar-abertas')->assertExitCode(0);

        Notification::assertSentToTimes($admin, TorneiraAbertaNotification::class, 1);
    }
}
