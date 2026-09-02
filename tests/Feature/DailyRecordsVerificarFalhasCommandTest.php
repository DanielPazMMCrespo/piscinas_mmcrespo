<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `ProcessDailyRecordAfterCreate::failed()` avisa por registo, mas nada
 * varria `failed_jobs` à procura de um quadro mais largo (ex.: vários
 * registos a falhar seguidos). O comando `daily-records:verificar-falhas`
 * (routes/console.php, agendado a cada 15 min) fecha essa lacuna.
 */
class DailyRecordsVerificarFalhasCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);

        // A cache "forever" do dedup persiste entre métodos de teste no
        // driver 'array' (é só por processo, não por teste) — sem isto o
        // segundo teste herdava o "último id visto" do primeiro.
        Cache::forget('daily_records_falhas_ultimo_id');
    }

    private function inserirFalha(string $queue = 'daily-records'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessDailyRecordAfterCreate']),
            'exception' => 'RuntimeException: falha simulada',
            'failed_at' => now(),
        ]);
    }

    public function test_avisa_admins_quando_ha_falhas_novas_na_fila_daily_records(): void
    {
        Notification::fake();

        $this->inserirFalha();

        $this->artisan('daily-records:verificar-falhas')->assertExitCode(0);

        Notification::assertSentTo($this->admin, DatabaseNotification::class);
    }

    public function test_nao_avisa_por_falhas_de_outra_fila(): void
    {
        Notification::fake();

        $this->inserirFalha(queue: 'sensor-sync');

        $this->artisan('daily-records:verificar-falhas')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_nao_avisa_duas_vezes_pela_mesma_falha(): void
    {
        Notification::fake();

        $this->inserirFalha();

        $this->artisan('daily-records:verificar-falhas')->assertExitCode(0);
        $this->artisan('daily-records:verificar-falhas')->assertExitCode(0);

        Notification::assertSentToTimes($this->admin, DatabaseNotification::class, 1);
    }
}
