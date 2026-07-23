<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OperationalActionResource;
use App\Models\DosingContainer;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OperationalActionResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tecnico;

    private Pool $pool;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->installation = Installation::create([
            'name' => 'Complexo Aquático',
            'morada' => 'Av. Principal',
            'active' => true,
        ]);

        $this->pool = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Piscina Principal',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 28.0,
            'volume' => 1000,
            'active' => true,
        ]);
    }

    public function test_admin_can_render_operational_actions_page(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(OperationalActionResource\Pages\ListOperationalActions::class)
            ->assertSuccessful();
    }

    public function test_user_can_create_lavagem_filtro_action_with_details(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
                'registado_em' => now()->toDateTimeString(),
                'dados.filtro_nome' => 'Filtro 1',
                'dados.duracao_min' => 15,
                'dados.pressao_antes_bar' => 1.45,
                'dados.pressao_depois_bar' => 0.85,
                'observacoes' => 'Lavagem quinzenal do filtro principal',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
        ]);
    }

    public function test_torneira_action_creates_or_resolves_tap_alert(): void
    {
        $this->actingAs($this->admin);

        // Turn on tap with water -> opens TapAlert
        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_TORNEIRA,
                'registado_em' => now()->toDateTimeString(),
                'dados.agua_modo' => 'on_com_agua',
                'observacoes' => 'Enchimento iniciado',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tap_alerts', [
            'pool_id' => $this->pool->id,
            'resolved_at' => null,
        ]);

        // Turn tap off -> resolves TapAlert
        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_TORNEIRA,
                'registado_em' => now()->addMinutes(30)->toDateTimeString(),
                'dados.agua_modo' => 'off',
                'observacoes' => 'Enchimento terminado',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseMissing('tap_alerts', [
            'pool_id' => $this->pool->id,
            'resolved_at' => null,
        ]);
    }

    public function test_reabastecimento_bidao_updates_dosing_container(): void
    {
        $this->actingAs($this->admin);

        $container = DosingContainer::create([
            'pool_id' => $this->pool->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 25000,
            'restante_ml' => 5000,
        ]);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_REABASTECIMENTO_BIDAO,
                'registado_em' => now()->toDateTimeString(),
                'dados.bidao_tipo' => DosingContainer::TIPO_CLORO,
                'dados.quantidade_l' => 20,
                'observacoes' => 'Reabastecido bidão de cloro',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEquals(20000, $container->fresh()->restante_ml);
    }

    public function test_editing_reabastecimento_bidao_resyncs_dosing_container(): void
    {
        $this->actingAs($this->admin);

        $container = DosingContainer::create([
            'pool_id' => $this->pool->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 25000,
            'restante_ml' => 0,
        ]);

        $acao = OperationalAction::create([
            'user_id' => $this->admin->id,
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_REABASTECIMENTO_BIDAO,
            'registado_em' => now(),
            'dados' => ['bidao_tipo' => DosingContainer::TIPO_CLORO, 'quantidade_l' => 20],
        ]);

        $this->assertEquals(20000, $container->fresh()->restante_ml);

        Livewire::test(OperationalActionResource\Pages\EditOperationalAction::class, ['record' => $acao->getKey()])
            ->fillForm(['dados.quantidade_l' => 10])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(10000, $container->fresh()->restante_ml);
    }

    public function test_editing_torneira_action_resyncs_tap_alert(): void
    {
        $this->actingAs($this->admin);

        $acao = OperationalAction::create([
            'user_id' => $this->admin->id,
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_TORNEIRA,
            'registado_em' => now(),
            'dados' => ['agua_modo' => 'on_com_agua'],
        ]);

        $this->assertDatabaseHas('tap_alerts', [
            'pool_id' => $this->pool->id,
            'resolved_at' => null,
        ]);

        Livewire::test(OperationalActionResource\Pages\EditOperationalAction::class, ['record' => $acao->getKey()])
            ->fillForm(['dados.agua_modo' => 'off'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseMissing('tap_alerts', [
            'pool_id' => $this->pool->id,
            'resolved_at' => null,
        ]);
    }

    public function test_analise_pontual_requires_at_least_one_measured_value(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_ANALISE_PONTUAL,
                'registado_em' => now()->toDateTimeString(),
                'dados.ph' => null,
                'dados.cloro_livre' => null,
                'dados.cloro_total' => null,
                'dados.orp' => null,
                'dados.temperatura' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['dados.ph']);
    }

    public function test_outro_action_requires_observations(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_OUTRO,
                'registado_em' => now()->toDateTimeString(),
                'observacoes' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['observacoes']);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_OUTRO,
                'registado_em' => now()->toDateTimeString(),
                'observacoes' => 'Ajuste de válvulas manuais',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_OUTRO,
            'observacoes' => 'Ajuste de válvulas manuais',
        ]);
    }

    public function test_user_can_create_tratamento_choque_action(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_TRATAMENTO_CHOQUE,
                'registado_em' => now()->toDateTimeString(),
                'dados.produto' => 'Hipoclorito de Cálcio',
                'dados.quantidade' => '5 kg',
                'observacoes' => 'Tratamento preventivo quinzenal',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_TRATAMENTO_CHOQUE,
        ]);
    }

    public function test_user_can_create_limpeza_praias_action(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_LIMPEZA_PRAIAS,
                'registado_em' => now()->toDateTimeString(),
                'observacoes' => 'Desinfeção de grelhas e praias circundantes',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_LIMPEZA_PRAIAS,
        ]);
    }
}
