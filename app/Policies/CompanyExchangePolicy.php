<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CompanyExchange;
use App\Models\User;

/** Пары обмена — только владелец группы. */
class CompanyExchangePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function view(User $user, CompanyExchange $pair): bool
    {
        return $user->role === Role::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, CompanyExchange $pair): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, CompanyExchange $pair): bool
    {
        return $user->role === Role::Owner;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
