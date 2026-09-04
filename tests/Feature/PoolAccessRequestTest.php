<?php

declare(strict_types=1);

use App\Constants\PoolAccessRequestStatus;
use App\Constants\UserRole;
use App\Filament\Resources\PoolAccessRequestResource;
use App\Models\Pool;
use App\Models\PoolAccessRequest;
use App\Models\PoolClosure;
use App\Models\User;
use App\Notifications\PedidoAcessoContaNotification;
use App\Notifications\PedidoAcessoRespondidoNotification;
use App\Services\PoolAccessRequestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (UserRole::all() as $cargo) {
        Role::findOrCreate($cargo);
    }
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function nadadorNaPiscina(Pool $piscina): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::NADADOR_SALVADOR);
    $user->piscinas()->attach($piscina);

    return $user;
}

it('nao bloqueia o nadador-salvador enquanto tiver pelo menos uma piscina aberta', function (): void {
    $aberta = Pool::factory()->create();
    $encerrada = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $encerrada->id]);

    $ns = nadadorNaPiscina($aberta);
    $ns->piscinas()->attach($encerrada);

    expect(app(PoolAccessRequestService::class)->estaBloqueado($ns))->toBeFalse();
});

it('bloqueia o nadador-salvador quando todas as piscinas atribuidas estao encerradas', function (): void {
    $piscina = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $piscina->id]);

    $ns = nadadorNaPiscina($piscina);

    expect(app(PoolAccessRequestService::class)->estaBloqueado($ns))->toBeTrue();
});

it('nao bloqueia outros cargos mesmo com a piscina encerrada', function (): void {
    $piscina = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $piscina->id]);

    $tecnico = User::factory()->create();
    $tecnico->assignRole(UserRole::TECNICO);

    expect(app(PoolAccessRequestService::class)->estaBloqueado($tecnico))->toBeFalse();
});

it('redireciona o nadador-salvador bloqueado para o ecra de encerramento e deixa os outros cargos passar', function (): void {
    $piscina = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $piscina->id]);
    $ns = nadadorNaPiscina($piscina);

    $this->actingAs($ns)->get('/admin')->assertRedirect('/piscinas-encerradas');

    $admin = User::factory()->create();
    $admin->assignRole(UserRole::ADMIN);
    $this->actingAs($admin)->get('/admin')->assertSuccessful();
});

it('permite ao nadador-salvador bloqueado pedir acesso e notifica o administrador', function (): void {
    Notification::fake();

    $piscina = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $piscina->id]);
    $ns = nadadorNaPiscina($piscina);

    $admin = User::factory()->create();
    $admin->assignRole(UserRole::ADMIN);

    $this->actingAs($ns)
        ->post('/piscinas-encerradas/pedir-acesso', ['motivo' => 'Preciso de confirmar um registo de ontem.'])
        ->assertRedirect('/piscinas-encerradas');

    $pedido = PoolAccessRequest::where('user_id', $ns->id)->firstOrFail();
    expect($pedido->status)->toBe(PoolAccessRequestStatus::PENDENTE);

    Notification::assertSentTo($admin, PedidoAcessoContaNotification::class);
});

it('nao deixa criar um segundo pedido enquanto houver um pendente', function (): void {
    $piscina = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $piscina->id]);
    $ns = nadadorNaPiscina($piscina);

    $this->actingAs($ns)
        ->post('/piscinas-encerradas/pedir-acesso', ['motivo' => 'Primeiro pedido, detalhado.']);

    $this->actingAs($ns)
        ->post('/piscinas-encerradas/pedir-acesso', ['motivo' => 'Segundo pedido, detalhado.'])
        ->assertSessionHasErrors('motivo');

    expect(PoolAccessRequest::where('user_id', $ns->id)->count())->toBe(1);
});

it('desbloqueia a conta quando o administrador aprova o pedido', function (): void {
    Notification::fake();

    $piscina = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $piscina->id]);
    $ns = nadadorNaPiscina($piscina);
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::ADMIN);

    $pedido = PoolAccessRequest::factory()->create(['user_id' => $ns->id]);

    app(PoolAccessRequestService::class)->aprovar($pedido, $admin, 'Pode confirmar.');

    expect(app(PoolAccessRequestService::class)->estaBloqueado($ns))->toBeFalse();
    Notification::assertSentTo($ns, PedidoAcessoRespondidoNotification::class);
});

it('mantem a conta bloqueada quando o administrador nega o pedido', function (): void {
    Notification::fake();

    $piscina = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $piscina->id]);
    $ns = nadadorNaPiscina($piscina);
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::ADMIN);

    $pedido = PoolAccessRequest::factory()->create(['user_id' => $ns->id]);

    app(PoolAccessRequestService::class)->negar($pedido, $admin);

    expect(app(PoolAccessRequestService::class)->estaBloqueado($ns))->toBeTrue();
    Notification::assertSentTo($ns, PedidoAcessoRespondidoNotification::class);
});

