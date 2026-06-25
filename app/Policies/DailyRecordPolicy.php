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

        return $user->hasRole(UserRole::NADADOR_SALVADOR)
            && $user->piscinas()->where('pools.id', $record->pool_id)->exists();
    }

    public function create(User $user): bool
    {
        if ($user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            return true;
        }
        if ($user->hasRole(UserRole::NADADOR_SALVADOR)) {
            return $user->piscinas()->exists();
        }
        return false;
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
