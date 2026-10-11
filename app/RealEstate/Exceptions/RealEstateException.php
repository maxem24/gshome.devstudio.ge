<?php

namespace App\RealEstate\Exceptions;

use RuntimeException;

/** Ошибка обращения к API Real Estate; userMessage() — что показать человеку. */
abstract class RealEstateException extends RuntimeException
{
    abstract public function userMessage(): string;
}