it('exige um novo pedido quando ha um encerramento mais recente do que a aprovacao anterior', function (): void {
    Carbon::setTestNow('2026-07-15 09:00:00');

    $piscina = Pool::factory()->create();
    PoolClosure::factory()->periodo(
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-07-31'),
    )->create(['pool_id' => $piscina->id]);

    $ns = nadadorNaPiscina($piscina);
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::ADMIN);

    $pedido = PoolAccessRequest::factory()->create(['user_id' => $ns->id]);
    app(PoolAccessRequestService::class)->aprovar($pedido, $admin);

    expect(app(PoolAccessRequestService::class)->estaBloqueado($ns))->toBeFalse();

    // Um novo encerramento comeca depois da aprovacao anterior: tem de pedir outra vez.
    Carbon::setTestNow('2026-09-01 09:00:00');
    PoolClosure::factory()->create(['pool_id' => $piscina->id, 'inicio' => Carbon::now()]);

    expect(app(PoolAccessRequestService::class)->estaBloqueado($ns))->toBeTrue();
});

it('so admin acede aos pedidos de acesso', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::ADMIN);
    $this->actingAs($admin);
    expect(PoolAccessRequestResource::canAccess())->toBeTrue();

    $gestor = User::factory()->create();
    $gestor->assignRole(UserRole::GESTOR);
    $this->actingAs($gestor);
    expect(PoolAccessRequestResource::canAccess())->toBeFalse();
});

// Regressão: o toggle "a água continua em tratamento" liga por defeito em
// qualquer motivo que não seja época balnear, e promete em três sítios da UI
// que "os registos diários continuam possíveis". O bloqueio usava
// estaEncerradaEm(), que ignora o regime, e punha o NS fora de todo o /admin
// durante uma paragem técnica — sem conseguir gravar a leitura que a lei exige.
it('nao bloqueia o nadador-salvador quando a piscina esta encerrada mas com agua em tratamento', function (): void {
    $piscina = Pool::factory()->create();
    PoolClosure::factory()->comTratamento()->create(['pool_id' => $piscina->id]);

    $ns = nadadorNaPiscina($piscina);

    expect(app(PoolAccessRequestService::class)->estaBloqueado($ns))->toBeFalse();
});

it('continua a bloquear quando a piscina esta parada, sem tratamento de agua', function (): void {
    $piscina = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $piscina->id, 'agua_em_tratamento' => false]);

    $ns = nadadorNaPiscina($piscina);

    expect(app(PoolAccessRequestService::class)->estaBloqueado($ns))->toBeTrue();
});

it('nao bloqueia se ao menos uma piscina estiver com agua em tratamento', function (): void {
    $parada = Pool::factory()->create();
    $emTratamento = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $parada->id, 'agua_em_tratamento' => false]);
    PoolClosure::factory()->comTratamento()->create(['pool_id' => $emTratamento->id]);

    $ns = nadadorNaPiscina($parada);
    $ns->piscinas()->attach($emTratamento);

    expect(app(PoolAccessRequestService::class)->estaBloqueado($ns))->toBeFalse();
});

it('distingue piscina encerrada de piscina parada', function (): void {
    $emTratamento = Pool::factory()->create();
    PoolClosure::factory()->comTratamento()->create(['pool_id' => $emTratamento->id]);

    $parada = Pool::factory()->create();
    PoolClosure::factory()->create(['pool_id' => $parada->id, 'agua_em_tratamento' => false]);

    $aberta = Pool::factory()->create();

    expect($emTratamento->estaEncerradaEm())->toBeTrue()
        ->and($emTratamento->estaParadaEm())->toBeFalse()
        ->and($parada->estaEncerradaEm())->toBeTrue()
        ->and($parada->estaParadaEm())->toBeTrue()
        ->and($aberta->estaEncerradaEm())->toBeFalse()
        ->and($aberta->estaParadaEm())->toBeFalse();
});

it('deixa o nadador-salvador chegar ao painel com a agua em tratamento', function (): void {
    $piscina = Pool::factory()->create();
    PoolClosure::factory()->comTratamento()->create(['pool_id' => $piscina->id]);

    $ns = nadadorNaPiscina($piscina);

    // `assertNoRedirect` nao existe no Laravel: dava BadMethodCallException e
    // o teste nunca chegou a verificar nada. O que se quer e que o NS NAO seja
    // desviado para /piscinas-encerradas com a agua em tratamento.
    $resposta = $this->actingAs($ns)->get('/admin');

    $this->assertNotSame(
        '/piscinas-encerradas',
        parse_url((string) $resposta->headers->get('Location'), PHP_URL_PATH),
        'Com a agua em tratamento o nadador-salvador entra no painel.'
    );
});
