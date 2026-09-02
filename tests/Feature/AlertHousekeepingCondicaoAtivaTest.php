<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\AlertType;
use App\Constants\UserRole;
use App\Models\AlertState;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\User;
use App\Services\AlertasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AlertHousekeepingCondicaoAtivaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (UserRole::all() as $cargo) {
            Role::findOrCreate($cargo);
        }

        AlertasService::resetMemo();
        cache()->flush();
    }

    public function test_nao_apaga_resolvido_manual_cuja_condicao_ainda_esta_ativa(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $piscina = Pool::factory()->create();

        DailyRecord::factory()->create([
            'pool_id' => $piscina->id,
            'user_id' => $admin->id,
            'registado_em' => now(),
            'ph' => 9.5,
            'e_correcao' => false,
        ]);

        $alertas = app(AlertasService::class)->calcular($admin)['alertas'];
        $chave = collect($alertas)->keys()
            ->first(fn (string $k) => str_starts_with($k, AlertType::FORA_LIMITES.'|'));

        $this->assertNotNull($chave, 'Pré-condição: o registo tem de gerar um alerta ativo.');

        $estado = AlertState::create([
            'alert_key' => $chave,
            'status' => 'resolvido',
            'moved_by' => $admin->id,
            'moved_at' => now()->subDays(10),
        ]);

        Artisan::call('alerts:housekeeping');

        $this->assertDatabaseHas('alert_states', ['id' => $estado->id]);
    }

    public function test_apaga_resolvido_manual_cuja_condicao_ja_desapareceu(): void
    {
        $estado = AlertState::create([
            'alert_key' => 'fora_limites|999|2020-01-01',
            'status' => 'resolvido',
            'moved_at' => now()->subDays(10),
        ]);

        Artisan::call('alerts:housekeeping');

        $this->assertDatabaseMissing('alert_states', ['id' => $estado->id]);
    }
}
