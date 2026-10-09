<?php

namespace App\Support;

use InvalidArgumentException;

final class InvalidPhoneNumber extends InvalidArgumentException
{
    public static function for(string $raw, ?\Throwable $previous = null): self
    {
        return new self("Не грузинский или неверный номер: «{$raw}»", 0, $previous);
    }
}
