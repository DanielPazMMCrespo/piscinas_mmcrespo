<?php declare(strict_types=1);
namespace App\Filament\Resources\StockWarehouseResource\Pages;


use App\Filament\Resources\StockWarehouseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStockWarehouse extends EditRecord
{
    protected static string $resource = StockWarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ((float) $record->quantity < 0) {
            $record->quantity = 0;
            $record->saveQuietly();
        }

        \App\Models\StockWarehouseLog::create([
            'product_id'     => $record->product_id,
            'user_id'        => auth()->id(),
            'tipo_movimento' => 'ajuste',
            'quantity'       => $record->quantity,
            'fornecedor'     => 'Edição direta (ajuste de inventário)',
            'created_at'     => now(),
        ]);
    }
}
