<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalActionResource\Pages;

use App\Filament\Resources\OperationalActionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;

class ViewOperationalAction extends ViewRecord
{
    protected static string $resource = OperationalActionResource::class;

    public function mount(string|int $record): void
    {
        // Regra 11 do CLAUDE.md: a autorizacao vem do Resource, nunca de uma
        // lista de papeis reescrita aqui. O canAccess() foi alargado ao
        // nadador-salvador com permissao de analise e esta pagina ficou para
        // tras: o NS criava a acao pontual e depois levava 403 a tentar ve-la.
        if (! OperationalActionResource::canAccess()) {
            throw new AuthorizationException('Sem acesso a ações operacionais.');
        }

        parent::mount($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
