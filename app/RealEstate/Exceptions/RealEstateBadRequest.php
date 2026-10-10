<?php

namespace App\RealEstate\Exceptions;

/** 422 — ошибка в запросе GS Home (баг клиента), человеку — общий текст. */
final class RealEstateBadRequest extends RealEstateException
{
    public function userMessage(): string
    {
        return 'Не удалось загрузить объявления. Ошибка записана в журнал.';
    }
}
