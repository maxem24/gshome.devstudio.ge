<?php

namespace App\RealEstate\Exceptions;

/** 422 — ошибка в запросе GS Home (баг клиента), человеку — общий текст. */
class RealEstateBadRequest extends RealEstateException
{
    public function userMessage(): string
    {
        return __('gshome.errors.bad_request');
    }
}
