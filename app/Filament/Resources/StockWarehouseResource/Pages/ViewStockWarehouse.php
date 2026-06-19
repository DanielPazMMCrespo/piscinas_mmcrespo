<?php declare(strict_types=1);
namespace App\Filament\Resources\StockWarehouseResource\Pages;

namespace App\Filament\Resources\StockWarehouseResource\Pages;

use App\Filament\Resources\StockWarehouseResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewStockWarehouse extends ViewRecord
{
    protected static string $resource = StockWarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
