<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Constants\UserRole;
use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Password inicial aleatória e nunca comunicada: ninguém consegue entrar
        // com ela, só com o link de redefinição enviado em afterCreate().
        // must_change_password fica como segunda barreira caso o link expire
        // e alguém tenha de usar "Forçar Pass/PIN" manualmente.
        $data['password'] = Hash::make(Str::random(32));
        $data['must_change_password'] = true;

        return $data;
    }

    protected function afterCreate(): void
    {
        if (auth()->user()?->hasRole(UserRole::GESTOR)) {
            $this->record->assignRole(UserRole::NADADOR_SALVADOR);
        }

        Password::broker()->sendResetLink(['email' => $this->record->email]);
    }
}
