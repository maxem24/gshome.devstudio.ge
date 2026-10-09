<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Активен — работает. Заблокирован — временно не входит, всё остальное за ним
 * (номера, сотрудники, место в команде, имя на объявлениях). В архиве — ушёл
 * совсем: номера и сотрудники переданы, имя на объявлениях скрыто.
 */
enum UserStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Активен',
            self::Blocked => 'Заблокирован',
            self::Archived => 'В архиве',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Blocked => 'warning',
            self::Archived => 'gray',
        };
    }
}
