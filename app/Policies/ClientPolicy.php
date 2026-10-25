<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;

/**
 * Клиенты: база общая, видимость — через Client::visibleTo (архив). Редактирования нет;
 * в архив — владелец и создатель, обратно — владелец.
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Client $client): bool
    {
        return false;
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function archive(User $user, Client $client): bool
    {
        return $user->role === Role::Owner || $client->created_by === $user->id;
    }

    public function restore(User $user, Client $client): bool
    {
        return $user->role === Role::Owner;
    }
}
