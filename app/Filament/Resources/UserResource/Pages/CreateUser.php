<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Constants\UserRole;
use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = Hash::make('password');
        $data['must_change_password'] = true;

        return $data;
    }

    protected function afterCreate(): void
    {
        if (auth()->user()?->hasRole(UserRole::GESTOR)) {
            $this->record->assignRole(UserRole::NADADOR_SALVADOR);
        }
    }
}
