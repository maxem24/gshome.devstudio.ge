<?php

namespace App\Crm;

use App\Access\OrgAccess;
use App\Crm\Exceptions\CrmForbidden;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Задачи сотрудников. Кому можно ставить — решает OrgAccess::canActFor. */
class Tasks
{
    public function create(User $author, User $assignee, string $body, ?CarbonInterface $due): Task
    {
        if ($assignee->isNot($author) && ! OrgAccess::canActFor($author, $assignee)) {
            throw new CrmForbidden('Cannot assign a task to this user.');
        }

        $task = DB::transaction(function () use ($author, $assignee, $body, $due): Task {
            $task = Task::query()->create([
                'body' => $body,
                'assignee_id' => $assignee->id,
                'author_id' => $author->id,
                'due_at' => $due,
                'status' => 'open',
            ]);
            Journal::record(Journal::TASK_CREATED, $task, [
                'task_id' => $task->id,
                'assignee_id' => $assignee->id,
                'due_at' => $due?->toIso8601String(),
            ], $author);

            return $task;
        });

        if ($assignee->isNot($author)) {
            $assignee->notify(new TaskAssigned($task, $author));
        }

        return $task;
    }

    public function setStatus(Task $task, string $status, User $by): void
    {
        if (! in_array($status, config('crm.task_statuses'), true)) {
            throw new InvalidArgumentException("Unknown task status: {$status}.");
        }

        // Задачу ушедшего в архив или заблокированного разбирает руководитель: canSee без статуса.
        $allowed = $by->getKey() === $task->assignee_id
            || $by->getKey() === $task->author_id
            || OrgAccess::canSee($by, $task->assignee);
        if (! $allowed) {
            throw new CrmForbidden('Cannot change this task.');
        }

        DB::transaction(function () use ($task, $status, $by): void {
            $old = $task->status;
            $task->update(['status' => $status]);
            Journal::record(Journal::TASK_STATUS_CHANGED, $task, [
                'task_id' => $task->id,
                'old' => $old,
                'new' => $status,
            ], $by);
        });
    }
}
