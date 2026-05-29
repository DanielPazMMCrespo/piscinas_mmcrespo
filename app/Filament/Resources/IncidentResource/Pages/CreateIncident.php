<?php

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Filament\Resources\IncidentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIncident extends CreateRecord
{
    protected static string $resource = IncidentResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->requiresConfirmation()
                ->modalHeading('Confirmar incidente')
                ->modalDescription('Confirme que a informação do incidente está correta antes de submeter.')
                ->modalSubmitActionLabel('Confirmar e guardar'),
            $this->getCancelFormAction(),
        ];
    }
}
