<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Какой дом ищет. Несколько галочек сразу. */
enum ClientHome: string implements HasLabel
{
    case NewBuild = 'new_build';
    case InBlock = 'in_block';
    case Renovated = 'renovated';
    case OldBuilding = 'old_building';
    case OldRenovation = 'old_renovation';
    case NoRenovation = 'no_renovation';
    case Furnished = 'furnished';
    case Unfurnished = 'unfurnished';
    case Pets = 'pets';

    public function getLabel(): string
    {
        return __('gshome.crm.clients.home.'.$this->value);
    }
}
