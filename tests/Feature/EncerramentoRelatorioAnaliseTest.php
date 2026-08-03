<?php

declare(strict_types=1);

use App\Constants\MotivoEncerramento;
use App\Constants\UserRole;
use App\Filament\Pages\RelatorioPdf;
use App\Filament\Widgets\HeatmapConformidadeWidget;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (UserRole::all() as $cargo) {
        Role::findOrCreate($cargo);
    }

    cache()->flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function autenticarAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::ADMIN);
    test()->actingAs($user);

    return $user;
}

it('inclui os encerramentos do periodo nas seccoes do livro sanitario', function (): void {
    $autor = autenticarAdmin();
    $instalacao = Installation::factory()->create();
    $piscina = Pool::factory()->create(['installation_id' => $instalacao->id]);

    PoolClosure::factory()->periodo(
        Carbon::parse('2026-08-01'),
        Carbon::parse('2026-08-20'),
    )->create([
        'pool_id' => $piscina->id,
        'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
        'encerrada_por' => $autor->id,
    ]);

    $seccoes = RelatorioPdf::construirSeccoes(
        collect([$piscina]),
        Carbon::parse('2026-08-01')->startOfDay(),
        Carbon::parse('2026-08-31')->endOfDay(),
        'todos',
        'media_diaria',
    );

    expect($seccoes[0])->toHaveKey('encerramentos')
        ->and($seccoes[0]['encerramentos'])->toHaveCount(1)
        ->and($seccoes[0]['encerramentos']->first()->motivo)->toBe(MotivoEncerramento::EPOCA_BALNEAR);
});

it('ignora encerramentos fora do periodo pedido', function (): void {
    autenticarAdmin();
    $piscina = Pool::factory()->create();

    PoolClosure::factory()->periodo(
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    )->create(['pool_id' => $piscina->id]);

    $seccoes = RelatorioPdf::construirSeccoes(
        collect([$piscina]),
        Carbon::parse('2026-08-01')->startOfDay(),
        Carbon::parse('2026-08-31')->endOfDay(),
        'todos',
        'media_diaria',
    );

    expect($seccoes[0]['encerramentos'])->toBeEmpty();
});

it('imprime a declaracao de encerramento no livro sanitario', function (): void {
    $autor = autenticarAdmin();
    $instalacao = Installation::factory()->create();
    $piscina = Pool::factory()->create(['installation_id' => $instalacao->id]);

    PoolClosure::factory()->periodo(
        Carbon::parse('2026-08-05'),
        Carbon::parse('2026-08-25'),
    )->create([
        'pool_id' => $piscina->id,
        'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
        'encerrada_por' => $autor->id,
        'observacoes' => 'Fecho anual programado.',
    ]);

    $inicio = Carbon::parse('2026-08-01')->startOfDay();
    $fim = Carbon::parse('2026-08-31')->endOfDay();

    $html = view('pdf.livro-sanitario', [
        'instalacao' => $instalacao,
        'seccoes' => RelatorioPdf::construirSeccoes(collect([$piscina]), $inicio, $fim, 'todos', 'media_diaria'),
        'inicio' => $inicio,
        'fim' => $fim,
        'emitidoEm' => Carbon::parse('2026-09-01'),
        'emitidoPor' => $autor->name,
        'colunasVisiveis' => ['ph', 'cloro_livre', 'conforme'],
        'seccoesVisiveis' => ['mostrar_resumo'],
        'modo' => 'todos',
        'controladorModo' => 'media_diaria',
    ])->render();

    // O Blade quebra linhas dentro das frases — comparar sobre texto normalizado.
    $texto = preg_replace('/\s+/', ' ', $html);

    expect($texto)
        ->toContain('Declaração de encerramento')
        ->toContain('Piscina encerrada de 05/08/2026 a 25/08/2026')
        ->toContain(MotivoEncerramento::labels()[MotivoEncerramento::EPOCA_BALNEAR])
        ->toContain('Fecho anual programado.')
        ->toContain('Não são devidos registos diários')
        // 05/08 a 25/08 inclusive.
        ->toContain('esteve encerrada 21 dias');
});

