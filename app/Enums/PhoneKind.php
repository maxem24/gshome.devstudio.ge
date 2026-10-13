<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Подпись номера; на привязку объявлений не влияет. */
enum PhoneKind: string implements HasLabel
{
    case Personal = 'personal';
    case Office = 'office';

    public function getLabel(): string
    {
        return match ($this) {
            self::Personal => __('gshome.phone_kind.personal'),
            self::Office => __('gshome.phone_kind.office'),
        };
    }
}
