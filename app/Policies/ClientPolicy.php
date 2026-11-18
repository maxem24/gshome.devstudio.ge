<?php

namespace App\Policies;

use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\User;

/**
 * Клиенты: база общая, видимость — через Client::visibleTo (архив). Правка — см. update();
 * в архив — владелец и создатель (без открытых сделок), обратно — владелец.
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

    /** Править — владелец, тот, кто завёл, и тот, у кого есть сделка с клиентом. Номер правкой не меняется. */
    public function update(User $user, Client $client): bool
    {
        return $user->role === Role::Owner
            || $client->created_by === $user->id
            || $client->deals()->where('user_id', $user->id)->exists();
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Кнопка «В архив» показывается тому, кто вправе: открытые сделки отказ с пояснением даёт само действие. */
    public function offerArchive(User $user, Client $client): bool
    {
        return $user->role === Role::Owner || $client->created_by === $user->id;
    }

    /** С открытыми сделками клиента в архив не убирают. */
    public function archive(User $user, Client $client): bool
    {
        return $this->offerArchive($user, $client) && ! $client->deals()->where('status', DealStatus::Open)->exists();
    }

    public function restore(User $user, Client $client): bool
    {
        return $user->role === Role::Owner;
    }
}
