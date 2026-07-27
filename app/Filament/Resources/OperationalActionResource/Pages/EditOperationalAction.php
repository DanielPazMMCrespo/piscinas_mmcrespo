<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalActionResource\Pages;

use App\Constants\UserRole;
use App\Filament\Resources\OperationalActionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditOperationalAction extends EditRecord
{
    protected static string $resource = OperationalActionResource::class;

    public function mount(): void
    {
        if (! auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            throw new AuthorizationException('Sem acesso a ações operacionais.');
        }

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
