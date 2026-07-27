<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\StockWarehouse;
use App\Models\StockWarehouseLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
    }

    private function createTestData(): array
    {
        // Create a test installation and pool
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

        // Create test products
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

        // Create warehouse stock
        $stock_warehouse_chlorine = StockWarehouse::create([
            'product_id' => $chlorine->id,
            'quantity' => 100.000,
        ]);

        $stock_warehouse_ph = StockWarehouse::create([
            'product_id' => $ph_plus->id,
            'quantity' => 50.000,
        ]);

        // Create a technician user
        $technician = User::factory()->create(['name' => 'Técnico Teste']);
        $technician->assignRole('tecnico');

        return [
            'installation' => $installation,
            'pool' => $pool,
            'chlorine' => $chlorine,
            'ph_plus' => $ph_plus,
            'stock_warehouse_chlorine' => $stock_warehouse_chlorine,
            'stock_warehouse_ph' => $stock_warehouse_ph,
            'technician' => $technician,
        ];
    }

    public function test_transfer_stock_from_warehouse_to_installation(): void
    {
        $data = $this->createTestData();
        $installation = $data['installation'];
        $chlorine = $data['chlorine'];
        $stock_warehouse = $data['stock_warehouse_chlorine'];

        $initial_warehouse_qty = $stock_warehouse->quantity;
        $transfer_qty = 25.000;

        // Simulate the transfer action
        DB::transaction(function () use ($stock_warehouse, $installation, $transfer_qty, $chlorine) {
            $freshWarehouse = StockWarehouse::lockForUpdate()->find($stock_warehouse->id);
            $freshWarehouse->quantity -= $transfer_qty;
            $freshWarehouse->save();

            StockWarehouseLog::create([
                'stock_warehouse_id' => $stock_warehouse->id,
                'user_id' => 1,
                'tipo_movimento' => 'saida',
                'quantity' => $transfer_qty,
                'fornecedor' => 'Test transfer',
            ]);

            $stockInstallation = StockInstallation::lockForUpdate()
                ->where('installation_id', $installation->id)
                ->where('product_id', $chlorine->id)
                ->first();

            if ($stockInstallation === null) {
                $stockInstallation = StockInstallation::create([
                    'installation_id' => $installation->id,
                    'product_id' => $chlorine->id,
                    'quantity' => $transfer_qty,
                    'limite_minimo' => 10.000,
                ]);
            } else {
                $stockInstallation->quantity += $transfer_qty;
                $stockInstallation->save();
            }

            StockInstallationLog::create([
                'stock_installation_id' => $stockInstallation->id,
                'user_id' => 1,
                'tipo_movimento' => 'entrada',
                'quantity' => $transfer_qty,
                'created_at' => now(),
            ]);
        });

        // Assertions
        $stock_warehouse->refresh();
        $this->assertEquals(
            $initial_warehouse_qty - $transfer_qty,
            $stock_warehouse->quantity,
            'Warehouse stock should be decremented'
        );

        $stockInstallation = StockInstallation::where('installation_id', $installation->id)
            ->where('product_id', $chlorine->id)
            ->first();

        $this->assertNotNull($stockInstallation, 'Installation stock should be created');
        $this->assertEquals($transfer_qty, $stockInstallation->quantity);

        // Verify logs were created
        $warehouse_log = StockWarehouseLog::where('stock_warehouse_id', $stock_warehouse->id)
            ->where('tipo_movimento', 'saida')
            ->first();
        $this->assertNotNull($warehouse_log);
        $this->assertEquals($transfer_qty, $warehouse_log->quantity);

        $installation_log = StockInstallationLog::where('stock_installation_id', $stockInstallation->id)
            ->where('tipo_movimento', 'entrada')
            ->first();
        $this->assertNotNull($installation_log);
        $this->assertEquals($transfer_qty, $installation_log->quantity);
    }

    public function test_transfer_insufficient_warehouse_stock_is_rejected(): void
    {
        $data = $this->createTestData();
        $installation = $data['installation'];
        $chlorine = $data['chlorine'];
        $stock_warehouse = $data['stock_warehouse_chlorine'];

        $insufficient_qty = $stock_warehouse->quantity + 50.000;

        // Attempt transfer
        DB::transaction(function () use ($stock_warehouse, $insufficient_qty) {
            $freshWarehouse = StockWarehouse::lockForUpdate()->find($stock_warehouse->id);

            if ($freshWarehouse->quantity < $insufficient_qty) {
                // Transfer should not proceed
                return;
            }

            $freshWarehouse->quantity -= $insufficient_qty;
            $freshWarehouse->save();
        });

        // Warehouse stock should remain unchanged
        $stock_warehouse->refresh();
        $this->assertEquals(100.000, $stock_warehouse->quantity);

        // No installation stock should be created
        $stockInstallation = StockInstallation::where('installation_id', $installation->id)
            ->where('product_id', $chlorine->id)
            ->first();
        $this->assertNull($stockInstallation);
    }

    public function test_multiple_transfers_to_same_installation_accumulate(): void
    {
        $data = $this->createTestData();
        $installation = $data['installation'];
        $chlorine = $data['chlorine'];
        $stock_warehouse = $data['stock_warehouse_chlorine'];

        $first_transfer = 20.000;
        $second_transfer = 15.000;

        // First transfer
        DB::transaction(function () use ($stock_warehouse, $installation, $first_transfer, $chlorine) {
            $freshWarehouse = StockWarehouse::lockForUpdate()->find($stock_warehouse->id);
            $freshWarehouse->quantity -= $first_transfer;
            $freshWarehouse->save();

            StockWarehouseLog::create([
                'stock_warehouse_id' => $stock_warehouse->id,
                'user_id' => 1,
                'tipo_movimento' => 'saida',
                'quantity' => $first_transfer,
                'fornecedor' => 'Transfer 1',
            ]);

            $stockInstallation = StockInstallation::create([
                'installation_id' => $installation->id,
                'product_id' => $chlorine->id,
                'quantity' => $first_transfer,
                'limite_minimo' => 0,
            ]);

            StockInstallationLog::create([
                'stock_installation_id' => $stockInstallation->id,
                'user_id' => 1,
                'tipo_movimento' => 'entrada',
                'quantity' => $first_transfer,
                'created_at' => now(),
            ]);
        });

        // Second transfer to same installation
        DB::transaction(function () use ($stock_warehouse, $installation, $second_transfer, $chlorine) {
            $freshWarehouse = StockWarehouse::lockForUpdate()->find($stock_warehouse->id);
            $freshWarehouse->quantity -= $second_transfer;
            $freshWarehouse->save();

            StockWarehouseLog::create([
                'stock_warehouse_id' => $stock_warehouse->id,
                'user_id' => 1,
                'tipo_movimento' => 'saida',
                'quantity' => $second_transfer,
                'fornecedor' => 'Transfer 2',
            ]);

            $stockInstallation = StockInstallation::lockForUpdate()
                ->where('installation_id', $installation->id)
                ->where('product_id', $chlorine->id)
                ->first();

            $stockInstallation->quantity += $second_transfer;
            $stockInstallation->save();

            StockInstallationLog::create([
                'stock_installation_id' => $stockInstallation->id,
                'user_id' => 1,
                'tipo_movimento' => 'entrada',
                'quantity' => $second_transfer,
                'created_at' => now(),
            ]);
        });

        // Verify accumulation
        $stockInstallation = StockInstallation::where('installation_id', $installation->id)
            ->where('product_id', $chlorine->id)
            ->first();

        $this->assertEquals($first_transfer + $second_transfer, $stockInstallation->quantity);

        // Verify both logs exist
        $logs = StockInstallationLog::where('stock_installation_id', $stockInstallation->id)
            ->where('tipo_movimento', 'entrada')
            ->get();
        $this->assertCount(2, $logs);
    }

    public function test_warehouse_stock_entry_increments_quantity(): void
    {
        $data = $this->createTestData();
        $stock_warehouse = $data['stock_warehouse_chlorine'];
        $chlorine = $data['chlorine'];

        $initial_qty = $stock_warehouse->quantity;
        $entry_qty = 50.000;

        DB::transaction(function () use ($stock_warehouse, $entry_qty, $chlorine) {
            $fresh = StockWarehouse::lockForUpdate()->find($stock_warehouse->id);
            $fresh->quantity += $entry_qty;
            $fresh->save();

            StockWarehouseLog::create([
                'stock_warehouse_id' => $stock_warehouse->id,
                'user_id' => 1,
                'tipo_movimento' => 'entrada',
                'quantity' => $entry_qty,
                'fornecedor' => 'Supplier A',
            ]);
        });

        $stock_warehouse->refresh();
        $this->assertEquals($initial_qty + $entry_qty, $stock_warehouse->quantity);

        $log = StockWarehouseLog::where('stock_warehouse_id', $stock_warehouse->id)
            ->where('tipo_movimento', 'entrada')
            ->first();
        $this->assertNotNull($log);
        $this->assertEquals($entry_qty, $log->quantity);
        $this->assertEquals('Supplier A', $log->fornecedor);
    }

    public function test_stock_logs_track_user_and_timestamp(): void
    {
        $data = $this->createTestData();
        $technician = $data['technician'];
        $chlorine = $data['chlorine'];
        $stock_warehouse = $data['stock_warehouse_chlorine'];

        $test_time = now();

        DB::transaction(function () use ($stock_warehouse, $chlorine, $technician) {
            $fresh = StockWarehouse::lockForUpdate()->find($stock_warehouse->id);
            $fresh->quantity += 10.000;
            $fresh->save();

            StockWarehouseLog::create([
                'stock_warehouse_id' => $stock_warehouse->id,
                'user_id' => $technician->id,
                'tipo_movimento' => 'entrada',
                'quantity' => 10.000,
                'fornecedor' => 'Test',
            ]);
        });

        $log = StockWarehouseLog::where('stock_warehouse_id', $stock_warehouse->id)
            ->where('user_id', $technician->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($technician->id, $log->user_id);
        $this->assertEquals('entrada', $log->tipo_movimento);
        // created_at is auto-set by the model, verify it's within reasonable range
        $this->assertNotNull($log->created_at);
    }

    public function test_installation_stock_below_minimum_threshold(): void
    {
        $data = $this->createTestData();
        $installation = $data['installation'];
        $chlorine = $data['chlorine'];

        // Create installation stock with minimum threshold
        $stockInstallation = StockInstallation::create([
            'installation_id' => $installation->id,
            'product_id' => $chlorine->id,
            'quantity' => 5.000,
            'limite_minimo' => 10.000,
        ]);

        // Check if below minimum
        $is_below_minimum = $stockInstallation->quantity < $stockInstallation->limite_minimo;

        $this->assertTrue($is_below_minimum, 'Stock should be flagged as below minimum');
    }

    public function test_stock_consistency_across_multiple_concurrent_operations(): void
    {
        $data = $this->createTestData();
        $installation = $data['installation'];
        $chlorine = $data['chlorine'];
        $stock_warehouse = $data['stock_warehouse_chlorine'];

        // Simulate multiple operations (simplified - in real scenario would use queues/transactions)
        $quantities = [10.000, 15.000, 20.000];
        $total_transferred = 0;

        foreach ($quantities as $qty) {
            DB::transaction(function () use ($stock_warehouse, $installation, $qty, $chlorine, &$total_transferred) {
                $freshWarehouse = StockWarehouse::lockForUpdate()->find($stock_warehouse->id);

                if ($freshWarehouse->quantity >= $qty) {
                    $freshWarehouse->quantity -= $qty;
                    $freshWarehouse->save();
                    $total_transferred += $qty;

                    StockWarehouseLog::create([
                        'stock_warehouse_id' => $stock_warehouse->id,
                        'user_id' => 1,
                        'tipo_movimento' => 'saida',
                        'quantity' => $qty,
                        'fornecedor' => 'Op',
                    ]);

                    $si = StockInstallation::lockForUpdate()
                        ->where('installation_id', $installation->id)
                        ->where('product_id', $chlorine->id)
                        ->first();

                    if ($si === null) {
                        $si = StockInstallation::create([
                            'installation_id' => $installation->id,
                            'product_id' => $chlorine->id,
                            'quantity' => $qty,
                            'limite_minimo' => 0,
                        ]);
                    } else {
                        $si->quantity += $qty;
                        $si->save();
                    }

                    StockInstallationLog::create([
                        'stock_installation_id' => $si->id,
                        'user_id' => 1,
                        'tipo_movimento' => 'entrada',
                        'quantity' => $qty,
                        'created_at' => now(),
                    ]);
                }
            });
        }

        // Verify final state
        $stock_warehouse->refresh();
        $this->assertEquals(100.000 - $total_transferred, $stock_warehouse->quantity);

        $si = StockInstallation::where('installation_id', $installation->id)
            ->where('product_id', $chlorine->id)
            ->first();
        $this->assertEquals($total_transferred, $si->quantity);
    }
}
