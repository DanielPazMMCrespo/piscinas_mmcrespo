<?php declare(strict_types=1);
namespace App\Filament\Resources\DosingContainerResource\Pages;

use App\Filament\Resources\DosingContainerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDosingContainers extends ListRecords
{
    protected static string $resource = DosingContainerResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
