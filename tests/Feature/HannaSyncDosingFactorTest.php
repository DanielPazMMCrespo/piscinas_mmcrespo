<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\StockHub;
use App\Models\DosingContainer;
use App\Models\HannaDevice;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Services\HannaCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HannaSyncDosingFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
    }

    private function createSetup(): array
    {
        $installation = Installation::create([
            'name' => 'Complexo Piscinas',
            'morada' => 'Rua do Desporto',
            'active' => true,
        ]);

        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Competição',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);

        $device = HannaDevice::create([
            'hanna_device_id' => 'DEV-BL132',
            'name' => 'Sonda Competição',
            'pool_id' => $pool->id,
            'active' => true,
            'dose_sincronizada_ate' => now()->subDay(),
        ]);

        // Cloro com Hanna BL10-2 @ 60% (fator 1.851)
        $containerCloro = DosingContainer::create([
            'pool_id' => $pool->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 25000,
            'restante_ml' => 25000,
            'alerta_percent' => 20,
            'bomba_modelo' => DosingContainer::BOMBA_HANNA_BL10_2,
            'bomba_capacidade_max_lh' => 10.80,
            'bomba_potenciometro_percent' => 60,
            'fator_correcao' => 1.851,
        ]);

        // pH com bomba standard (fator 1.000)
        $containerPh = DosingContainer::create([
            'pool_id' => $pool->id,
            'tipo' => DosingContainer::TIPO_PH_MENOS,
            'capacidade_ml' => 20000,
            'restante_ml' => 20000,
            'alerta_percent' => 20,
            'bomba_modelo' => DosingContainer::BOMBA_STANDARD,
            'fator_correcao' => 1.000,
        ]);

        return [$installation, $pool, $device, $containerCloro, $containerPh];
    }

    public function test_hanna_sync_multiplies_dosage_by_container_fator_correcao(): void
    {
        [$installation, $pool, $device, $containerCloro, $containerPh] = $this->createSetup();

        $readingTime = now()->subHours(2)->toDateTimeString();

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getLastReading')
            ->with('DEV-BL132')
            ->once()
            ->andReturn([
                'dt' => $readingTime,
                'ph' => 7.20,
                'orp' => 750.0,
                'temperatura_agua' => 26.5,
                'temperatura_ar' => 25.0,
                'caudal_ph' => 1.0,
                'caudal_cloro' => 1.0,
            ]);

        // Raw doses: Cloro 1000 mL, pH 500 mL
        $mock->shouldReceive('getHistoryReadings')
            ->with('DEV-BL132', \Mockery::any(), \Mockery::any())
            ->once()
            ->andReturn([
                [
                    'dt' => $readingTime,
                    'dose_cloro_ml' => 1000.0,
                    'dose_ph_ml' => 500.0,
                ],
            ]);

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync')->assertSuccessful();

        // Cloro: 1000 mL * 1.851 factor = 1851 mL
        // Restante: 25000 - 1851 = 23149 mL
        $this->assertEquals(23149.0, (float) $containerCloro->fresh()->restante_ml);

        // pH: 500 mL * 1.000 factor = 500 mL
        // Restante: 20000 - 500 = 19500 mL
        $this->assertEquals(19500.0, (float) $containerPh->fresh()->restante_ml);
    }

    public function test_stock_hub_calibrar_bomba_action_updates_container_and_can_mark_empty(): void
    {
        [$installation, $pool, $device, $containerCloro, $containerPh] = $this->createSetup();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(StockHub::class)
            ->callAction('calibrarBomba', data: [
                'container_id' => $containerCloro->id,
                'bomba_modelo' => DosingContainer::BOMBA_HANNA_BL10_2,
                'bomba_capacidade_max_lh' => 10.80,
                'bomba_potenciometro_percent' => 70,
                'zerar_nivel' => true,
            ], arguments: ['container' => $containerCloro->id])
            ->assertHasNoActionErrors();

        $fresh = $containerCloro->fresh();
        $this->assertSame(DosingContainer::BOMBA_HANNA_BL10_2, $fresh->bomba_modelo);
        $this->assertSame(70, $fresh->bomba_potenciometro_percent);
        // 10.8 * 0.70 = 7.56; 7.56 / 3.5 = 2.160
        $this->assertEquals(2.16, (float) $fresh->fator_correcao);
        $this->assertEquals(0.0, (float) $fresh->restante_ml);
    }

    public function test_stock_hub_renders_pump_calibration_badge(): void
    {
        [$installation, $pool, $device, $containerCloro, $containerPh] = $this->createSetup();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/stock')
            ->assertSuccessful()
            ->assertSee('Hanna BL10-2 @ 60% (6,5 L/h · 1,85×)')
            ->assertSee('Calibrar Bomba / Ajustar Potenciómetro');
    }
}
