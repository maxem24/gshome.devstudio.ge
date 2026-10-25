<?php

namespace App\Listeners;

use App\Crm\Events\PinTransferred;
use App\Enums\DealStatus;

/** Синхронный: открытые сделки переходят новому держателю в той же транзакции. */
class ReassignDealsOnPinTransferred
{
    public function handle(PinTransferred $event): void
    {
        $event->pin->deals()->where('status', DealStatus::Open)->update([
            'user_id' => $event->to->id,
            'updated_at' => now(),
        ]);
    }
}
