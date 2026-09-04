<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalActionResource\Pages;

use App\Filament\Resources\OperationalActionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditOperationalAction extends EditRecord
{
    protected static string $resource = OperationalActionResource::class;

    /**
     * Regra 11 do CLAUDE.md: a autorizacao vem do Resource. O canEdit() dele ja
     * tem a regra real (admin sempre; tecnico so o proprio registo e dentro de
     * 24h), que a lista de papeis que aqui estava nao exprimia.
     *
     * Vai no authorizeAccess() e nao no mount(): a EditRecord chama-o DEPOIS de
     * resolver o registo. No mount(), $this->getRecord() ainda nao existe.
     */
    protected function authorizeAccess(): void
    {
        if (! OperationalActionResource::canEdit($this->getRecord())) {
            throw new AuthorizationException('Sem acesso a ações operacionais.');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
