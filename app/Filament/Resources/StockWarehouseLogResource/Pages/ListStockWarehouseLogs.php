<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockWarehouseLogResource\Pages;

use App\Filament\Resources\StockWarehouseLogResource;
use Filament\Resources\Pages\ListRecords;

class ListStockWarehouseLogs extends ListRecords
{
    protected static string $resource = StockWarehouseLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
