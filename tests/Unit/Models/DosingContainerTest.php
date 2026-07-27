<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Constants\UserRole;
use App\Models\DosingContainer;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Notifications\DosingContainerLowAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DosingContainerTest extends TestCase
{
    use RefreshDatabase;

    private function container(array $attrs = []): DosingContainer
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => 26,
            'temp_max' => 27,
            'volume' => 900,
            'active' => true,
        ]);

        return DosingContainer::create(array_merge([
            'pool_id' => $pool->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 20000,
            'restante_ml' => 20000,
            'alerta_percent' => 20,
        ], $attrs));
    }

    public function test_percentagem_and_nivel(): void
    {
        $c = $this->container(['restante_ml' => 10000]);
        $this->assertSame(50.0, $c->percentagem());
        $this->assertSame('ok', $c->nivel());

        $c->restante_ml = 6000; // 30% < alerta*2 (40)
        $this->assertSame('aviso', $c->nivel());

        $c->restante_ml = 3000; // 15% < alerta (20)
        $this->assertSame('critico', $c->nivel());
        $this->assertTrue($c->estaBaixo());
    }

    public function test_percentagem_null_without_capacity(): void
    {
        $c = $this->container(['capacidade_ml' => null, 'restante_ml' => 5000]);
        $this->assertNull($c->percentagem());
        $this->assertSame('desconhecido', $c->nivel());
        $this->assertFalse($c->estaBaixo());
    }

    public function test_consumir_decrements_and_logs(): void
    {
        $c = $this->container(['restante_ml' => 1000]);

        $descontado = $c->consumir(300);

        $this->assertSame(300.0, $descontado);
        $this->assertSame(700.0, (float) $c->fresh()->restante_ml);
        $this->assertDatabaseHas('dosing_container_logs', [
            'dosing_container_id' => $c->id,
            'tipo_movimento' => 'consumo',
            'quantidade_ml' => -300,
        ]);
    }

    public function test_consumir_never_goes_negative(): void
    {
        $c = $this->container(['restante_ml' => 100]);

        $descontado = $c->consumir(500);

        $this->assertSame(100.0, $descontado);
        $this->assertSame(0.0, (float) $c->fresh()->restante_ml);
    }

    public function test_notificar_se_baixo_alerts_once_per_episode(): void
    {
        Notification::fake();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::TECNICO, 'guard_name' => 'web']);
        User::factory()->create()->assignRole(UserRole::ADMIN);

        $c = $this->container(['restante_ml' => 1000]); // 5% < 20% -> baixo

        $c->notificarSeBaixo();
        Notification::assertSentTimes(DosingContainerLowAlert::class, 1);
        $this->assertNotNull($c->fresh()->alerta_notificado_em);

        // Mesmo episódio: não volta a notificar.
        $c->notificarSeBaixo();
        Notification::assertSentTimes(DosingContainerLowAlert::class, 1);
    }

    public function test_notificar_se_baixo_silent_when_level_ok(): void
    {
        Notification::fake();

        $c = $this->container(['restante_ml' => 20000]); // 100%

        $c->notificarSeBaixo();
        Notification::assertNothingSent();
        $this->assertNull($c->fresh()->alerta_notificado_em);
    }

    public function test_reabastecer_resets_level_and_alert(): void
    {
        $c = $this->container(['restante_ml' => 500, 'alerta_notificado_em' => now()]);

        $c->reabastecer(20000, null, 'Bidão novo');

        $fresh = $c->fresh();
        $this->assertSame(20000.0, (float) $fresh->restante_ml);
        $this->assertNull($fresh->alerta_notificado_em);
        $this->assertNotNull($fresh->reabastecido_em);
        $this->assertDatabaseHas('dosing_container_logs', [
            'dosing_container_id' => $c->id,
            'tipo_movimento' => 'reabastecimento',
        ]);
    }
}
