<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Jobs\ProcessDailyRecordAfterCreate;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * O job esgota as 3 tentativas em silêncio: `failed()` só escrevia num
 * `Log::error` (storage/logs é efémero no Railway, ninguém no painel o vê).
 * Sem aviso, stock nunca é descontado, a torneira nunca abre/fecha e a
 * não-conformidade nunca chega a um admin — para sempre, sem ninguém saber.
 */
class ProcessDailyRecordAfterCreateFailedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DailyRecord $registo;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);

        $installation = Installation::factory()->create();
        $pool = Pool::factory()->create(['installation_id' => $installation->id]);
        $autor = User::factory()->create();

        $this->registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $autor->id,
            'registado_em' => now(),
            'ph' => 7.2,
        ]);
    }

    public function test_avisa_os_admins_quando_o_job_esgota_as_tentativas(): void
    {
        Notification::fake();

        // Força a falha terminal do job (como o worker faz depois de
        // esgotar `tries=3` sem sucesso) sem depender do backoff real.
        $job = new ProcessDailyRecordAfterCreate($this->registo->id, $this->admin->id);
        $job->failed(new RuntimeException('Falha simulada no processamento pós-registo'));

        Notification::assertSentTo($this->admin, DatabaseNotification::class);
    }
}
