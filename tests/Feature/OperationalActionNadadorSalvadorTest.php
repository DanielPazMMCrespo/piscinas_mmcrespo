<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\OperationalActionResource;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OperationalActionNadadorSalvadorTest extends TestCase
{
    use RefreshDatabase;

    private User $nadadorSalvador;

    private User $nadadorSalvadorSemPermissao;

    private Pool $pool;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);

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

        // NS com permissão de análise de parâmetros
        $this->nadadorSalvador = User::factory()->create();
        $this->nadadorSalvador->assignRole(UserRole::NADADOR_SALVADOR);
        $this->nadadorSalvador->piscinas()->attach($this->pool->id);
        $this->nadadorSalvador->update([
            'ns_permissions' => [NSPermission::ANALISE_PARAMETROS],
        ]);

        // NS sem permissão de análise de parâmetros
        $this->nadadorSalvadorSemPermissao = User::factory()->create();
        $this->nadadorSalvadorSemPermissao->assignRole(UserRole::NADADOR_SALVADOR);
        $this->nadadorSalvadorSemPermissao->piscinas()->attach($this->pool->id);
        $this->nadadorSalvadorSemPermissao->update([
            'ns_permissions' => [NSPermission::REGISTO_DIARIO],
        ]);
    }

    public function test_ns_with_analise_parametros_can_access_operational_action_resource(): void
    {
        $this->actingAs($this->nadadorSalvador);

        $this->assertTrue(OperationalActionResource::canAccess());
    }

    public function test_ns_without_analise_parametros_cannot_access_operational_action_resource(): void
    {
        $this->actingAs($this->nadadorSalvadorSemPermissao);

        $this->assertFalse(OperationalActionResource::canAccess());
    }

    public function test_ns_can_render_list_page_with_analise_parametros(): void
    {
        $this->actingAs($this->nadadorSalvador);

        Livewire::test(OperationalActionResource\Pages\ListOperationalActions::class)
            ->assertSuccessful();
    }

    public function test_ns_without_analise_parametros_cannot_render_list_page(): void
    {
        $this->actingAs($this->nadadorSalvadorSemPermissao);

        // The middleware should block access before even rendering
        Livewire::test(OperationalActionResource\Pages\ListOperationalActions::class)
            ->assertForbidden();
    }

    public function test_ns_with_analise_parametros_can_create_analise_pontual(): void
    {
        $this->actingAs($this->nadadorSalvador);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_ANALISE_PONTUAL,
                'registado_em' => now()->toDateTimeString(),
                'dados.ph' => 7.2,
                'dados.cloro_livre' => 0.8,
                'observacoes' => 'Análise pontual do NS',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_ANALISE_PONTUAL,
            'user_id' => $this->nadadorSalvador->id,
            'observacoes' => 'Análise pontual do NS',
        ]);
    }

    public function test_ns_cannot_create_lavagem_filtro_action(): void
    {
        $this->actingAs($this->nadadorSalvador);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
                'registado_em' => now()->toDateTimeString(),
                'dados.filtro_nome' => 'Filtro 1',
                'dados.duracao_min' => 15,
                'dados.pressao_antes_bar' => 1.45,
                'dados.pressao_depois_bar' => 0.85,
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseMissing('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
        ]);
    }

    public function test_ns_cannot_create_torneira_action(): void
    {
        $this->actingAs($this->nadadorSalvador);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_TORNEIRA,
                'registado_em' => now()->toDateTimeString(),
                'dados.agua_modo' => 'on_com_agua',
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseMissing('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_TORNEIRA,
        ]);
    }

    public function test_ns_cannot_create_bomba_action(): void
    {
        $this->actingAs($this->nadadorSalvador);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_BOMBA,
                'registado_em' => now()->toDateTimeString(),
                'dados.bomba_nome' => 'Bomba Principal',
                'dados.bomba_acao' => 'paragem_arranque',
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseMissing('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_BOMBA,
        ]);
    }

    public function test_ns_cannot_create_reabastecimento_bidao_action(): void
    {
        $this->actingAs($this->nadadorSalvador);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'tipo' => OperationalAction::TIPO_REABASTECIMENTO_BIDAO,
                'registado_em' => now()->toDateTimeString(),
                'dados.bidao_tipo' => 'cloro',
                'dados.quantidade_l' => 20,
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseMissing('operational_actions', [
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_REABASTECIMENTO_BIDAO,
        ]);
    }

    public function test_ns_cannot_access_create_page_without_analise_parametros(): void
    {
        $this->actingAs($this->nadadorSalvadorSemPermissao);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->assertThrows(AuthorizationException::class);
    }

    public function test_ns_can_see_only_their_pools_operational_actions(): void
    {
        // Criar uma segunda piscina que o NS não tem acesso
        $pool2 = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Piscina Secundária',
            'type' => 'leisure',
            'temp_min' => 28.0,
            'temp_max' => 30.0,
            'volume' => 500,
            'active' => true,
        ]);

        // Criar ações em ambas as piscinas
        $acao1 = OperationalAction::create([
            'user_id' => $this->nadadorSalvador->id,
            'pool_id' => $this->pool->id,
            'tipo' => OperationalAction::TIPO_ANALISE_PONTUAL,
            'registado_em' => now(),
            'dados' => ['ph' => 7.2],
        ]);

        $acao2 = OperationalAction::create([
            'user_id' => $this->nadadorSalvador->id,
            'pool_id' => $pool2->id,
            'tipo' => OperationalAction::TIPO_ANALISE_PONTUAL,
            'registado_em' => now(),
            'dados' => ['ph' => 7.1],
        ]);

        $this->actingAs($this->nadadorSalvador);

        // NS deve ver apenas ações da sua piscina
        $query = OperationalActionResource::getEloquentQuery();
        $poolIds = $query->pluck('pool_id')->unique();

        $this->assertContains($this->pool->id, $poolIds->toArray());
        $this->assertNotContains($pool2->id, $poolIds->toArray());
    }

    public function test_ns_can_see_only_analise_pontual_in_tipo_options(): void
    {
        $this->actingAs($this->nadadorSalvador);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->assertFormSet([
                'tipo' => null,
            ])
            ->assertCanSeeFormComponent('tipo');

        // Verificar que apenas análise pontual está disponível
        $component = Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class);

        // Acessar o campo tipo do formulário
        $tiposDisponiveis = OperationalActionResource::class;

        // Usamos reflexão para chamar o método privado tiposDisponiveis()
        $reflection = new \ReflectionClass($tiposDisponiveis);
        $method = $reflection->getMethod('tiposDisponiveis');
        $method->setAccessible(true);

        $tipos = $method->invoke(null);

        // Deve haver apenas um tipo (análise pontual)
        $this->assertCount(1, $tipos);
        $this->assertArrayHasKey(OperationalAction::TIPO_ANALISE_PONTUAL, $tipos);
    }
}
