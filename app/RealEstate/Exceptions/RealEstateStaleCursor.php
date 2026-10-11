<?php

namespace App\RealEstate\Exceptions;

/** 422 только по полю cursor: курсор из старого адреса или с чужой сортировкой. Не баг клиента — экран начинает с первой страницы. */
final class RealEstateStaleCursor extends RealEstateException
{
    public function userMessage(): string
    {
        return 'Страница списка устарела, показана первая.';
    }
}
