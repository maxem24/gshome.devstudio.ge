<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Живёт ли клиент в аренде или ищет квартиру и ждёт звонка. */
enum ClientHousing: string implements HasLabel
{
    case Renting = 'renting';
    case Looking = 'looking';

    public function getLabel(): string
    {
        return __('gshome.crm.clients.housing.'.$this->value);
    }
}
