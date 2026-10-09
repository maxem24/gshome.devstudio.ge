<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;

/** Компании: управляет владелец; владелец компании читает свою. Удалять нельзя — только архив. */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::CompanyOwner], true);
    }

    public function view(User $user, Company $company): bool
    {
        return $user->role === Role::Owner
            || ($user->role === Role::CompanyOwner && $user->company_id === $company->id);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, Company $company): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, Company $company): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
