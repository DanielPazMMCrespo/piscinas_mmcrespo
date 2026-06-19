<?php declare(strict_types=1);
namespace App\Filament\Resources\StockInstallationResource\Pages;

namespace App\Filament\Resources\StockInstallationResource\Pages;

use App\Filament\Resources\StockInstallationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStockInstallation extends EditRecord
{
    protected static string $resource = StockInstallationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
