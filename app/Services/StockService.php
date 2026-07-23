<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\StockWarehouse;
use App\Models\StockWarehouseLog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Serviço responsável por toda a gestão de movimentos de Stock.
 * Encapsula transações da BD, isolando a lógica de negócio do UI (Filament).
 */
class StockService
{
    /**
     * Dá entrada de stock no armazém principal.
     */
    public function addWarehouseStock(int|string $warehouseId, float $quantity, int|string $userId, ?string $observacoes = null): void
    {
        DB::transaction(function () use ($warehouseId, $quantity, $userId, $observacoes) {
            $fresh = StockWarehouse::lockForUpdate()->findOrFail($warehouseId);
            $fresh->quantity += $quantity;
            $fresh->save();

            StockWarehouseLog::create([
                'product_id' => $fresh->product_id,
                'user_id' => $userId,
                'tipo_movimento' => 'entrada',
                'quantity' => $quantity,
                'fornecedor' => $observacoes,
            ]);
        });
    }

    /**
     * Transfere stock do armazém principal para uma instalação específica.
     *
     * @throws DomainException Se o stock no armazém for insuficiente.
     */
    public function transferToInstallation(int|string $warehouseId, int|string $installationId, float $quantity, int|string $userId, ?string $observacoes = null): void
    {
        DB::transaction(function () use ($warehouseId, $installationId, $quantity, $userId, $observacoes) {
            $freshArmazem = StockWarehouse::lockForUpdate()->findOrFail($warehouseId);

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

            if ($freshArmazem->quantity < $quantity) {
                throw new DomainException("Stock insuficiente no armazém. Disponível: {$freshArmazem->quantity}. Pedido: {$quantity}.");
            }

            $freshArmazem->quantity -= $quantity;
            $freshArmazem->save();

            StockWarehouseLog::create([
                'product_id' => $freshArmazem->product_id,
                'user_id' => $userId,
                'tipo_movimento' => 'saida',
                'quantity' => $quantity,
                'fornecedor' => $observacoes,
            ]);

            $stockInstalacao->quantity += $quantity;
            $stockInstalacao->save();

            StockInstallationLog::create([
                'stock_installation_id' => $stockInstalacao->id,
                'user_id' => $userId,
                'tipo_movimento' => 'entrada',
                'quantity' => $quantity,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Consome (reduz) stock manualmente de uma instalação.
     *
     * @throws DomainException Se o stock na instalação for insuficiente.
     */
    public function consumeInstallationStock(int|string $stockInstallationId, float $quantity, int|string $userId): void
    {
        DB::transaction(function () use ($stockInstallationId, $quantity, $userId) {
            $fresh = StockInstallation::lockForUpdate()->findOrFail($stockInstallationId);

            if ($fresh->quantity < $quantity) {
                throw new DomainException("Stock insuficiente na instalação. Disponível: {$fresh->quantity}. Pedido: {$quantity}.");
            }

            $fresh->quantity -= $quantity;
            $fresh->save();

            StockInstallationLog::create([
                'stock_installation_id' => $fresh->id,
                'user_id' => $userId,
                'tipo_movimento' => 'consumo',
                'quantity' => $quantity,
                'created_at' => now(),
            ]);
        });
    }
}
