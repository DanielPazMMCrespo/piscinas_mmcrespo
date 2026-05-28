<?php

namespace App\Filament\Resources\StockInstallationResource\Pages;

use App\Filament\Resources\StockInstallationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockInstallations extends ListRecords
{
    protected static string $resource = StockInstallationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
