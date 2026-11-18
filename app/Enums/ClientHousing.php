<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Статус клиента: активен — ищет квартиру и попадает в подбор; неактивен — уже не нужно. */
enum ClientHousing: string implements HasColor, HasLabel
{
    case Renting = 'renting';
    case Looking = 'looking';

    public function getColor(): string
    {
        return $this === self::Looking ? 'success' : 'gray';
    }

    public function getLabel(): string
    {
        return __('gshome.crm.clients.housing.'.$this->value);
    }
}
