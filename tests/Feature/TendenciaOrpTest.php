<?php

declare(strict_types=1);

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use App\Notifications\TendenciaAlertaNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (UserRole::all() as $cargo) {
        Role::findOrCreate($cargo);
    }

    cache()->flush();
    Carbon::setTestNow('2026-08-07 10:00:00');

    User::factory()->create()->assignRole(UserRole::ADMIN);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Cloro livre a descer 1,20 → 0,80 → 0,50: a previsão cai abaixo do mínimo legal. */
function piscinaComCloroADescer(): Pool
{
    $piscina = Pool::factory()->create(['orp_min' => 650, 'orp_max' => 850]);

    foreach ([1.20, 0.80, 0.50] as $i => $valor) {
        DailyRecord::factory()->create([
            'pool_id' => $piscina->id,
            'registado_em' => now()->copy()->subDays(3 - $i),
            'cloro_livre' => $valor,
            'cloro_total' => $valor + 0.2,
            'ph' => 7.40,
        ]);
    }

    return $piscina;
}

function leiturasOrp(Pool $piscina, array $valores): void
{
    foreach ($valores as $i => $orp) {
        SensorReading::create([
            'pool_id' => $piscina->id,
            'hanna_device_id' => 'BL132-TESTE',
            'lida_em' => now()->copy()->subDays(3 - $i),
            'ph' => 7.40,
            'orp' => $orp,
        ]);
    }
}

it('nao alerta quando o ORP subiu na mesma janela', function (): void {
    Notification::fake();

    $piscina = piscinaComCloroADescer();
    leiturasOrp($piscina, [700, 730, 760]);

    $this->artisan('tendencias:verificar')->assertSuccessful();

    Notification::assertNotSentTo(User::role(UserRole::ADMIN)->get(), TendenciaAlertaNotification::class);
});

it('nao alerta quando o ORP se manteve estavel e acima do minimo', function (): void {
    Notification::fake();

    $piscina = piscinaComCloroADescer();
    leiturasOrp($piscina, [720, 718, 715]);

    $this->artisan('tendencias:verificar')->assertSuccessful();

    Notification::assertNotSentTo(User::role(UserRole::ADMIN)->get(), TendenciaAlertaNotification::class);
});

it('alerta quando o ORP desceu a confirmar a tendencia', function (): void {
    Notification::fake();

    $piscina = piscinaComCloroADescer();
    leiturasOrp($piscina, [760, 700, 660]);

    $this->artisan('tendencias:verificar')->assertSuccessful();

    Notification::assertSentTo(
        User::role(UserRole::ADMIN)->get(),
        TendenciaAlertaNotification::class,
        function (TendenciaAlertaNotification $notificacao, array $canais, User $user) {
            $corpo = $notificacao->toDatabase($user)['body'] ?? '';

            return str_contains($corpo, 'ORP confirma a descida: 760 → 660 mV.');
        }
    );
});

it('alerta quando o ORP estavel ja esta abaixo do minimo da piscina', function (): void {
    Notification::fake();

    $piscina = piscinaComCloroADescer();
    leiturasOrp($piscina, [610, 608, 605]);

    $this->artisan('tendencias:verificar')->assertSuccessful();

    Notification::assertSentTimes(TendenciaAlertaNotification::class, 1);
});

it('alerta com ressalva quando nao ha ORP para confirmar', function (): void {
    Notification::fake();

    piscinaComCloroADescer();

    $this->artisan('tendencias:verificar')->assertSuccessful();

    Notification::assertSentTo(
        User::role(UserRole::ADMIN)->get(),
        TendenciaAlertaNotification::class,
        function (TendenciaAlertaNotification $notificacao, array $canais, User $user) {
            $corpo = $notificacao->toDatabase($user)['body'] ?? '';

            return str_contains($corpo, 'Sem ORP da sonda nesta janela para confirmar');
        }
    );
});

it('mantem a tendencia de pH independente do ORP', function (): void {
    Notification::fake();

    $piscina = Pool::factory()->create(['orp_min' => 650, 'orp_max' => 850]);

    foreach ([7.10, 6.95, 6.85] as $i => $valor) {
        DailyRecord::factory()->create([
            'pool_id' => $piscina->id,
            'registado_em' => now()->copy()->subDays(3 - $i),
            'ph' => $valor,
            'cloro_livre' => 1.00,
            'cloro_total' => 1.20,
        ]);
    }

    leiturasOrp($piscina, [700, 730, 760]);

    $this->artisan('tendencias:verificar')->assertSuccessful();

    Notification::assertSentTo(
        User::role(UserRole::ADMIN)->get(),
        TendenciaAlertaNotification::class,
        function (TendenciaAlertaNotification $notificacao, array $canais, User $user) {
            $corpo = $notificacao->toDatabase($user)['body'] ?? '';

            return ! str_contains($corpo, 'ORP');
        }
    );
});
