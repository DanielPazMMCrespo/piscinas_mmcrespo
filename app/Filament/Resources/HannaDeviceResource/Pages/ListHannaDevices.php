<?php declare(strict_types=1);
namespace App\Filament\Resources\HannaDeviceResource\Pages;

namespace App\Filament\Resources\HannaDeviceResource\Pages;

use App\Filament\Resources\HannaDeviceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHannaDevices extends ListRecords
{
    protected static string $resource = HannaDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
