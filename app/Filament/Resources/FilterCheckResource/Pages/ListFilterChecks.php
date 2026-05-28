<?php

namespace App\Filament\Resources\FilterCheckResource\Pages;

use App\Filament\Resources\FilterCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFilterChecks extends ListRecords
{
    protected static string $resource = FilterCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
