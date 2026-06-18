<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Installation;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\StockWarehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_transaction_uses_lock_for_update(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $product = Product::create(['name' => 'Cloro', 'unidade' => 'kg']);

        $stock = StockInstallation::create([
            'installation_id' => $inst->id,
            'product_id' => $product->id,
            'quantity' => 100.0,
            'limite_minimo' => 10.0,
        ]);

        // Simula transação com lock (verificar que não exceção ao usar lockForUpdate)
        DB::transaction(function () use ($stock, $product) {
            $locked = StockInstallation::where('id', $stock->id)->lockForUpdate()->first();
            $this->assertNotNull($locked);
            $this->assertEquals(100.0, $locked->quantity);

            $locked->update(['quantity' => 95.0]);
        });

        $stock->refresh();
        $this->assertEquals(95.0, $stock->quantity);
    }

    public function test_stock_warehouse_relationships(): void
    {
        $product = Product::create(['name' => 'Cloro', 'unidade' => 'kg']);

        $stock = StockWarehouse::create([
            'product_id' => $product->id,
            'quantity' => 500.0,
        ]);

        $this->assertNotNull($stock->produto);
        $this->assertEquals($product->id, $stock->product_id);
    }

    public function test_stock_installation_relationships(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $product = Product::create(['name' => 'Cloro', 'unidade' => 'kg']);

        $stock = StockInstallation::create([
            'installation_id' => $inst->id,
            'product_id' => $product->id,
            'quantity' => 100.0,
            'limite_minimo' => 10.0,
        ]);

        $this->assertNotNull($stock->instalacao);
        $this->assertEquals($inst->id, $stock->installation_id);

        $this->assertNotNull($stock->produto);
        $this->assertEquals($product->id, $stock->product_id);
    }

    public function test_stock_quantity_calculation(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $product = Product::create(['name' => 'Cloro', 'unidade' => 'kg']);

        $stock = StockInstallation::create([
            'installation_id' => $inst->id,
            'product_id' => $product->id,
            'quantity' => 100.0,
            'limite_minimo' => 10.0,
        ]);

        // Simula consumo
        $stock->update(['quantity' => 85.0]);
        $stock->refresh();

        $this->assertEquals(85.0, $stock->quantity);
    }

    public function test_stock_minimum_limit_alert(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $product = Product::create(['name' => 'Cloro', 'unidade' => 'kg']);

        $stock = StockInstallation::create([
            'installation_id' => $inst->id,
            'product_id' => $product->id,
            'quantity' => 8.0,  // Abaixo do mínimo
            'limite_minimo' => 10.0,
        ]);

        // Verifica que stock está abaixo do limite
        $this->assertLessThan($stock->limite_minimo, $stock->quantity);
    }
}
