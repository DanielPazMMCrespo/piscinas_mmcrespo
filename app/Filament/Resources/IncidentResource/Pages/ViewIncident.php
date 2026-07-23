<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Filament\Resources\IncidentResource;
use App\Filament\Widgets\IncidentChatWidget;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewIncident extends ViewRecord
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            IncidentResource::resolverHeaderAction(),
            Actions\EditAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            IncidentChatWidget::class,
        ];
    }
}
