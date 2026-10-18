<?php

namespace App\RealEstate\Exceptions;

final class RealEstateUnavailable extends RealEstateException
{
    public function userMessage(): string
    {
        return __('gshome.errors.unavailable');
    }
}
