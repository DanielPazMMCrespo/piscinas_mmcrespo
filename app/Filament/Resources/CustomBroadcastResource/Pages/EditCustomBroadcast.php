<?php declare(strict_types=1);
namespace App\Filament\Resources\CustomBroadcastResource\Pages;

use App\Filament\Resources\CustomBroadcastResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomBroadcast extends EditRecord
{
    protected static string $resource = CustomBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
