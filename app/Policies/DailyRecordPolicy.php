<?php

declare(strict_types=1);

namespace App\Policies;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\User;

class DailyRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(UserRole::all());
    }

    public function view(User $user, DailyRecord $record): bool
    {
        if ($user->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO])) {
            return true;
        }

        // Nadador-Salvador só pode ver os seus próprios registos.
        return $user->hasRole(UserRole::NADADOR_SALVADOR) && $record->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO, UserRole::NADADOR_SALVADOR]);
    }

    public function update(User $user, DailyRecord $record): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function delete(User $user, DailyRecord $record): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }
}
