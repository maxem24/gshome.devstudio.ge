<?php

namespace App\Listeners;

use App\Crm\Deals;
use App\Crm\Events\PinTransferred;
use App\Enums\DealStatus;

/** Синхронный: открытые сделки переходят новому держателю в той же транзакции. */
class ReassignDealsOnPinTransferred
{
    public function __construct(private readonly Deals $deals) {}

    public function handle(PinTransferred $event): void
    {
        // Новый держатель, бывший партнёром, перестаёт им быть — иначе CHECK deals_partner_not_agent.
        $event->pin->deals()->where('status', DealStatus::Open)
            ->where('partner_user_id', $event->to->id)->lockForUpdate()->get()
            ->each(fn ($deal) => $this->deals->removePartner($deal, 'became_holder', $event->by));

        $event->pin->deals()->where('status', DealStatus::Open)->update([
            'user_id' => $event->to->id,
            'updated_at' => now(),
        ]);
    }
}
