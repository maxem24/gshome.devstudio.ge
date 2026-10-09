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
            self::OurEmployee => 'Наш сотрудник',
            self::LinkedCompany => 'Сотрудник связанной компании',
            self::GroupCompany => 'Одна из наших компаний',
            self::OtherCompany => 'Другая компания',
        };
    }
}
