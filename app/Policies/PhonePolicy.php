<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Phone;
use App\Models\User;

class PhonePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::CompanyOwner], true);
    }

    public function view(User $user, Phone $phone): bool
    {
        return $user->role === Role::Owner
            || ($user->role === Role::CompanyOwner && $user->company_id === $phone->company_id);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, Phone $phone): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, Phone $phone): bool
    {
        return $user->role === Role::Owner;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
