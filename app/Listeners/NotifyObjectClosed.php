<?php

namespace App\Listeners;

use App\Crm\Events\ObjectClosed;
use App\Crm\Pins;
use App\Notifications\ObjectClosed as ObjectClosedNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/** Сообщает остальным ведущим, что объект закрыт. Только после коммита: откат не должен слать уведомлений. */
class NotifyObjectClosed implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly Pins $pins) {}

    public function handle(ObjectClosed $event): void
    {
        $holders = (new EloquentCollection($event->closedPins->all()))->loadMissing('holder')
            ->map(fn ($pin) => $pin->holder)
            ->all();
        // Руководитель закрыл от имени агента: его пина среди закрытых нет, но узнать он должен.
        if ($event->actingHolder !== null) {
            $holders[] = $event->actingHolder;
        }
        $holders = collect($holders)
            ->filter(fn ($holder) => $holder->id !== $event->by->id)
            ->unique('id');

        foreach ($holders as $holder) {
            $holder->notify(new ObjectClosedNotification(
                $event->object,
                $event->reason,
                $this->pins->nameFor($event->by, $holder),
            ));
        }
    }
}
