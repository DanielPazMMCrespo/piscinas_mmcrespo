<?php declare(strict_types=1);
namespace App\Filament\Resources\StockWarehouseResource\Pages;


use App\Filament\Resources\StockWarehouseResource;
use App\Models\StockWarehouse;
use App\Models\StockWarehouseLog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditStockWarehouse extends EditRecord
{
    protected static string $resource = StockWarehouseResource::class;

    private float $quantidadeAnterior = 0.0;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $fresh = StockWarehouse::lockForUpdate()->findOrFail($record->id);
            $this->quantidadeAnterior = (float) $fresh->quantity;

            $fresh->fill($data);
            if ((float) $fresh->quantity < 0) {
                $fresh->quantity = 0;
            }
            $fresh->save();

            return $fresh;
        });
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        $delta = round((float) $record->quantity - $this->quantidadeAnterior, 3);

        if ($delta === 0.0) {
            return;
        }

        StockWarehouseLog::create([
            'stock_warehouse_id' => $record->id,
            'user_id' => auth()->id(),
            'tipo_movimento' => $delta > 0 ? 'entrada' : 'saida',
            'quantity' => abs($delta),
            'fornecedor' => 'Edição direta (ajuste de inventário)',
            'created_at' => now(),
        ]);
    }
}
