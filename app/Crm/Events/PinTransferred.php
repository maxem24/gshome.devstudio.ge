<?php

namespace App\Crm\Events;

use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final class PinTransferred
{
    use Dispatchable;

    public function __construct(public readonly ListingPin $pin, public readonly User $from, public readonly User $to) {}
}
