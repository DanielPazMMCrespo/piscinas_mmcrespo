<?php

declare(strict_types=1);

namespace App\Policies;

use App\Constants\UserRole;
use App\Models\DosingContainer;
use App\Models\User;

class DosingContainerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO, UserRole::GESTOR]);
    }

    public function view(User $user, DosingContainer $container): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function update(User $user, DosingContainer $container): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function delete(User $user, DosingContainer $container): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }
}
