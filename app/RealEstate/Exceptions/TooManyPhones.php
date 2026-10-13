<?php

namespace App\RealEstate\Exceptions;

/** API принимает не больше 500 номеров в phones/exclude_phones (422) — фильтр недоступен. */
final class TooManyPhones extends RealEstateException
{
    public function userMessage(): string
    {
        return __('gshome.errors.too_many_phones');
    }
}
