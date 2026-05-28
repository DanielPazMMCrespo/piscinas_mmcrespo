<?php

namespace App\Filament\Resources\FilterCheckResource\Pages;

use App\Filament\Resources\FilterCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFilterCheck extends EditRecord
{
    protected static string $resource = FilterCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
