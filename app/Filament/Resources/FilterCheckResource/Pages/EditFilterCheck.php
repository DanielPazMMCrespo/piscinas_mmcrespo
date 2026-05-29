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

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->requiresConfirmation()
                ->modalHeading('Confirmar alteração')
                ->modalDescription('Confirme que pretende guardar as alterações.')
                ->modalSubmitActionLabel('Confirmar e guardar'),
            $this->getCancelFormAction(),
        ];
    }
}
