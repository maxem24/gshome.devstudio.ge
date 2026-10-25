<?php

namespace App\Policies;

use App\Access\OrgAccess;
use App\Enums\Role;
use App\Models\Task;
use App\Models\User;

/**
 * Задачи: видит тот, кто видит исполнителя по ступени (OrgAccess). Правки текста нет, удаления нет:
 * из интерфейса задачу ставят, меняют ей статус и убирают в архив.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Task $task): bool
    {
        return OrgAccess::canSee($user, $task->assignee);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Task $task): bool
    {
        return false;
    }

    public function delete(User $user, Task $task): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Статус: исполнитель, автор или руководитель исполнителя — то же правило, что в Tasks::setStatus. */
    public function changeStatus(User $user, Task $task): bool
    {
        return ! $task->trashed()
            && ($user->getKey() === $task->assignee_id
                || $user->getKey() === $task->author_id
                || OrgAccess::canSee($user, $task->assignee));
    }

    /** В архив: автор или руководитель исполнителя (исполнитель-агент свою задачу не прячет). */
    public function archive(User $user, Task $task): bool
    {
        return ! $task->trashed()
            && ($user->getKey() === $task->author_id || ($user->role !== Role::Agent && OrgAccess::canSee($user, $task->assignee)));
    }
}
