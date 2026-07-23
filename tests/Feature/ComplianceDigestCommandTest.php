<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Notifications\ResumoConformidadeNotification;
use App\Services\AlertasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ComplianceDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        AlertasService::resetMemo();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function poolForaDosLimites(): Pool
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::factory()->create(['installation_id' => $inst->id, 'active' => true]);

        DailyRecord::factory()->create([
            'pool_id' => $pool->id,
            'registado_em' => now(),
            'ph' => 9.5,
        ]);

        return $pool;
    }

    public function test_envia_resumo_no_horario_configurado_quando_ha_piscina_nao_conforme(): void
    {
        Carbon::setTestNow('2026-01-01 08:00:00');
        Notification::fake();

        $this->poolForaDosLimites();

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $gestor = User::factory()->create();
        $gestor->assignRole(UserRole::GESTOR);
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $this->artisan('notificacoes:resumo-conformidade')->assertExitCode(0);

        Notification::assertSentTo($admin, ResumoConformidadeNotification::class);
        Notification::assertSentTo($gestor, ResumoConformidadeNotification::class);
        Notification::assertNotSentTo($tecnico, ResumoConformidadeNotification::class);
    }

    public function test_nao_envia_nada_quando_todas_as_piscinas_estao_conformes(): void
    {
        Carbon::setTestNow('2026-01-01 08:00:00');
        Notification::fake();

        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::factory()->create(['installation_id' => $inst->id, 'active' => true]);
        DailyRecord::factory()->create(['pool_id' => $pool->id, 'registado_em' => now()]);

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $this->artisan('notificacoes:resumo-conformidade')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_nao_envia_fora_dos_horarios_configurados(): void
    {
        Carbon::setTestNow('2026-01-01 09:15:00');
        Notification::fake();

        $this->poolForaDosLimites();

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $this->artisan('notificacoes:resumo-conformidade')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_nao_duplica_envio_no_mesmo_slot(): void
    {
        Carbon::setTestNow('2026-01-01 08:00:00');
        Notification::fake();

        $this->poolForaDosLimites();

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $this->artisan('notificacoes:resumo-conformidade')->assertExitCode(0);
        AlertasService::resetMemo();
        $this->artisan('notificacoes:resumo-conformidade')->assertExitCode(0);

        Notification::assertSentToTimes($admin, ResumoConformidadeNotification::class, 1);
    }
}
