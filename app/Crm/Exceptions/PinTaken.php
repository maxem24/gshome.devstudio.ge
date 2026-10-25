<?php

namespace App\Crm\Exceptions;

use App\Models\ListingPin;
use RuntimeException;

/** Группа дублей уже в работе у другого человека. */
final class PinTaken extends RuntimeException
{
    public function __construct(public readonly ?ListingPin $pin)
    {
        parent::__construct('Listing is already in work.');
    }
}
