<?php declare(strict_types=1);
namespace App\Filament\Resources\OperationalActionResource\Pages;

use App\Filament\Resources\OperationalActionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOperationalActions extends ListRecords
{
    protected static string $resource = OperationalActionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
