<?php

declare(strict_types=1);

namespace App\Policies;

use App\Constants\UserRole;
use App\Models\StockInstallation;
use App\Models\User;

class StockInstallationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(UserRole::all());
    }

    public function view(User $user, StockInstallation $stock): bool
    {
        return $user->hasAnyRole(UserRole::all());
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]);
    }

    public function update(User $user, StockInstallation $stock): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]);
    }

    public function delete(User $user, StockInstallation $stock): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }
}
