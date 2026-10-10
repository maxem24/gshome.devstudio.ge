<?php

namespace App\RealEstate\Exceptions;

final class RealEstateAccessDenied extends RealEstateException
{
    public function userMessage(): string
    {
        return 'Доступ к Real Estate закрыт — ключ истёк или отозван. Сообщите администратору.';
    }
}
