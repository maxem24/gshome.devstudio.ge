<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ViewingOutcome: string implements HasLabel
{
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case FollowUp = 'follow_up';
    case Unreachable = 'unreachable';
    case Negotiating = 'negotiating';

    public function getLabel(): string
    {
        return __('gshome.crm.viewing_outcome.'.$this->value);
    }
}
