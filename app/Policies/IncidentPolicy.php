<?php

declare(strict_types=1);

namespace App\Policies;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->hasAnyRole(UserRole::all())) {
            return false;
        }

        return $user->podeVer(NSPermission::INCIDENTES);
    }

    public function view(User $user, Incident $incident): bool
    {
        if ($user->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO])) {
            return true;
        }

        // Nadador-Salvador só pode ver os incidentes que registou.
        return $user->hasRole(UserRole::NADADOR_SALVADOR)
            && $user->podeVer(NSPermission::INCIDENTES)
            && $incident->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(UserRole::all()) && $user->podeVer(NSPermission::INCIDENTES);
    }

    public function update(User $user, Incident $incident): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function delete(User $user, Incident $incident): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }
}
