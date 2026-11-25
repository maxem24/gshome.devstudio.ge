<?php

namespace App\Listeners;

use App\Crm\Events\ObjectClosed;
use App\Crm\Pins;
use App\Notifications\ObjectClosed as ObjectClosedNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Сообщает остальным ведущим, что объект закрыт. Только после коммита: откат не должен слать уведомлений. */
class NotifyObjectClosed implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly Pins $pins) {}

    public function handle(ObjectClosed $event): void
    {
        $holders = $event->closedPins
            ->map(fn ($pin) => $pin->holder)
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
