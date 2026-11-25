<?php

namespace App\Crm\Events;

use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/** Объект закрыт для всех участников. Уведомления рассылает Listeners\NotifyObjectClosed. */
final class ObjectClosed
{
    use Dispatchable;

    /**
     * @param  Collection<int, ListingPin>  $closedPins  пины, закрытые этим вызовом (без пина того, кто закрыл)
     */
    public function __construct(
        public readonly ListingObject $object,
        public readonly User $by,
        public readonly string $reason,
        public readonly Collection $closedPins,
    ) {}
}
