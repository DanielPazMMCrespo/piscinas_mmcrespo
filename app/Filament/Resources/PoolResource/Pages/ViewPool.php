<?php

namespace App\Filament\Resources\PoolResource\Pages;

use App\Filament\Resources\PoolResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPool extends ViewRecord
{
    protected static string $resource = PoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
