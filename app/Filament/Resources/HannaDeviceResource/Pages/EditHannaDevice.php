<?php declare(strict_types=1);
namespace App\Filament\Resources\HannaDeviceResource\Pages;


use App\Filament\Resources\HannaDeviceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHannaDevice extends EditRecord
{
    protected static string $resource = HannaDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
