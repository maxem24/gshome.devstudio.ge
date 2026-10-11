<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Кто выставил объявление на площадке (ТЗ 4.5, решение 2026-10-10). */
enum SellerCategory: string implements HasLabel
{
    case Owner = 'owner';
    case Agency = 'agency';
    case Agent = 'agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Собственник',
            self::Agency => 'Агентство',
            self::Agent => 'Агент',
        };
    }
}
