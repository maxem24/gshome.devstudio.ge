<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Почему агент отметил объект «недоступен». */
enum UnavailableReason: string implements HasLabel
{
    case RentedElsewhere = 'rented_elsewhere';
    case Fake = 'fake';
    case OwnerRefused = 'owner_refused';
    case Other = 'other';

    /** Причина закрывает объект для всех агентов, а не только для этого. */
    public function closesObject(): bool
    {
        return match ($this) {
            self::RentedElsewhere, self::Fake => true,
            self::OwnerRefused, self::Other => false,
        };
    }

    public function getLabel(): string
    {
        return __('gshome.unavailable_reason.'.$this->value);
    }
}
