<?php

namespace App\Filament\Resources\DailyRecordResource\Pages;

use App\Filament\Resources\DailyRecordResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDailyRecord extends CreateRecord
{
    protected static string $resource = DailyRecordResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->requiresConfirmation()
                ->modalHeading('Confirmar registo')
                ->modalDescription('Confirme que os valores introduzidos estão corretos. Depois de submetido, só o administrador pode alterar este registo.')
                ->modalSubmitActionLabel('Confirmar e guardar'),
            $this->getCancelFormAction(),
        ];
    }
}
