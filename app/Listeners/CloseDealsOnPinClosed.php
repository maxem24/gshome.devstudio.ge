<?php

namespace App\Listeners;

use App\Crm\Events\PinClosed;
use App\Enums\DealStatus;

/**
 * Синхронный: работает в транзакции закрытия пина. Выигранная сделка к этому моменту уже won,
 * поэтому под «открытыми» остаются только чужие сделки пина.
 */
class CloseDealsOnPinClosed
{
    public function handle(PinClosed $event): void
    {
        if (! in_array($event->reason, ['won', 'unavailable'], true)) {
            return;
        }

        $event->pin->deals()->where('status', DealStatus::Open)->update([
            'status' => DealStatus::Unavailable,
            'closed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
