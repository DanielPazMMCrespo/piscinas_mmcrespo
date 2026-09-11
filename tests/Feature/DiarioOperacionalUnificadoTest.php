<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\DailyRecordResource\Pages\ListDailyRecords;
use App\Filament\Resources\OperationalActionResource;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\User;
use App\Services\SourceSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DiarioOperacionalUnificadoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tecnico;

    private User $ns;

    private Pool $piscina1;

    private Pool $piscina2;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::TECNICO, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::NADADOR_SALVADOR, 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole(UserRole::TECNICO);

        $this->ns = User::factory()->create();
        $this->ns->assignRole(UserRole::NADADOR_SALVADOR);

        $instalacao = Installation::factory()->create(['name' => 'Complexo Olímpico']);
        $this->piscina1 = Pool::factory()->create([
            'installation_id' => $instalacao->id,
            'name' => 'Piscina Principal',
            'active' => true,
        ]);

        $this->piscina2 = Pool::factory()->create([
            'installation_id' => $instalacao->id,
            'name' => 'Piscina Aprendizagem',
            'active' => true,
        ]);

        // Atribuir apenas piscina1 ao nadador-salvador
        $this->ns->piscinas()->attach($this->piscina1->id);
    }

    public function test_operational_action_navigation_is_hidden_from_main_sidebar(): void
    {
        $this->assertFalse(OperationalActionResource::shouldRegisterNavigation());
        $this->assertSame('Registos', DailyRecordResource::getNavigationLabel());
    }

    public function test_top_kpis_reflect_measurements_and_technical_actions(): void
    {
        $this->actingAs($this->tecnico);

        // Criar uma medição hoje
        DailyRecord::factory()->create([
            'pool_id' => $this->piscina1->id,
            'user_id' => $this->tecnico->id,
            'registado_em' => now(),
            'ph' => 7.25,
            'cloro_livre' => 1.50,
        ]);

        // Criar uma ação de lavagem de filtro
        OperationalAction::create([
            'pool_id' => $this->piscina1->id,
            'user_id' => $this->tecnico->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'registado_em' => now()->subHour(),
            'dados' => ['duracao_min' => 4, 'filtro_nome' => 'Filtro 1'],
        ]);

        $component = Livewire::test(ListDailyRecords::class);
        $kpis = $component->instance()->getKpis();

        $this->assertSame(1, $kpis['medicoes_hoje']);
        $this->assertSame(1, $kpis['piscinas_medidas']);
        $this->assertNotNull($kpis['ultima_lavagem']);
        $this->assertSame($this->piscina1->id, $kpis['ultima_lavagem']->pool_id);
    }

    public function test_consolidated_timeline_interleaves_water_readings_and_technical_interventions(): void
    {
        $this->actingAs($this->tecnico);

        // 10:00 - Lavagem de filtro
        $acao = OperationalAction::create([
            'pool_id' => $this->piscina1->id,
            'user_id' => $this->tecnico->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'registado_em' => now()->subMinutes(60),
            'dados' => ['duracao_min' => 3, 'pressao_antes_bar' => 1.4, 'pressao_depois_bar' => 0.9],
        ]);

        // 10:30 - Medição pós-lavagem
        $registo = DailyRecord::factory()->create([
            'pool_id' => $this->piscina1->id,
            'user_id' => $this->tecnico->id,
            'registado_em' => now()->subMinutes(30),
            'ph' => 7.30,
            'cloro_livre' => 1.40,
            'orp' => 725,
        ]);

        $component = Livewire::test(ListDailyRecords::class);
        $timeline = $component->instance()->getTimelineEvents();

        $this->assertCount(2, $timeline);
        // O mais recente (10:30) deve vir em primeiro lugar
        $this->assertSame('medicao', $timeline->first()['tipo_evento']);
        $this->assertSame(725, $timeline->first()['orp']);

        // O anterior (10:00) deve vir em segundo lugar
        $this->assertSame('acao_tecnica', $timeline->last()['tipo_evento']);
        $this->assertSame(OperationalAction::TIPO_LAVAGEM_FILTRO, $timeline->last()['sub_tipo']);
    }

    public function test_timeline_filters_by_pool_and_event_type(): void
    {
        $this->actingAs($this->tecnico);

        DailyRecord::factory()->create([
            'pool_id' => $this->piscina1->id,
            'registado_em' => now()->subMinutes(20),
            'ph' => 7.20,
        ]);

        DailyRecord::factory()->create([
            'pool_id' => $this->piscina2->id,
            'registado_em' => now()->subMinutes(10),
            'ph' => 7.40,
        ]);

        OperationalAction::create([
            'pool_id' => $this->piscina1->id,
            'user_id' => $this->tecnico->id,
            'tipo' => OperationalAction::TIPO_CONTADOR,
            'registado_em' => now()->subMinutes(5),
            'dados' => ['contador_valor' => 1250.5],
        ]);

        $component = Livewire::test(ListDailyRecords::class);

        // Filtrar apenas piscina 1
        $component->call('filterByPool', $this->piscina1->id);
        $timelinePiscina1 = $component->instance()->getTimelineEvents();
        $this->assertCount(2, $timelinePiscina1);
        $this->assertTrue($timelinePiscina1->every(fn ($e) => $e['pool_id'] === $this->piscina1->id));

        // Filtrar apenas medições
        $component->call('filterByType', 'medicoes');
        $timelineMedicoes = $component->instance()->getTimelineEvents();
        $this->assertCount(1, $timelineMedicoes);
        $this->assertSame('medicao', $timelineMedicoes->first()['tipo_evento']);
    }

    public function test_nadador_salvador_only_sees_assigned_pools_and_has_no_technical_action(): void
    {
        $this->actingAs($this->ns);

        DailyRecord::factory()->create([
            'pool_id' => $this->piscina1->id,
            'registado_em' => now(),
            'ns_ph' => 7.20,
        ]);

        DailyRecord::factory()->create([
            'pool_id' => $this->piscina2->id, // piscina não atribuída
            'registado_em' => now(),
            'ph' => 7.50,
        ]);

        $component = Livewire::test(ListDailyRecords::class);

        // Não deve ter a ação de cabeçalho 'novaAcaoTecnica'
        $component->assertActionHidden('novaAcaoTecnica');

        // Vê apenas eventos da piscina atribuída
        $timeline = $component->instance()->getTimelineEvents();
        $this->assertCount(1, $timeline);
        $this->assertSame($this->piscina1->id, $timeline->first()['pool_id']);
    }

    public function test_tecnico_can_create_technical_action_via_header_action(): void
    {
        $this->actingAs($this->tecnico);

        $component = Livewire::test(ListDailyRecords::class);
        $component->assertActionVisible('novaAcaoTecnica');

        $component->callAction('novaAcaoTecnica', [
            'pool_id' => $this->piscina1->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'registado_em' => now(),
            'filtro_nome' => 'Filtro Principal',
            'duracao_min' => 5,
            'pressao_antes_bar' => 1.5,
            'pressao_depois_bar' => 0.8,
            'observacoes' => 'Lavagem completa preventiva',
        ]);

        $this->assertDatabaseHas('operational_actions', [
            'pool_id' => $this->piscina1->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'user_id' => $this->tecnico->id,
        ]);

        $acao = OperationalAction::where('tipo', OperationalAction::TIPO_LAVAGEM_FILTRO)->first();
        $this->assertSame(5, $acao->dados['duracao_min']);
        $this->assertSame('Filtro Principal', $acao->dados['filtro_nome']);
    }

    public function test_modal_da_acao_tecnica_e_impresso_fora_do_separador_escondido(): void
    {
        $this->actingAs($this->admin);

        $html = Livewire::test(ListDailyRecords::class)
            ->mountAction('novaAcaoTecnica')
            ->html();

        $separadorTabela = strpos($html, 'x-show="activeTab === \'leituras\'"');
        $modal = strpos($html, 'Registar Ação Técnica na Piscina');

        $this->assertNotFalse($separadorTabela, 'O separador das medições deixou de existir na vista.');
        $this->assertNotFalse($modal, 'O modal da ação técnica não foi impresso.');

        // O separador está com display:none por omissão (a vista abre na linha
        // temporal). Se o modal sair depois dele, sai lá dentro e não abre.
        $this->assertLessThan(
            $separadorTabela,
            $modal,
            'O modal da ação técnica está dentro do separador escondido: o botão não abre nada.'
        );
    }

    public function test_source_selection_service_picks_latest_record_with_water_chemistry(): void
    {
        // Registo mais antigo com medição de água
        $registoComAgua = DailyRecord::factory()->create([
            'pool_id' => $this->piscina1->id,
            'registado_em' => now()->subHours(2),
            'ph' => 7.22,
            'cloro_livre' => 1.35,
        ]);

        // Registo mais recente SEM medição de água (apenas notas/contadores)
        DailyRecord::factory()->create([
            'pool_id' => $this->piscina1->id,
            'registado_em' => now()->subHour(),
            'ph' => null,
            'ns_ph' => null,
            'cloro_livre' => null,
            'ns_cloro_livre' => null,
            'observacoes' => 'Verificação visual do tanque',
        ]);

        $service = app(SourceSelectionService::class);
        $resultado = $service->selectSource($this->piscina1);

        $this->assertSame('manual', $resultado['source']);
        $this->assertNotNull($resultado['record']);
        $this->assertSame($registoComAgua->id, $resultado['record']->id);
        $this->assertEquals(7.22, $resultado['record']->ph);
    }
}
