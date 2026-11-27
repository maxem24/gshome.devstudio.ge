<?php

namespace App\Crm\Exceptions;

use App\Models\ListingPin;
use RuntimeException;

/** Вы уже ведёте этот объект: в нём есть ваш активный пин. */
final class PinTaken extends RuntimeException
{
    public function __construct(public readonly ?ListingPin $pin)
    {
        parent::__construct('You already work on this object.');
    }
}
