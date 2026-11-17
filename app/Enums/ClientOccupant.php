<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Кто будет жить: состав, которым снимают квартиру, а не отдельные роли. */
enum ClientOccupant: string implements HasLabel
{
    case OnePerson = 'one_person';
    case Couple = 'couple';
    case SmallFamily = 'small_family';
    case LargeFamily = 'large_family';
    case MotherChild = 'mother_child';
    case FatherChild = 'father_child';
    case Brothers = 'brothers';
    case Sisters = 'sisters';
    case BrotherAndSister = 'brother_and_sister';
    case TwoFemaleStudents = 'two_female_students';
    case TwoMaleStudents = 'two_male_students';

    public function getLabel(): string
    {
        return __('gshome.crm.clients.occupant.'.$this->value);
    }
}
