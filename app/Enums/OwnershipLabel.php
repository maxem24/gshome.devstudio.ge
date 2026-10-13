<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OwnershipLabel: string implements HasLabel
{
    case OurEmployee = 'our_employee';
    case LinkedCompany = 'linked_company';
    case GroupCompany = 'group_company';
    case OtherCompany = 'other_company';

    public function getLabel(): string
    {
        return match ($this) {
            self::OurEmployee => __('gshome.ownership.our_employee'),
            self::LinkedCompany => __('gshome.ownership.linked_company'),
            self::GroupCompany => __('gshome.ownership.group_company'),
            self::OtherCompany => __('gshome.ownership.other_company'),
        };
    }
}
