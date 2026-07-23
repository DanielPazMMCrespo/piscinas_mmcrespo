<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockInstallationLogResource\Pages;

use App\Filament\Resources\StockInstallationLogResource;
use Filament\Resources\Pages\ListRecords;

class ListStockInstallationLogs extends ListRecords
{
    protected static string $resource = StockInstallationLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
