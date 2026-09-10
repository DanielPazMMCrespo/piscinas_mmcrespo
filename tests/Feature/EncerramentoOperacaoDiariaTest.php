<?php

declare(strict_types=1);

use App\Constants\AlertType;
use App\Constants\IncidentStatus;
use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\TapAlert;
use App\Models\User;
use App\Services\AlertasService;
use App\Services\DailyRecordService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
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

afterEach(function (): void {
    Carbon::setTestNow();
});

function admin(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::ADMIN);

    return $user;
}

function encerrar(Pool $piscina, bool $comTratamento = false): PoolClosure
{
    return PoolClosure::factory()
        ->periodo(Carbon::now()->subDay())
        ->create(['pool_id' => $piscina->id, 'agua_em_tratamento' => $comTratamento]);
}

it('nao gera alerta de falta de registo numa piscina encerrada', function (): void {
    Carbon::setTestNow(Carbon::now()->setTime(14, 0));

    $aberta = Pool::factory()->create();
    $encerrada = Pool::factory()->create();
    encerrar($encerrada);

    $alertas = app(AlertasService::class)->calcular(admin())['alertas'];

    expect($alertas)->toHaveKey(AlertType::SEM_REGISTO."|{$aberta->id}|".now()->toDateString())
        ->and($alertas)->not->toHaveKey(AlertType::SEM_REGISTO."|{$encerrada->id}|".now()->toDateString());
});

it('exclui piscinas encerradas dos denominadores de conformidade', function (): void {
    Pool::factory()->count(2)->create();
    $encerrada = Pool::factory()->create();
    encerrar($encerrada);

    $resultado = app(AlertasService::class)->calcular(admin());

    expect($resultado['totalPiscinas'])->toBe(2)
        ->and($resultado['encerradas'])->toBe(1);
});

it('mostra um cartao neutro agregado das piscinas encerradas', function (): void {
    $encerrada = Pool::factory()->create();
    encerrar($encerrada);

    $alertas = app(AlertasService::class)->calcular(admin())['alertas'];
    $chave = AlertType::ENCERRADA.'|'.now()->toDateString();

    expect($alertas)->toHaveKey($chave)
        ->and($alertas[$chave]['nivel'])->toBe('neutro')
        ->and($alertas[$chave]['detalhe'])->toContain($encerrada->nome_completo);
});

it('nao gera alerta de torneira aberta numa piscina encerrada', function (): void {
    $encerrada = Pool::factory()->create();
    encerrar($encerrada);

    TapAlert::create([
        'pool_id' => $encerrada->id,
        'opened_at' => Carbon::now()->subHours(6),
    ]);

    $alertas = app(AlertasService::class)->calcular(admin())['alertas'];

    expect(collect($alertas)->keys()->filter(fn (string $k) => str_starts_with($k, AlertType::TORNEIRA.'|')))
        ->toBeEmpty();
});

it('mantem o alerta de violacao legal quando a agua continua em tratamento', function (): void {
    $piscina = Pool::factory()->create();
    encerrar($piscina, comTratamento: true);

    DailyRecord::factory()->create([
        'pool_id' => $piscina->id,
        'registado_em' => Carbon::now(),
        'ph' => 9.5,
        'e_correcao' => false,
    ]);

    $alertas = app(AlertasService::class)->calcular(admin())['alertas'];

    $foraLimites = collect($alertas)->filter(fn (array $a, string $k) => str_starts_with($k, AlertType::FORA_LIMITES.'|'));

    expect($foraLimites)->not->toBeEmpty()
        ->and($foraLimites->first()['detalhe'])->toContain('água em tratamento');
});

it('recusa registo diario numa piscina encerrada e parada', function (): void {
    $utilizador = admin();
    $piscina = Pool::factory()->create();
    encerrar($piscina);

    expect(fn () => app(DailyRecordService::class)->createRecords($utilizador, [
        'registado_em' => Carbon::now()->toDateString(),
        'pools' => [(string) $piscina->id => ['ph' => 7.2]],
    ]))->toThrow(ValidationException::class);

    expect(DailyRecord::where('pool_id', $piscina->id)->count())->toBe(0);
});

it('aceita registo diario numa piscina encerrada com agua em tratamento', function (): void {
    $utilizador = admin();
    $piscina = Pool::factory()->create();
    encerrar($piscina, comTratamento: true);

    app(DailyRecordService::class)->createRecords($utilizador, [
        'registado_em' => Carbon::now()->toDateString(),
        'pools' => [(string) $piscina->id => ['ph' => 7.2]],
    ]);

    expect(DailyRecord::where('pool_id', $piscina->id)->count())->toBe(1);
});

it('valida o encerramento contra a data do registo e nao contra hoje', function (): void {
    Carbon::setTestNow('2026-10-15 10:00:00');

    $utilizador = admin();
    $piscina = Pool::factory()->create();

    // Encerrada em agosto, ja reaberta hoje.
    PoolClosure::factory()->periodo(
        Carbon::parse('2026-08-01'),
        Carbon::parse('2026-08-31'),
    )->create(['pool_id' => $piscina->id, 'agua_em_tratamento' => false]);

    // Registo de hoje passa.
    app(DailyRecordService::class)->createRecords($utilizador, [
        'registado_em' => '2026-10-15',
        'pools' => [(string) $piscina->id => ['ph' => 7.2]],
    ]);

    // Registo retroativo para dentro do periodo encerrado nao passa.
    expect(fn () => app(DailyRecordService::class)->createRecords($utilizador, [
        'registado_em' => '2026-08-10',
        'pools' => [(string) $piscina->id => ['ph' => 7.2]],
    ]))->toThrow(ValidationException::class);

    expect(DailyRecord::where('pool_id', $piscina->id)->count())->toBe(1);
});

it('nao abre incidente automatico numa piscina encerrada', function (): void {
    $piscina = Pool::factory()->create();
    encerrar($piscina, comTratamento: true);

    // Tres violacoes de pH no mesmo dia — o gatilho da regra 1.
    DailyRecord::factory()->count(3)->create([
        'pool_id' => $piscina->id,
        'registado_em' => Carbon::now(),
        'ph' => 9.5,
        'e_correcao' => false,
    ]);

    $this->artisan('regras:executar')->assertSuccessful();

    expect(Incident::where('pool_id', $piscina->id)->count())->toBe(0);
});

it('nao abre incidente automatico por oscilacoes de agua mesmo em piscina aberta (regra desativada)', function (): void {
    $piscina = Pool::factory()->create();

    DailyRecord::factory()->count(3)->create([
        'pool_id' => $piscina->id,
        'registado_em' => Carbon::now(),
        'ph' => 9.5,
        'e_correcao' => false,
    ]);

    $this->artisan('regras:executar')->assertSuccessful();

    // Incidentes por oscilações foram desativados no commit 305b97e para evitar sobrecarga/fadiga de alarmes
    expect(Incident::where('pool_id', $piscina->id)->where('status', IncidentStatus::ABERTO)->count())
        ->toBe(0);
});
