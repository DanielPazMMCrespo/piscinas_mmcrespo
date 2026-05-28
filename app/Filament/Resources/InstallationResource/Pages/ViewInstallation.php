<?php

namespace App\Filament\Resources\InstallationResource\Pages;

use App\Filament\Resources\InstallationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewInstallation extends ViewRecord
{
    protected static string $resource = InstallationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
