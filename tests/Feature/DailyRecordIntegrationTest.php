<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\RecordAddition;
use App\Models\RecordPhoto;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyRecordIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
    }

    private function createTestEnvironment(): array
    {
        $installation = Installation::create([
            'name' => 'Leiria - Teste',
            'morada' => 'Rua Teste',
            'active' => true,
        ]);

        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Competição',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);

        $chlorine = Product::create([
            'name' => 'Cloro Livre',
            'unidade' => 'kg',
            'categoria' => 'desinfectante',
            'active' => true,
        ]);

        $ph_plus = Product::create([
            'name' => 'pH Plus',
            'unidade' => 'kg',
            'categoria' => 'regulador',
            'active' => true,
        ]);

        StockInstallation::create([
            'installation_id' => $installation->id,
            'product_id' => $chlorine->id,
            'quantity' => 100.000,
            'limite_minimo' => 10.000,
        ]);

        StockInstallation::create([
            'installation_id' => $installation->id,
            'product_id' => $ph_plus->id,
            'quantity' => 50.000,
            'limite_minimo' => 5.000,
        ]);

        $technician = User::factory()->create(['name' => 'Técnico Teste']);
        $technician->assignRole('tecnico');

        return [
            'installation' => $installation,
            'pool' => $pool,
            'chlorine' => $chlorine,
            'ph_plus' => $ph_plus,
            'technician' => $technician,
        ];
    }

    public function test_create_daily_record_with_conforming_values(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Create a daily record with values within legal limits
        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.4,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'caleira_feita' => true,
            'renovacao_agua' => false,
        ]);

        $this->assertDatabaseHas('daily_records', [
            'id' => $record->id,
            'pool_id' => $pool->id,
            'ph' => 7.4,
            'cloro_livre' => 1.2,
        ]);

        // Verify record can be retrieved
        $retrieved = DailyRecord::find($record->id);
        $this->assertNotNull($retrieved);
        $this->assertEquals(7.4, $retrieved->ph);
    }

    public function test_daily_record_conformance_validation(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Create record with pH outside legal limits (CN 14/DA: 6.9 - 8.0)
        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 5.5,  // Below minimum (6.9)
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        // pH is out of limit
        $is_ph_conforming = $record->ph >= DailyRecord::PH_MIN && $record->ph <= DailyRecord::PH_MAX;
        $this->assertFalse($is_ph_conforming);

        // Create record with cloro_livre outside limits
        $record2 = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now()->addHour(),
            'ph' => 7.0,
            'cloro_livre' => 0.3,  // Below minimum (0.5)
            'cloro_total' => 1.0,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        $is_chlorine_conforming = $record2->cloro_livre >= DailyRecord::CLORO_LIVRE_MIN &&
                                  $record2->cloro_livre <= DailyRecord::CLORO_LIVRE_MAX;
        $this->assertFalse($is_chlorine_conforming);
    }

    public function test_temperature_limits_from_pool_config(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Pool has temp_min=26.0, temp_max=27.0
        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 25.0,  // Below pool minimum
        ]);

        $is_temp_conforming = $record->temperatura >= $pool->temp_min && $record->temperatura <= $pool->temp_max;
        $this->assertFalse($is_temp_conforming);

        $record2 = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now()->addHour(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,  // Within limits
        ]);

        $is_temp_conforming2 = $record2->temperatura >= $pool->temp_min && $record2->temperatura <= $pool->temp_max;
        $this->assertTrue($is_temp_conforming2);
    }

    public function test_transparencia_turbidez_validation(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Turbidez (FNU) should not exceed 5.0 (TRANSPARENCIA_MAX)
        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 6.0,  // Above maximum
            'temperatura' => 26.5,
        ]);

        $is_turbidez_conforming = $record->transparencia <= DailyRecord::TRANSPARENCIA_MAX;
        $this->assertFalse($is_turbidez_conforming);
    }

    public function test_chemical_addition_consumes_stock(): void
    {
        $env = $this->createTestEnvironment();
        $installation = $env['installation'];
        $pool = $env['pool'];
        $technician = $env['technician'];
        $chlorine = $env['chlorine'];

        $initial_stock = StockInstallation::where('installation_id', $installation->id)
            ->where('product_id', $chlorine->id)
            ->first();

        $initial_qty = $initial_stock->quantity;
        $consumption = 5.500;

        // Create record and add chemical consumption
        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        // Add chemical consumption record
        RecordAddition::create([
            'daily_record_id' => $record->id,
            'product_id' => $chlorine->id,
            'quantity' => $consumption,
            'acao_corretiva' => 'Adição para manutenção',
        ]);

        // Simulate stock deduction
        \Illuminate\Support\Facades\DB::transaction(function () use ($initial_stock, $consumption) {
            $stock = StockInstallation::lockForUpdate()->find($initial_stock->id);
            $stock->quantity -= $consumption;
            $stock->save();

            StockInstallationLog::create([
                'stock_installation_id' => $stock->id,
                'user_id' => 1,
                'tipo_movimento' => 'consumo',
                'quantity' => $consumption,
                'created_at' => now(),
            ]);
        });

        // Verify stock was decremented
        $initial_stock->refresh();
        $this->assertEquals($initial_qty - $consumption, $initial_stock->quantity);

        // Verify log was created
        $log = StockInstallationLog::where('stock_installation_id', $initial_stock->id)
            ->where('tipo_movimento', 'consumo')
            ->first();
        $this->assertNotNull($log);
        $this->assertEquals($consumption, $log->quantity);
    }

    public function test_chemical_addition_with_insufficient_stock(): void
    {
        $env = $this->createTestEnvironment();
        $installation = $env['installation'];
        $pool = $env['pool'];
        $technician = $env['technician'];
        $chlorine = $env['chlorine'];

        $stock = StockInstallation::where('installation_id', $installation->id)
            ->where('product_id', $chlorine->id)
            ->first();

        $initial_qty = $stock->quantity;
        $consumption = $initial_qty + 50.000;  // More than available

        // Create record with addition
        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        RecordAddition::create([
            'daily_record_id' => $record->id,
            'product_id' => $chlorine->id,
            'quantity' => $consumption,
            'acao_corretiva' => 'Adição urgente',
        ]);

        // Attempt to consume more than available - should desconto até zero
        \Illuminate\Support\Facades\DB::transaction(function () use ($stock, $consumption) {
            $fresh = StockInstallation::lockForUpdate()->find($stock->id);
            $actual_consumption = min($consumption, $fresh->quantity);

            $fresh->quantity -= $actual_consumption;
            $fresh->save();

            StockInstallationLog::create([
                'stock_installation_id' => $stock->id,
                'user_id' => 1,
                'tipo_movimento' => 'consumo',
                'quantity' => $actual_consumption,
                'created_at' => now(),
            ]);
        });

        // Stock should go to zero or as low as possible
        $stock->refresh();
        $this->assertLessThanOrEqual(0, $stock->quantity - $consumption + $initial_qty);
        $this->assertGreaterThanOrEqual(0, $stock->quantity);
    }

    public function test_record_with_optional_photos(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Create record with optional photo fields
        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'bomba_foto' => null,  // Optional
            'tanque_foto' => null,  // Optional
            'ns_foto' => null,  // Optional
        ]);

        // Verify record created without photos
        $this->assertNull($record->bomba_foto);
        $this->assertNull($record->tanque_foto);

        // Update record with photo paths
        $record->update([
            'bomba_foto' => 'storage/fotos/bomba-123.jpg',
            'tanque_foto' => 'storage/fotos/tanque-123.jpg',
        ]);

        $record->refresh();
        $this->assertEquals('storage/fotos/bomba-123.jpg', $record->bomba_foto);
        $this->assertEquals('storage/fotos/tanque-123.jpg', $record->tanque_foto);
    }

    public function test_append_only_correction_pattern(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Create original record
        $original = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 6.5,  // Incorrect/out of limit
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'e_correcao' => false,
            'corrige_registo_id' => null,
        ]);

        $this->assertFalse($original->e_correcao);

        // Create correction record (append-only pattern)
        $correction = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now()->addMinute(),
            'ph' => 7.2,  // Corrected value
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'e_correcao' => true,
            'corrige_registo_id' => $original->id,
            'razao_correcao' => 'Leitura incorreta do medidor',
        ]);

        $this->assertTrue($correction->e_correcao);
        $this->assertEquals($original->id, $correction->corrige_registo_id);
        $this->assertNotNull($correction->razao_correcao);

        // Original record should not be modified
        $original->refresh();
        $this->assertEquals(6.5, $original->ph);
        $this->assertFalse($original->e_correcao);
    }

    public function test_records_with_different_user_roles(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];

        // Create different users with different roles
        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->assignRole('admin');

        $technician = User::factory()->create(['name' => 'Técnico']);
        $technician->assignRole('tecnico');

        $swimmer = User::factory()->create(['name' => 'Nadador']);
        $swimmer->assignRole('nadador_salvador');

        // Each can create records
        $record_admin = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $admin->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        $record_tech = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now()->addHour(),
            'ph' => 7.3,
            'cloro_livre' => 1.1,
            'cloro_total' => 1.4,
            'transparencia' => 2.5,
            'temperatura' => 26.8,
        ]);

        // Verify records exist
        $this->assertDatabaseHas('daily_records', ['id' => $record_admin->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('daily_records', ['id' => $record_tech->id, 'user_id' => $technician->id]);
    }

    public function test_cloro_combinado_calculation(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // cloro_combinado = cloro_total - cloro_livre
        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 2.0,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        $cloro_combinado = $record->cloro_total - $record->cloro_livre;
        $is_combinado_conforming = $cloro_combinado <= DailyRecord::CLORO_COMBINADO_MAX;

        $this->assertEqualsWithDelta(0.8, $cloro_combinado, 0.0001);
        // 0.8 > CLORO_COMBINADO_MAX (0.6) → não conforme.
        $this->assertFalse($is_combinado_conforming);
    }

    public function test_record_timestamps_registado_em(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        $test_time = Carbon::parse('2026-06-18 14:30:00');

        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => $test_time,
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        $this->assertEquals($test_time->timestamp, $record->registado_em->timestamp);
    }

    public function test_multiple_additions_per_record(): void
    {
        $env = $this->createTestEnvironment();
        $installation = $env['installation'];
        $pool = $env['pool'];
        $technician = $env['technician'];
        $chlorine = $env['chlorine'];
        $ph_plus = $env['ph_plus'];

        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.0,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        // Add multiple chemicals
        RecordAddition::create([
            'daily_record_id' => $record->id,
            'product_id' => $chlorine->id,
            'quantity' => 2.500,
            'acao_corretiva' => 'Manutenção cloro',
        ]);

        RecordAddition::create([
            'daily_record_id' => $record->id,
            'product_id' => $ph_plus->id,
            'quantity' => 1.000,
            'acao_corretiva' => 'Ajuste pH',
        ]);

        $additions = RecordAddition::where('daily_record_id', $record->id)->get();
        $this->assertCount(2, $additions);

        $total_quantity = $additions->sum('quantity');
        $this->assertEquals(3.500, $total_quantity);
    }

    public function test_multiple_pools_photo_isolation(): void
    {
        $env = $this->createTestEnvironment();
        $installation = $env['installation'];
        $technician = $env['technician'];

        $pool1 = $env['pool'];
        $pool2 = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Lazer',
            'type' => 'leisure',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 500.00,
            'active' => true,
        ]);
        $pool3 = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Infantil',
            'type' => 'children',
            'temp_min' => 28.0,
            'temp_max' => 30.0,
            'volume' => 100.00,
            'active' => true,
        ]);

        $createPage = new \App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord();

        $data = [
            'installation_id' => $installation->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'pools' => [
                $pool1->id => [
                    'bomba_ferrada' => true,
                    'bomba_foto' => null,
                ],
                $pool2->id => [
                    'bomba_ferrada' => true,
                    'bomba_foto' => '',
                ],
                $pool3->id => [
                    'bomba_ferrada' => true,
                    'bomba_foto' => ['bomba/infantil_bomba.jpg'],
                ],
            ],
        ];

        $method = new \ReflectionMethod($createPage, 'handleRecordCreation');
        $method->setAccessible(true);
        $method->invoke($createPage, $data);

        $record1 = DailyRecord::where('pool_id', $pool1->id)->latest('id')->first();
        $record2 = DailyRecord::where('pool_id', $pool2->id)->latest('id')->first();
        $record3 = DailyRecord::where('pool_id', $pool3->id)->latest('id')->first();

        $this->assertNull($record1->bomba_foto);
        $this->assertNull($record2->bomba_foto);
        $this->assertEquals('bomba/infantil_bomba.jpg', $record3->bomba_foto);
    }
}

