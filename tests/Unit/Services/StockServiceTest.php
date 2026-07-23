<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Installation;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\StockWarehouse;
use App\Models\User;
use App\Services\StockService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private function stockInstallation(float $quantity = 0.0): StockInstallation
    {
        $installation = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $product = Product::factory()->create(['name' => 'Cloro', 'unidade' => 'kg']);

        return StockInstallation::create([
            'installation_id' => $installation->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'limite_minimo' => 2.0,
        ]);
    }

    public function test_add_installation_stock_increments_and_logs(): void
    {
        $stock = $this->stockInstallation(5.0);
        $user = User::factory()->create();

        app(StockService::class)->addInstallationStock($stock->id, 3.5, $user->id);

        $this->assertSame(8.5, (float) $stock->fresh()->quantity);
        $this->assertDatabaseHas('stock_installation_logs', [
            'stock_installation_id' => $stock->id,
            'tipo_movimento' => 'entrada',
            'quantity' => 3.5,
        ]);
    }

    public function test_add_installation_stock_rejects_non_positive(): void
    {
        $stock = $this->stockInstallation(5.0);
        $user = User::factory()->create();

        $this->expectException(DomainException::class);

        app(StockService::class)->addInstallationStock($stock->id, 0.0, $user->id);
    }

    public function test_add_warehouse_stock_rejects_non_positive(): void
    {
        $product = Product::factory()->create();
        $warehouse = StockWarehouse::create(['product_id' => $product->id, 'quantity' => 10.0]);
        $user = User::factory()->create();

        $this->expectException(DomainException::class);

        app(StockService::class)->addWarehouseStock($warehouse->id, -1.0, $user->id);
    }

    public function test_transfer_rejects_non_positive(): void
    {
        $installation = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $product = Product::factory()->create();
        $warehouse = StockWarehouse::create(['product_id' => $product->id, 'quantity' => 10.0]);
        $user = User::factory()->create();

        $this->expectException(DomainException::class);

        app(StockService::class)->transferToInstallation($warehouse->id, $installation->id, 0.0, $user->id);
    }
}
