<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DealStatus: string implements HasLabel
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
    case Unavailable = 'unavailable';

    public function getLabel(): string
    {
        return __('gshome.crm.deal_status.'.$this->value);
    }
}
