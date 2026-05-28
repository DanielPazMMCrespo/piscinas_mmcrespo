<?php

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
}
