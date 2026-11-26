<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Как посчитана комиссия в договоре с собственником. */
enum CommissionKind: string implements HasLabel
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case PercentMonths = 'percent_months';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('gshome.crm.pins.commission_kind.'.$this->value);
    }
}
