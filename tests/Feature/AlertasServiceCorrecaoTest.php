<?php

declare(strict_types=1);

use App\Constants\AlertType;
use App\Constants\UserRole;
use App\Models\AlertState;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\User;
use App\Services\AlertasService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (UserRole::all() as $cargo) {
        Role::findOrCreate($cargo);
    }

    AlertasService::resetMemo();
    cache()->flush();
});

it('mantem a mesma chave de alerta fora_limites depois de uma correcao append-only', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::ADMIN);

    $piscina = Pool::factory()->create();

    $original = DailyRecord::factory()->create([
        'pool_id' => $piscina->id,
        'user_id' => $admin->id,
        'registado_em' => now(),
        'ph' => 9.5,
        'e_correcao' => false,
    ]);

    $alertasAntes = app(AlertasService::class)->calcular($admin)['alertas'];
    $chaveAntiga = collect($alertasAntes)->keys()
        ->first(fn (string $k) => str_starts_with($k, AlertType::FORA_LIMITES.'|'));

    expect($chaveAntiga)->not->toBeNull();

    // O quadro operacional marca o alerta como tratado — o AlertState persiste
    // com a chave gerada a partir do registo original.
    AlertState::create([
        'alert_key' => $chaveAntiga,
        'status' => 'resolvido',
        'moved_by' => $admin->id,
        'moved_at' => now(),
    ]);

    // Correcao append-only: novo DailyRecord, pH mantem-se fora dos limites,
    // o original nunca e alterado (so ganha uma relacao "correcoes").
    $correcao = DailyRecord::create($original->toArray() + [
        'e_correcao' => true,
        'corrige_registo_id' => $original->id,
        'razao_correcao' => 'Erro de digitacao, valor real confirmado.',
    ]);

    AlertasService::resetMemo();
    cache()->flush();

    $alertasDepois = app(AlertasService::class)->calcular($admin)['alertas'];
    $chaveNova = collect($alertasDepois)->keys()
        ->first(fn (string $k) => str_starts_with($k, AlertType::FORA_LIMITES.'|'));

    expect($chaveNova)->not->toBeNull()
        ->and($chaveNova)->toBe($chaveAntiga);

    // A chave estavel significa que o AlertState "resolvido" criado antes da
    // correcao continua a aplicar-se — o quadro nao gera um cartao novo do
    // zero, mostra o mesmo alerta com "condicao persiste".
    expect(AlertState::where('alert_key', $chaveNova)->where('status', 'resolvido')->exists())->toBeTrue();
});
