<?php

namespace App\RealEstate\Exceptions;

/** API принимает не больше 500 номеров в phones/exclude_phones (422) — фильтр недоступен. */
final class TooManyPhones extends RealEstateException
{
    public function userMessage(): string
    {
        return 'Фильтр по принадлежности недоступен: в группе больше 500 номеров.';
    }
}
