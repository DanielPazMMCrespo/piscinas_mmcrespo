<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\AlertLevel;
use App\Constants\AlertType;
use App\Constants\UserRole;
use App\Filament\Pages\InspecaoDgs;
use App\Models\DailyRecord;
use App\Models\DosingContainer;
use App\Models\DosingContainerLog;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\User;
use App\Services\AlertasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InteligenciaOperacionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (UserRole::all() as $cargo) {
            Role::findOrCreate($cargo);
        }

        AlertasService::resetMemo();
        cache()->flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dosing_container_calculates_daily_average_and_autonomy_hours(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        $piscina = Pool::factory()->create(['active' => true]);
        $bidao = DosingContainer::create([
            'pool_id' => $piscina->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 25000,
            'restante_ml' => 5000, // 5 Litros restantes
            'alerta_percent' => 20,
        ]);

        // Cria consumos nos últimos 2 dias: total 2000 ml em 2 dias = 1000 ml/dia
        DosingContainerLog::create([
            'dosing_container_id' => $bidao->id,
            'tipo_movimento' => 'consumo',
            'quantidade_ml' => -1000,
            'restante_apos_ml' => 6000,
            'origem' => 'controlador',
            'registado_em' => Carbon::now()->subDays(2),
        ]);

        DosingContainerLog::create([
            'dosing_container_id' => $bidao->id,
            'tipo_movimento' => 'consumo',
            'quantidade_ml' => -1000,
            'restante_apos_ml' => 5000,
            'origem' => 'controlador',
            'registado_em' => Carbon::now()->subDay(),
        ]);

        // Consumo diário: 2000 ml / 2 dias = 1000 ml/dia
        $consumoDiario = $bidao->consumoMedioDiarioMl(3);
        $this->assertNotNull($consumoDiario);
        $this->assertEqualsWithDelta(1000.0, $consumoDiario, 100.0);

        // Autonomia: 5000 ml restantes / 1000 ml/dia = 5 dias = 120 horas
        $horas = $bidao->horasAutonomia(3);
        $this->assertNotNull($horas);
        $this->assertEqualsWithDelta(120.0, $horas, 15.0);

        // Status deve ser 'ok'
        $this->assertEquals('ok', $bidao->statusAutonomia(3));
    }

    public function test_dosing_container_flags_weekend_exhaustion_warning(): void
    {
        // Quinta-feira às 14:00
        Carbon::setTestNow('2026-09-10 14:00:00'); // 10 Set 2026 é Quinta-feira

        $piscina = Pool::factory()->create(['active' => true]);
        $bidao = DosingContainer::create([
            'pool_id' => $piscina->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 25000,
            'restante_ml' => 1500, // Restam 1.5L
            'alerta_percent' => 20,
        ]);

        // Consumo de 1000ml por dia
        DosingContainerLog::create([
            'dosing_container_id' => $bidao->id,
            'tipo_movimento' => 'consumo',
            'quantidade_ml' => -2000,
            'restante_apos_ml' => 1500,
            'origem' => 'controlador',
            'registado_em' => Carbon::now()->subDays(2),
        ]);

        // Autonomia: 1500 ml / 1000 ml/dia = 1.5 dias = 36 horas (< 48h!)
        $horas = $bidao->horasAutonomia(3);
        $this->assertNotNull($horas);
        $this->assertLessThan(48.0, $horas);

        // Status deve ser 'aviso' ou 'critico'
        $status = $bidao->statusAutonomia(3);
        $this->assertContains($status, ['aviso', 'critico']);

        // Esgota nas próximas 48h partindo de quinta-feira = sexta/sábado
        $this->assertTrue($bidao->esgotaNoFimDeSemana(3));
    }

    public function test_alertas_service_triggers_autonomia_quimica_alert(): void
    {
        Carbon::setTestNow('2026-09-10 14:00:00');

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $piscina = Pool::factory()->create(['active' => true]);
        $bidao = DosingContainer::create([
            'pool_id' => $piscina->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 25000,
            'restante_ml' => 800, // Muito baixo (< 1L)
            'alerta_percent' => 20,
        ]);

        DosingContainerLog::create([
            'dosing_container_id' => $bidao->id,
            'tipo_movimento' => 'consumo',
            'quantidade_ml' => -1000,
            'restante_apos_ml' => 800,
            'origem' => 'controlador',
            'registado_em' => Carbon::now()->subDay(),
        ]);

        $alertas = app(AlertasService::class)->calcular($admin)['alertas'];

        $alertasQuimicos = collect($alertas)->filter(fn ($a, $k) => str_starts_with($k, AlertType::AUTONOMIA_QUIMICA.'|'));
        $this->assertNotEmpty($alertasQuimicos);

        $alerta = $alertasQuimicos->first();
        $this->assertContains($alerta['nivel'], [AlertLevel::VERMELHO, AlertLevel::AMARELO]);
        $this->assertStringContainsString('Cloro', $alerta['titulo']);
    }

    public function test_alertas_service_detects_unexplained_water_meter_jump(): void
    {
        Carbon::setTestNow('2026-09-10 14:00:00');

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $piscina = Pool::factory()->create(['active' => true, 'volume' => 100]);

        // Leitura anterior do contador há 24h: 1000 m³
        OperationalAction::create([
            'pool_id' => $piscina->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_CONTADOR,
            'dados' => ['contador_valor' => 1000.0],
            'ocorreu_em' => Carbon::now()->subHours(24),
            'registado_em' => Carbon::now()->subHours(24),
        ]);

        // Leitura atual do contador: 1030 m³ (+30 m³ de entrada de água sem lavagens de filtros!)
        OperationalAction::create([
            'pool_id' => $piscina->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_CONTADOR,
            'dados' => ['contador_valor' => 1030.0],
            'ocorreu_em' => Carbon::now()->subHours(2),
            'registado_em' => Carbon::now()->subHours(2),
        ]);

        $alertas = app(AlertasService::class)->calcular($admin)['alertas'];

        $alertasAgua = collect($alertas)->filter(fn ($a, $k) => str_starts_with($k, AlertType::ANOMALIA_AGUA.'|'));
        $this->assertNotEmpty($alertasAgua);

        $alerta = $alertasAgua->first();
        $this->assertEquals(AlertLevel::AMARELO, $alerta['nivel']);
        $this->assertStringContainsString('consumo de água anómalo', $alerta['titulo']);
        $this->assertStringContainsString('30', $alerta['detalhe']);
    }

    public function test_inspecao_dgs_page_authorization_and_rendering(): void
    {
        Carbon::setTestNow('2026-09-10 14:00:00');

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $gestor = User::factory()->create();
        $gestor->assignRole(UserRole::GESTOR);

        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);

        $this->actingAs($admin);
        $this->assertTrue(InspecaoDgs::canAccess());

        $this->actingAs($gestor);
        $this->assertTrue(InspecaoDgs::canAccess());

        $this->actingAs($ns);
        $this->assertFalse(InspecaoDgs::canAccess());

        // Test rendering with admin
        $this->actingAs($admin);
        $instalacao = Installation::factory()->create(['active' => true, 'name' => 'Complexo Desportivo']);
        $piscina = Pool::factory()->create(['installation_id' => $instalacao->id, 'active' => true, 'name' => 'Piscina Principal']);

        // Registo diário conforme
        DailyRecord::factory()->create([
            'pool_id' => $piscina->id,
            'user_id' => $admin->id,
            'registado_em' => Carbon::now()->subDays(2),
            'ph' => 7.3,
            'cloro_livre' => 1.5,
            'cloro_total' => 1.7,
            'transparencia' => 0.5,
            'e_correcao' => false,
        ]);

        Livewire::test(InspecaoDgs::class)
            ->set('installation_id', $instalacao->id)
            ->assertSee('Inspeção Sanitária Oficial')
            ->assertSee('Complexo Desportivo')
            ->assertSee('Piscina Principal')
            ->assertSee('Conformidade CN 14/DA');
    }
}
