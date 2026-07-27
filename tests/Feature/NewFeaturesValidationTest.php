<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\EsquemaPiscina;
use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Filament\Resources\OperationalActionResource;
use App\Filament\Resources\OperationalActionResource\Pages\CreateOperationalAction;
use App\Models\DailyRecord;
use App\Models\DosingContainer;
use App\Models\HannaDevice;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\Product;
use App\Models\TapAlert;
use App\Models\User;
use App\Services\HannaCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NewFeaturesValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
    }

    private function createTestEnvironment(): array
    {
        $installation = Installation::create([
            'name' => 'Leiria',
            'morada' => 'Rua Teste',
            'active' => true,
        ]);

        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Lazer',
            'type' => 'leisure',
            'temp_min' => 28.0,
            'temp_max' => 30.0,
            'volume' => 600.00,
            'active' => true,
        ]);

        $product = Product::create([
            'name' => 'Cloro Granulado',
            'unidade' => 'kg',
            'active' => true,
        ]);

        $admin = User::factory()->create(['name' => 'Admin Daniel']);
        $admin->assignRole('admin');

        return [
            'installation' => $installation,
            'pool' => $pool,
            'product' => $product,
            'admin' => $admin,
        ];
    }

    public function test_quick_shortcuts_are_provided_in_esquema_page(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];
        $installation = $env['installation'];

        $this->actingAs($admin);

        // Access the Esquema page via Livewire to inspect view data.
        // A vista agrupa por instalação; cada piscina traz os seus atalhos rápidos.
        Livewire::test(EsquemaPiscina::class)
            ->assertSet('installationId', $installation->id)
            ->assertViewHas('estados', function (array $estados) use ($pool) {
                $estado = collect($estados)->first(fn (array $e) => $e['piscina']->id === $pool->id);
                $this->assertNotNull($estado);
                $this->assertArrayHasKey('url_acoes_rapidas', $estado);
                $this->assertStringContainsString('tipo=torneira', $estado['url_acoes_rapidas']['torneira']);
                $this->assertStringContainsString('pool='.$pool->id, $estado['url_acoes_rapidas']['torneira']);

                return true;
            });
    }

    public function test_operational_action_creation_form_prefills_correctly(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];

        $this->actingAs($admin);

        // Access page with query parameters
        $url = OperationalActionResource::getUrl('create', [
            'pool' => $pool->id,
            'tipo' => OperationalAction::TIPO_TORNEIRA,
        ]);

        // Simulating the request to test Filament Form defaults via Livewire
        Livewire::withQueryParams(['pool' => $pool->id, 'tipo' => OperationalAction::TIPO_TORNEIRA])
            ->test(CreateOperationalAction::class)
            ->assertFormSet([
                'pool_id' => $pool->id,
                'tipo' => OperationalAction::TIPO_TORNEIRA,
            ]);
    }

    public function test_tap_alert_closes_on_closed_tap_operational_action(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];

        $this->actingAs($admin);

        // Open the tap (creates TapAlert)
        $alert = TapAlert::create([
            'pool_id' => $pool->id,
            'opened_by' => $admin->id,
            'opened_at' => now(),
        ]);

        $this->assertNull($alert->resolved_at);

        // Record an operational action to close the tap
        OperationalAction::create([
            'pool_id' => $pool->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_TORNEIRA,
            'registado_em' => now(),
            'dados' => ['agua_modo' => 'off'],
        ]);

        $alert->refresh();
        $this->assertNotNull($alert->resolved_at);
        $this->assertEquals('acao_operacional', $alert->resolution);
    }

    public function test_daily_record_corrective_action_saves_to_record_additions_via_handle_creation(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];
        $product = $env['product'];

        $createPage = new CreateDailyRecord;

        $data = [
            'installation_id' => $env['installation']->id,
            'user_id' => $admin->id,
            'registado_em' => now(),
            'pools' => [
                $pool->id => [
                    'ns_ph' => 7.20,
                    'ns_cloro_livre' => 1.50,
                    'ns_cloro_total' => 2.00,
                    'ns_temperatura' => 28.5,
                    'adicoes' => [
                        [
                            'product_id' => $product->id,
                            'quantity' => 1.5,
                            'acao_corretiva' => 'Ajuste pH e Cloro manual',
                        ],
                    ],
                ],
            ],
        ];

        $method = new \ReflectionMethod($createPage, 'handleRecordCreation');
        $method->setAccessible(true);
        $method->invoke($createPage, $data);

        $record = DailyRecord::where('pool_id', $pool->id)->first();
        $this->assertNotNull($record);

        $addition = $record->adicoes()->first();
        $this->assertNotNull($addition);
        $this->assertEquals('Ajuste pH e Cloro manual', $addition->acao_corretiva);
        $this->assertEquals($product->id, $addition->product_id);
    }

    public function test_refill_dosing_container_operational_action(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];

        $container = DosingContainer::create([
            'pool_id' => $pool->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 20000,
            'restante_ml' => 5000,
            'alerta_percent' => 20,
        ]);

        $this->assertEquals(5000.0, (float) $container->restante_ml);

        // Record an operational action to refill the chlorine container
        OperationalAction::create([
            'pool_id' => $pool->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_REABASTECIMENTO_BIDAO,
            'registado_em' => now(),
            'dados' => [
                'bidao_tipo' => DosingContainer::TIPO_CLORO,
                'quantidade_l' => 15.0,
            ],
            'observacoes' => 'Reabastecido com 15L de cloro',
        ]);

        $container->refresh();
        $this->assertEquals(15000.0, (float) $container->restante_ml);
        $this->assertNotNull($container->reabastecido_em);
        $this->assertEquals($admin->id, $container->reabastecido_por);

        // Check that a log entry was generated
        $this->assertDatabaseHas('dosing_container_logs', [
            'dosing_container_id' => $container->id,
            'tipo_movimento' => 'reabastecimento',
            'quantidade_ml' => 15000,
            'nota' => 'Reabastecido com 15L de cloro',
        ]);
    }

    public function test_hanna_sync_discounts_dosage_only_after_refill_timestamp(): void
    {
        // Mock HannaCloudService first
        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getDeviceSettings')->andReturn([]);
        $mock->shouldReceive('getLastReading')->andReturn([
            'dt' => '2026-07-21 10:15:00',
            'ph' => 7.2,
            'orp' => 700.0,
            'temperatura_agua' => 26.5,
            'temperatura_ar' => 24.0,
            'caudal_ph' => 1.5,
            'caudal_cloro' => 0.9,
            'alarms' => [],
            'raw_parameters' => [],
        ]);
        $mock->shouldReceive('getHistoryReadings')->andReturn([
            ['dt' => '2026-07-21 09:55:00', 'dose_cloro_ml' => 100.0, 'dose_ph_ml' => 0.0],
            ['dt' => '2026-07-21 10:05:00', 'dose_cloro_ml' => 50.0, 'dose_ph_ml' => 0.0],
            ['dt' => '2026-07-21 10:15:00', 'dose_cloro_ml' => 30.0, 'dose_ph_ml' => 0.0],
        ]);

        config(['services.hanna.email' => 'test@example.com']);
        config(['services.hanna.password' => 'password']);

        $env = $this->createTestEnvironment();
        $pool = $env['pool'];

        // Create Hanna device
        $device = HannaDevice::create([
            'hanna_device_id' => 'DID-SYNC-TEST',
            'name' => 'Controlador Teste',
            'active' => true,
            'pool_id' => $pool->id,
            'dose_sincronizada_ate' => Carbon::parse('2026-07-21 09:50:00'),
        ]);

        // Create dosing container refilled at 10:00
        $container = DosingContainer::create([
            'pool_id' => $pool->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 20000,
            'restante_ml' => 20000,
            'alerta_percent' => 20,
            'reabastecido_em' => Carbon::parse('2026-07-21 10:00:00'),
        ]);

        // Run sync command
        $this->artisan('hanna:sync')->assertSuccessful();

        // Refresh container level
        $container->refresh();

        // Only 50 and 30 should be deducted (total 80 mL).
        // 100 mL should be ignored because it was at 09:55 (before refill at 10:00).
        $this->assertEquals(19920.0, (float) $container->restante_ml);
    }

    public function test_refill_both_dosing_containers_operational_action(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];

        $containerCloro = DosingContainer::create([
            'pool_id' => $pool->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 20000,
            'restante_ml' => 5000,
            'alerta_percent' => 20,
        ]);

        $containerPh = DosingContainer::create([
            'pool_id' => $pool->id,
            'tipo' => DosingContainer::TIPO_PH_MENOS,
            'capacidade_ml' => 10000,
            'restante_ml' => 2000,
            'alerta_percent' => 20,
        ]);

        // 1. Refill both leaving quantity blank (should refill to capacity)
        OperationalAction::create([
            'pool_id' => $pool->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_REABASTECIMENTO_BIDAO,
            'registado_em' => now(),
            'dados' => [
                'bidao_tipo' => 'ambos',
            ],
            'observacoes' => 'Encher ambos os bidoes',
        ]);

        $containerCloro->refresh();
        $containerPh->refresh();

        $this->assertEquals(20000.0, (float) $containerCloro->restante_ml);
        $this->assertEquals(10000.0, (float) $containerPh->restante_ml);

        // 2. Refill both specifying a custom quantity (e.g. 8 Litres)
        OperationalAction::create([
            'pool_id' => $pool->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_REABASTECIMENTO_BIDAO,
            'registado_em' => now(),
            'dados' => [
                'bidao_tipo' => 'ambos',
                'quantidade_l' => 8.0,
            ],
            'observacoes' => 'Definir ambos a 8L',
        ]);

        $containerCloro->refresh();
        $containerPh->refresh();

        $this->assertEquals(8000.0, (float) $containerCloro->restante_ml);
        $this->assertEquals(8000.0, (float) $containerPh->restante_ml);
    }

    public function test_retroactive_refill_recalculates_and_deducts_consumption_correctly(): void
    {
        config(['services.hanna.email' => 'test@example.com']);
        config(['services.hanna.password' => 'password']);

        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $admin = $env['admin'];

        // Create Hanna device with sync time at 10:35
        $device = HannaDevice::create([
            'hanna_device_id' => 'DID-RETRO-TEST',
            'name' => 'Controlador Teste',
            'active' => true,
            'pool_id' => $pool->id,
            'dose_sincronizada_ate' => Carbon::parse('2026-07-21 10:35:00'),
        ]);

        // Create dosing container currently at 5,000 mL
        $container = DosingContainer::create([
            'pool_id' => $pool->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 20000,
            'restante_ml' => 5000,
            'alerta_percent' => 20,
        ]);

        // Mock HannaCloudService
        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getHistoryReadings')
            ->with('DID-RETRO-TEST', \Mockery::on(fn ($date) => $date->format('Y-m-d H:i:s') === '2026-07-21 09:00:00'), \Mockery::on(fn ($date) => $date->format('Y-m-d H:i:s') === '2026-07-21 10:35:00'))
            ->once()
            ->andReturn([
                ['dt' => '2026-07-21 09:15:00', 'dose_cloro_ml' => 100.0, 'dose_ph_ml' => 0.0],
                ['dt' => '2026-07-21 09:45:00', 'dose_cloro_ml' => 200.0, 'dose_ph_ml' => 0.0],
                ['dt' => '2026-07-21 10:15:00', 'dose_cloro_ml' => 300.0, 'dose_ph_ml' => 0.0],
            ]);

        // Run retroactive refill yesterday at 09:00 (which is before syncTime 10:35)
        $container->reabastecer(20000, $admin->id, 'Retroactive Refill', Carbon::parse('2026-07-21 09:00:00'));

        // Refresh container level
        $container->refresh();

        // Level should be 20000 - 600 (100 + 200 + 300) = 19400 mL.
        $this->assertEquals(19400.0, (float) $container->restante_ml);

        // Check that a consumo_sonda log entry was generated
        $this->assertDatabaseHas('dosing_container_logs', [
            'dosing_container_id' => $container->id,
            'tipo_movimento' => 'consumo_sonda',
            'quantidade_ml' => 600,
            'nota' => 'Consumo recalculado retroativamente após reabastecimento',
        ]);
    }
}
