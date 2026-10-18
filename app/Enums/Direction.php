<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Direction: string implements HasLabel
{
    case Sale = 'sale';
    case Rent = 'rent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sale => __('gshome.direction.sale'),
            self::Rent => __('gshome.direction.rent'),
        };
    }
}
