<?php

declare(strict_types=1);

use App\Constants\MotivoEncerramento;
use App\Constants\UserRole;
use App\Filament\Pages\EncerramentoPiscinas;
use App\Filament\Resources\PoolClosureResource;
use App\Filament\Widgets\HistoricoEncerramentosWidget;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\User;
use App\Notifications\PiscinaEncerradaNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
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

function utilizadorCom(string $cargo): User
{
    $user = User::factory()->create();
    $user->assignRole($cargo);

    return $user;
}

it('permite acesso a admin, gestor e tecnico e nega a nadador-salvador', function (string $cargo, bool $esperado): void {
    $this->actingAs(utilizadorCom($cargo));

    expect(EncerramentoPiscinas::canAccess())->toBe($esperado);
})->with([
    [UserRole::ADMIN, true],
    [UserRole::GESTOR, true],
    [UserRole::TECNICO, true],
    [UserRole::NADADOR_SALVADOR, false],
]);

it('encerra uma piscina pela pagina e notifica a equipa', function (): void {
    Notification::fake();

    $admin = utilizadorCom(UserRole::ADMIN);
    $piscina = Pool::factory()->create();

    $this->actingAs($admin);

    Livewire::test(EncerramentoPiscinas::class)
        ->callTableAction('encerrar', $piscina, data: [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => Carbon::now()->toDateString(),
            'fim' => null,
            'agua_em_tratamento' => false,
            'observacoes' => 'Fim de época.',
        ])
        ->assertHasNoTableActionErrors();

    $encerramento = PoolClosure::where('pool_id', $piscina->id)->firstOrFail();

    expect($encerramento->motivo)->toBe(MotivoEncerramento::EPOCA_BALNEAR)
        ->and($encerramento->encerrada_por)->toBe($admin->id)
        ->and($piscina->fresh()->estaEncerradaEm())->toBeTrue();

    Notification::assertSentTo($admin, PiscinaEncerradaNotification::class);
});

it('encerra varias piscinas em lote', function (): void {
    Notification::fake();

    $this->actingAs(utilizadorCom(UserRole::GESTOR));

    $piscinas = Pool::factory()->count(3)->create();

    Livewire::test(EncerramentoPiscinas::class)
        ->callTableBulkAction('encerrar_lote', $piscinas, data: [
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'inicio' => Carbon::now()->toDateString(),
            'fim' => null,
            'agua_em_tratamento' => false,
            'observacoes' => null,
        ]);

    expect(PoolClosure::count())->toBe(3)
        ->and(Pool::operacionais()->count())->toBe(0);
});

it('reabre uma piscina pela pagina', function (): void {
    Notification::fake();
    Carbon::setTestNow('2026-09-15 09:00:00');

    $this->actingAs(utilizadorCom(UserRole::TECNICO));

    $piscina = Pool::factory()->create();
    PoolClosure::factory()->periodo(Carbon::parse('2026-08-01'))->create(['pool_id' => $piscina->id]);

    Livewire::test(EncerramentoPiscinas::class)
        ->callTableAction('reabrir', $piscina, data: [
            'data_reabertura' => '2026-09-15',
        ])
        ->assertHasNoTableActionErrors();

    $encerramento = PoolClosure::where('pool_id', $piscina->id)->firstOrFail();

    expect($encerramento->fim->toDateString())->toBe('2026-09-14')
        ->and($piscina->fresh()->estaEncerradaEm())->toBeFalse();
});

it('avisa sem gravar quando o periodo se sobrepoe a um encerramento existente', function (): void {
    Notification::fake();

    $this->actingAs(utilizadorCom(UserRole::ADMIN));

    $piscina = Pool::factory()->create();
    PoolClosure::factory()->periodo(
        Carbon::parse('2026-08-01'),
        Carbon::parse('2026-09-30'),
    )->create(['pool_id' => $piscina->id]);

    // A acao de encerrar esta escondida numa piscina ja encerrada, logo o
    // caminho de sobreposicao chega-se pelo lote (varias piscinas de uma vez).
    Livewire::test(EncerramentoPiscinas::class)
        ->callTableBulkAction('encerrar_lote', [$piscina], data: [
            'motivo' => MotivoEncerramento::OBRA,
            'inicio' => '2026-09-15',
            'fim' => '2026-10-15',
            'agua_em_tratamento' => true,
            'observacoes' => null,
        ]);

    expect(PoolClosure::where('pool_id', $piscina->id)->count())->toBe(1);
});

it('nao permite criar encerramentos diretamente no resource de historico', function (): void {
    $this->actingAs(utilizadorCom(UserRole::ADMIN));

    expect(PoolClosureResource::canCreate())->toBeFalse();
});

it('lista o historico de encerramentos', function (): void {
    $this->actingAs(utilizadorCom(UserRole::GESTOR));

    $piscina = Pool::factory()->create();
    PoolClosure::factory()->periodo(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
    )->create(['pool_id' => $piscina->id]);

    $this->get(PoolClosureResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($piscina->name);
});

it('so admin pode apagar um encerramento', function (): void {
    $encerramento = PoolClosure::factory()->create();

    expect(utilizadorCom(UserRole::ADMIN)->can('delete', $encerramento))->toBeTrue()
        ->and(utilizadorCom(UserRole::GESTOR)->can('delete', $encerramento))->toBeFalse()
        ->and(utilizadorCom(UserRole::TECNICO)->can('delete', $encerramento))->toBeFalse();
});

it('mostra o historico de encerramentos no rodape da pagina', function (): void {
    $this->actingAs(utilizadorCom(UserRole::GESTOR));

    $piscina = Pool::factory()->create();
    $encerramento = PoolClosure::factory()->periodo(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
    )->create(['pool_id' => $piscina->id]);

    Livewire::test(HistoricoEncerramentosWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$encerramento])
        ->assertSee($piscina->name);

    expect(Livewire::test(EncerramentoPiscinas::class)->instance()->getVisibleFooterWidgets())
        ->not->toBeEmpty();
});

it('o historico no rodape nao deixa o tecnico editar', function (): void {
    $encerramento = PoolClosure::factory()->create();

    $this->actingAs(utilizadorCom(UserRole::TECNICO));
    Livewire::test(HistoricoEncerramentosWidget::class)
        ->assertTableActionHidden('editar', $encerramento);

    $this->actingAs(utilizadorCom(UserRole::GESTOR));
    Livewire::test(HistoricoEncerramentosWidget::class)
        ->assertTableActionVisible('editar', $encerramento);
});
