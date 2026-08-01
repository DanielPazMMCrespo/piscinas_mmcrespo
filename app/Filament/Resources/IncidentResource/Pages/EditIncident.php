<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\Pages;

use App\Filament\Resources\IncidentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIncident extends EditRecord
{
    protected static string $resource = IncidentResource::class;

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
            // requiresConfirmation() aqui nunca aparecia (a ação usa submit(), que
            // ignora modais) — removido em vez de dar a ideia de haver confirmação.
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        ];
    }
}
