<?php

namespace App\RealEstate\Exceptions;

final class RealEstateUnavailable extends RealEstateException
{
    public function userMessage(): string
    {
        return 'Real Estate недоступен, попробуйте позже.';
    }
}
