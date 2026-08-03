<?php

declare(strict_types=1);

namespace App\Policies;

use App\Constants\UserRole;
use App\Models\PoolClosure;
use App\Models\User;

class PoolClosurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(UserRole::all());
    }

    public function view(User $user, PoolClosure $encerramento): bool
    {
        return $user->hasAnyRole(UserRole::all());
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    public function update(User $user, PoolClosure $encerramento): bool
    {
        return $user->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    /**
     * Apagar um encerramento reescreve o histórico que justifica os dias sem
     * registos no livro sanitário (CN 14/DA) — só admin.
     */
    public function delete(User $user, PoolClosure $encerramento): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }
}
