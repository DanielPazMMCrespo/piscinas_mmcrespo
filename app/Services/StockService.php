<?php declare(strict_types=1);

namespace App\Services;

use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\StockWarehouse;
use App\Models\StockWarehouseLog;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Regista uma entrada de stock no armazém central.
     */
    public function addWarehouseStock(int $warehouseStockId, float $quantidade, int $userId, ?string $observacoes = null): void
    {
        DB::transaction(function () use ($warehouseStockId, $quantidade, $userId, $observacoes): void {
            $fresh = StockWarehouse::lockForUpdate()->findOrFail($warehouseStockId);
            $fresh->quantity += $quantidade;
            $fresh->save();

            StockWarehouseLog::create([
                'product_id' => $fresh->product_id,
                'user_id' => $userId,
                'tipo_movimento' => 'entrada',
                'quantity' => $quantidade,
                'fornecedor' => $observacoes,
            ]);
        });
    }

    /**
     * Transfer stock do armazém central para uma instalação.
     * Retorna true se a transferência for bem-sucedida, false se o stock for insuficiente.
     */
    public function transferWarehouseStockToInstallation(
        int $warehouseStockId,
        int $installationId,
        float $quantidade,
        int $userId,
        ?string $observacoes = null
    ): bool {
        return DB::transaction(function () use ($warehouseStockId, $installationId, $quantidade, $userId, $observacoes): bool {
            $freshArmazem = StockWarehouse::lockForUpdate()->findOrFail($warehouseStockId);

            if ($freshArmazem->quantity < $quantidade) {
                return false;
            }

            $stockInstalacao = StockInstallation::firstOrCreate(
                [
                    'installation_id' => $installationId,
                    'product_id' => $freshArmazem->product_id,
                ],
                [
                    'quantity' => 0.0,
                    'limite_minimo' => 0,
                ]
            );

            $stockInstalacao = StockInstallation::lockForUpdate()->findOrFail($stockInstalacao->id);

            $freshArmazem->quantity -= $quantidade;
            $freshArmazem->save();

            StockWarehouseLog::create([
                'product_id' => $freshArmazem->product_id,
                'user_id' => $userId,
                'tipo_movimento' => 'saida',
                'quantity' => $quantidade,
                'fornecedor' => $observacoes,
            ]);

            $stockInstalacao->quantity += $quantidade;
            $stockInstalacao->save();

            StockInstallationLog::create([
                'stock_installation_id' => $stockInstalacao->id,
                'user_id' => $userId,
                'tipo_movimento' => 'entrada',
                'quantity' => $quantidade,
                'created_at' => now(),
            ]);

            return true;
        });
    }
}
