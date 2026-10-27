<?php

namespace App\RealEstate\Exceptions;

/** 422 validation_failed на POST /search-jobs: сайты не приняли фильтры (например, район без соответствия); это ответ, а не баг. */
final class RealEstateSearchRejected extends RealEstateException
{
    /** @param  list<string>  $fields  имена полей из error.fields */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return array_intersect($this->fields, ['district_id', 'subdistrict_id']) !== []
            ? __('gshome.search.rejected_district')
            : __('gshome.search.rejected');
    }
}
