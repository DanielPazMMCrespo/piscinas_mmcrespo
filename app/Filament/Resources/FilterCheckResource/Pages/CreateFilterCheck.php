<?php

namespace App\Filament\Resources\FilterCheckResource\Pages;

use App\Filament\Resources\FilterCheckResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFilterCheck extends CreateRecord
{
    protected static string $resource = FilterCheckResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->requiresConfirmation()
                ->modalHeading('Confirmar limpeza de filtro')
                ->modalDescription('Confirme que os dados estão corretos antes de submeter.')
                ->modalSubmitActionLabel('Confirmar e guardar'),
            $this->getCancelFormAction(),
        ];
    }
}