it('imprime a linha de encerramento dentro da tabela em ordem cronologica', function (): void {
    $autor = autenticarAdmin();
    $instalacao = Installation::factory()->create();
    $piscina = Pool::factory()->create(['installation_id' => $instalacao->id]);

    // Registo antes do fecho e outro depois da reabertura.
    DailyRecord::factory()->create([
        'pool_id' => $piscina->id,
        'user_id' => $autor->id,
        'registado_em' => Carbon::parse('2026-08-02 09:00'),
        'e_correcao' => false,
    ]);
    DailyRecord::factory()->create([
        'pool_id' => $piscina->id,
        'user_id' => $autor->id,
        'registado_em' => Carbon::parse('2026-08-28 09:00'),
        'e_correcao' => false,
    ]);

    PoolClosure::factory()->periodo(
        Carbon::parse('2026-08-05'),
        Carbon::parse('2026-08-25'),
    )->create(['pool_id' => $piscina->id, 'encerrada_por' => $autor->id]);

    $inicio = Carbon::parse('2026-08-01')->startOfDay();
    $fim = Carbon::parse('2026-08-31')->endOfDay();

    $html = view('pdf.livro-sanitario', [
        'instalacao' => $instalacao,
        'seccoes' => RelatorioPdf::construirSeccoes(collect([$piscina]), $inicio, $fim, 'todos', 'media_diaria'),
        'inicio' => $inicio,
        'fim' => $fim,
        'emitidoEm' => Carbon::parse('2026-09-01'),
        'emitidoPor' => $autor->name,
        'colunasVisiveis' => ['hora', 'ph', 'conforme'],
        'seccoesVisiveis' => ['mostrar_resumo'],
        'modo' => 'todos',
        'controladorModo' => 'media_diaria',
    ])->render();

    expect($html)->toContain('class="linha-encerramento"');

    // A linha do encerramento fica entre os dois registos. Procura-se pela
    // classe no atributo (a regra CSS com o mesmo nome aparece antes, no <style>).
    $posPrimeiro = strpos($html, '02/08/2026');
    $posEncerramento = strpos($html, 'class="linha-encerramento"');
    $posSegundo = strpos($html, '28/08/2026');

    expect($posPrimeiro)->toBeLessThan($posEncerramento)
        ->and($posEncerramento)->toBeLessThan($posSegundo);
});

it('conta os dias encerrados no resumo da piscina', function (): void {
    $autor = autenticarAdmin();
    $instalacao = Installation::factory()->create();
    $piscina = Pool::factory()->create(['installation_id' => $instalacao->id]);

    DailyRecord::factory()->create([
        'pool_id' => $piscina->id,
        'user_id' => $autor->id,
        'registado_em' => Carbon::parse('2026-08-02 09:00'),
        'e_correcao' => false,
    ]);

    // Encerramento a comecar antes da janela: so contam os dias dentro dela.
    PoolClosure::factory()->periodo(
        Carbon::parse('2026-07-20'),
        Carbon::parse('2026-08-10'),
    )->create(['pool_id' => $piscina->id, 'encerrada_por' => $autor->id]);

    $inicio = Carbon::parse('2026-08-01')->startOfDay();
    $fim = Carbon::parse('2026-08-31')->endOfDay();

    $html = view('pdf.livro-sanitario', [
        'instalacao' => $instalacao,
        'seccoes' => RelatorioPdf::construirSeccoes(collect([$piscina]), $inicio, $fim, 'todos', 'media_diaria'),
        'inicio' => $inicio,
        'fim' => $fim,
        'emitidoEm' => Carbon::parse('2026-09-01'),
        'emitidoPor' => $autor->name,
        'colunasVisiveis' => ['ph', 'conforme'],
        'seccoesVisiveis' => ['mostrar_resumo'],
        'modo' => 'todos',
        'controladorModo' => 'media_diaria',
    ])->render();

    // 01/08 a 10/08 = 10 dias dentro da janela pedida.
    expect($html)->toContain('Piscina encerrada:')
        ->toContain('<strong>10</strong>');
});

it('marca os dias encerrados com estado proprio no heatmap', function (): void {
    Carbon::setTestNow('2026-08-20 10:00:00');
    autenticarAdmin();

    $piscina = Pool::factory()->create();

    PoolClosure::factory()->periodo(
        Carbon::parse('2026-08-18'),
        Carbon::parse('2026-08-19'),
    )->create([
        'pool_id' => $piscina->id,
        'motivo' => MotivoEncerramento::MANUTENCAO,
    ]);

    $dados = (new HeatmapConformidadeWidget)->getDados();
    $estados = collect($dados['piscinas'][0]['celulas'])->pluck('estado');
    $encerradas = collect($dados['piscinas'][0]['celulas'])->where('estado', 'encerrada');

    expect($estados)->toContain('encerrada')
        ->and($encerradas)->toHaveCount(2)
        ->and($encerradas->first()['titulo'])->toContain('Encerrada')
        ->and($encerradas->first()['titulo'])->toContain(MotivoEncerramento::labels()[MotivoEncerramento::MANUTENCAO]);
});

it('mantem os dias sem registo distintos dos dias encerrados no heatmap', function (): void {
    Carbon::setTestNow('2026-08-20 10:00:00');
    autenticarAdmin();

    Pool::factory()->create();

    $dados = (new HeatmapConformidadeWidget)->getDados();
    $estados = collect($dados['piscinas'][0]['celulas'])->pluck('estado')->unique();

    expect($estados)->toContain('sem_dados')
        ->and($estados)->not->toContain('encerrada');
});

it('exclui piscinas encerradas das tendencias e da comparacao semanal', function (): void {
    Carbon::setTestNow('2026-08-20 10:00:00');

    Pool::factory()->create();
    $encerrada = Pool::factory()->create();
    PoolClosure::factory()->periodo(Carbon::parse('2026-08-01'))->create(['pool_id' => $encerrada->id]);

    expect(Pool::operacionais()->pluck('id')->all())->not->toContain($encerrada->id);

    $this->artisan('tendencias:verificar')->assertSuccessful();
    $this->artisan('notificacoes:comparacao-semanal')->assertSuccessful();
});
