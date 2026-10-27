<?php

namespace App\RealEstate\Exceptions;

/** 422 filters_required: ни один фильтр не сужает выдачу ни одного сайта — человеку нужно уточнить поиск. */
final class RealEstateFiltersRequired extends RealEstateException
{
    public function userMessage(): string
    {
        return __('gshome.search.filters_required');
    }
}
