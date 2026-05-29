<?php

namespace App\Filament\Resources\DailyRecordResource\Pages;

use App\Filament\Resources\DailyRecordResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDailyRecord extends EditRecord
{
    protected static string $resource = DailyRecordResource::class;

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
                ->modalDescription('Confirme que pretende guardar as alterações a este registo.')
                ->modalSubmitActionLabel('Confirmar e guardar'),
            $this->getCancelFormAction(),
        ];
    }
}
