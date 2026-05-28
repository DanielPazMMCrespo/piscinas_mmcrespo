<?php

namespace App\Filament\Resources\FilterCheckResource\Pages;

use App\Filament\Resources\FilterCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFilterCheck extends ViewRecord
{
    protected static string $resource = FilterCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
