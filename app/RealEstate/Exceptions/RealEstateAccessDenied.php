<?php

namespace App\RealEstate\Exceptions;

final class RealEstateAccessDenied extends RealEstateException
{
    public function userMessage(): string
    {
        return __('gshome.errors.access_denied');
    }
}
