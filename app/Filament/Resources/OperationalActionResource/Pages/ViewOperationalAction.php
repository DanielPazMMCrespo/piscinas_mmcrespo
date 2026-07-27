<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalActionResource\Pages;

use App\Constants\UserRole;
use App\Filament\Resources\OperationalActionResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;

class ViewOperationalAction extends ViewRecord
{
    protected static string $resource = OperationalActionResource::class;

    public function mount(): void
    {
        if (! auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            throw new AuthorizationException('Sem acesso a ações operacionais.');
        }

        parent::mount();
    }
}
