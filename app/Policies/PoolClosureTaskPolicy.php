<?php

declare(strict_types=1);

namespace App\Policies;

use App\Constants\UserRole;
use App\Models\PoolClosureTask;
use App\Models\User;

class PoolClosureTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(UserRole::all());
    }

    public function view(User $user, PoolClosureTask $tarefa): bool
    {
        return $user->hasAnyRole(UserRole::all());
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    public function update(User $user, PoolClosureTask $tarefa): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    /**
     * Apagar uma tarefa de checklist que justifica o cumprimento legal — só admin.
     */
    public function delete(User $user, PoolClosureTask $tarefa): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }
}
