<?php

namespace App\Filament\Resources\StockWarehouseResource\Pages;

use App\Filament\Resources\StockWarehouseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockWarehouses extends ListRecords
{
    protected static string $resource = StockWarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
