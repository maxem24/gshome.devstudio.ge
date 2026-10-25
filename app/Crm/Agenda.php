<?php

namespace App\Crm;

use App\Enums\DealStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Viewing;
use Illuminate\Support\Collection;

/** «Мои задачи»: открытые задачи и показы без итога, по времени; без срока — в конце. */
class Agenda
{
    /** @return Collection<int, AgendaItem> */
    public function for(User $user): Collection
    {
        $tasks = Task::query()
            ->where('assignee_id', $user->id)
            ->where('status', 'open')
            ->get()
            ->map(fn (Task $t) => new AgendaItem('task', $t->id, $t->body, $t->due_at, $t->due_at?->isPast() ?? false));

        $viewings = Viewing::query()
            ->whereNull('outcome')
            ->whereHas('deal', fn ($q) => $q
                ->where('status', DealStatus::Open->value)
                ->where('user_id', $user->id))
            ->get()
            ->map(fn (Viewing $v) => new AgendaItem(
                'viewing',
                $v->id,
                trim(__('gshome.crm.tasks.viewing', [], $user->locale->value).' '.$v->notes),
                $v->scheduled_at,
                $v->scheduled_at->isPast(),
            ));

        return $tasks->concat($viewings)
            ->sortBy(fn (AgendaItem $i) => $i->at?->getTimestamp() ?? PHP_INT_MAX)
            ->values();
    }
}
