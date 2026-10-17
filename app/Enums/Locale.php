<?php

namespace App\Enums;

/** Язык интерфейса. Код совпадает с `lang` API Real Estate: ru, en, ka. */
enum Locale: string
{
    case Russian = 'ru';
    case English = 'en';
    case Georgian = 'ka';

    /** Название на самом языке: в меню его узнают, не переводя. */
    public function nativeLabel(): string
    {
        return match ($this) {
            self::Russian => 'Русский',
            self::English => 'English',
            self::Georgian => 'ქართული',
        };
    }
}
