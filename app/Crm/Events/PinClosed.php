<?php

namespace App\Crm\Events;

use App\Models\ListingPin;
use Illuminate\Foundation\Events\Dispatchable;

final class PinClosed
{
    use Dispatchable;

    /** @param  'won'|'unavailable'|'released'  $reason */
    public function __construct(public readonly ListingPin $pin, public readonly string $reason) {}
}
