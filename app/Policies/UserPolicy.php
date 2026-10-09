<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/** Люди: управляет владелец; владелец компании читает свою. Удалять нельзя — только увольнение. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::CompanyOwner], true);
    }

    public function view(User $user, User $model): bool
    {
        return $user->role === Role::Owner
            || ($user->role === Role::CompanyOwner && $user->company_id === $model->company_id);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, User $model): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
