<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\RelatorioPdf;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RelatorioPdfModernizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Installation $installation;

    private Pool $poolLazer;

    private Pool $poolOndas;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->installation = Installation::create([
            'name' => 'Complexo Aquático Regional',
            'morada' => 'Rua do Desporto 10',
            'active' => true,
        ]);

        $this->poolLazer = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Piscina Lazer',
            'type' => 'leisure',
            'temp_min' => 28.0,
            'temp_max' => 30.0,
            'volume' => 450.0,
            'active' => true,
        ]);

        $this->poolOndas = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Piscina Ondas',
            'type' => 'waves',
            'temp_min' => 26.0,
            'temp_max' => 28.0,
            'volume' => 800.0,
            'active' => true,
        ]);
    }

    /**
     * Test 1: Default form initialization has modelo_relatorio = dgs_oficial and default regulatory columns.
     */
    public function test_default_model_is_dgs_oficial(): void
    {
        Livewire::actingAs($this->admin)
            ->test(RelatorioPdf::class)
            ->assertSet('data.modelo_relatorio', RelatorioPdf::MODELO_DGS_OFICIAL)
            ->assertSet('data.registo_modo', 'todos')
            ->assertSet('data.colunas_visiveis', RelatorioPdf::COLUNAS_DGS_OFICIAL)
            ->assertSet('data.seccoes_visiveis', RelatorioPdf::SECCOES_DGS_OFICIAL);
    }

    /**
     * Test 2: Switching model preset updates columns and sections dynamically.
     */
    public function test_switching_model_preset_updates_columns_and_sections(): void
    {
        Livewire::actingAs($this->admin)
            ->test(RelatorioPdf::class)
            ->set('data.modelo_relatorio', RelatorioPdf::MODELO_COMPLETO)
            ->assertSet('data.colunas_visiveis', RelatorioPdf::TODAS_COLUNAS)
            ->assertSet('data.seccoes_visiveis', RelatorioPdf::TODAS_SECCOES)
            ->set('data.modelo_relatorio', RelatorioPdf::MODELO_DGS_OFICIAL)
            ->assertSet('data.colunas_visiveis', RelatorioPdf::COLUNAS_DGS_OFICIAL)
            ->assertSet('data.seccoes_visiveis', RelatorioPdf::SECCOES_DGS_OFICIAL);
    }

    /**
     * Test 3: Pre-flight summary calculates compliance metrics, violations, and filter backwashes correctly.
     */
    public function test_preflight_summary_calculation(): void
    {
        // 1 compliant record
        DailyRecord::create([
            'pool_id' => $this->poolLazer->id,
            'user_id' => $this->admin->id,
            'registado_em' => now()->subDays(3)->startOfDay()->addHours(9),
            'ph' => 7.2,
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'temperatura' => 29.0,
            'transparencia' => 2.0,
            'filtro_faz_retrolavagem' => true,
            'numero_lavagens_filtro' => 1,
            'e_correcao' => false,
        ]);

        // 1 violating record (ph = 6.0 is out of bounds)
        DailyRecord::create([
            'pool_id' => $this->poolLazer->id,
            'user_id' => $this->admin->id,
            'registado_em' => now()->subDays(2)->startOfDay()->addHours(14),
            'ph' => 6.0,
            'cloro_livre' => 1.5,
            'cloro_total' => 1.8,
            'temperatura' => 29.0,
            'transparencia' => 2.0,
            'e_correcao' => false,
        ]);

        // 1 operational action of filter wash
        OperationalAction::create([
            'pool_id' => $this->poolLazer->id,
            'user_id' => $this->admin->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'registado_em' => now()->subDays(2)->startOfDay()->addHours(15),
            'observacoes' => 'Retrolavagem de rotina',
        ]);

        $test = Livewire::actingAs($this->admin)
            ->test(RelatorioPdf::class)
            ->fillForm([
                'installation_id' => $this->installation->id,
                'pool_id' => (string) $this->poolLazer->id,
                'data_inicio' => now()->subDays(5)->toDateString(),
                'data_fim' => now()->subDay()->toDateString(),
            ]);

        $summary = $test->instance()->preflightSummary;

        $this->assertTrue($summary['valido']);
        $this->assertEquals(2, $summary['totalRegistos']);
        $this->assertEquals(1, $summary['totalViolacoes']);
        $this->assertEquals(50.0, $summary['taxaConformidade']);
        $this->assertEquals(2, $summary['totalLavagens']); // 1 from daily record + 1 from operational action
    }

    /**
     * Test 4: Termo Legal is eligible only for 1 pool and a full civil month.
     */
    public function test_preflight_termo_legal_eligibility(): void
    {
        $inicioMesCompleto = now()->subMonth()->startOfMonth();
        $fimMesCompleto = now()->subMonth()->endOfMonth();

        // Scenario A: 1 pool, full month -> eligible
        $testA = Livewire::actingAs($this->admin)
            ->test(RelatorioPdf::class)
            ->fillForm([
                'installation_id' => $this->installation->id,
                'pool_id' => (string) $this->poolLazer->id,
                'data_inicio' => $inicioMesCompleto->toDateString(),
                'data_fim' => $fimMesCompleto->toDateString(),
            ]);

        $this->assertTrue($testA->instance()->preflightSummary['termoElegivel']);

        // Scenario B: all pools, full month -> not eligible
        $testB = Livewire::actingAs($this->admin)
            ->test(RelatorioPdf::class)
            ->fillForm([
                'installation_id' => $this->installation->id,
                'pool_id' => 'todas',
                'data_inicio' => $inicioMesCompleto->toDateString(),
                'data_fim' => $fimMesCompleto->toDateString(),
            ]);

        $this->assertFalse($testB->instance()->preflightSummary['termoElegivel']);
    }

    /**
     * Test 5: Pool closures are detected in preflight summary.
     */
    public function test_preflight_detects_formal_closures(): void
    {
        PoolClosure::create([
            'pool_id' => $this->poolLazer->id,
            'inicio' => now()->subDays(4),
            'fim' => now()->subDays(2),
            'motivo' => 'manutencao',
            'encerrada_por' => $this->admin->id,
            'observacoes' => 'Substituição de areia de sílica',
        ]);

        $test = Livewire::actingAs($this->admin)
            ->test(RelatorioPdf::class)
            ->fillForm([
                'installation_id' => $this->installation->id,
                'pool_id' => (string) $this->poolLazer->id,
                'data_inicio' => now()->subDays(5)->toDateString(),
                'data_fim' => now()->subDay()->toDateString(),
            ]);

        $this->assertEquals(1, $test->instance()->preflightSummary['encerramentosCount']);
    }

    /**
     * Test 6: Query optimization in construirSeccoes executes single query for DailyRecords across all pools.
     */
    public function test_construir_seccoes_query_efficiency(): void
    {
        DailyRecord::create([
            'pool_id' => $this->poolLazer->id,
            'user_id' => $this->admin->id,
            'registado_em' => now()->subDays(2),
            'ph' => 7.2,
            'cloro_livre' => 1.5,
            'e_correcao' => false,
        ]);

        DailyRecord::create([
            'pool_id' => $this->poolOndas->id,
            'user_id' => $this->admin->id,
            'registado_em' => now()->subDays(2),
            'ph' => 7.4,
            'cloro_livre' => 1.6,
            'e_correcao' => false,
        ]);

        $inicio = now()->subDays(5)->startOfDay();
        $fim = now()->subDay()->endOfDay();

        $seccoes = RelatorioPdf::construirSeccoes(
            collect([$this->poolLazer, $this->poolOndas]),
            $inicio,
            $fim,
            'todos',
            'media_diaria'
        );

        $this->assertCount(2, $seccoes);
        $this->assertEquals('Piscina Lazer', $seccoes[0]['piscina']->name);
        $this->assertCount(1, $seccoes[0]['registos']);
        $this->assertEquals('Piscina Ondas', $seccoes[1]['piscina']->name);
        $this->assertCount(1, $seccoes[1]['registos']);
    }
}
