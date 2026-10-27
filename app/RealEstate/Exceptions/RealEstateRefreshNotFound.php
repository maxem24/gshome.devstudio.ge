<?php

namespace App\RealEstate\Exceptions;

/** 404 на GET /listing-refreshes/{id}: актуализация чужая или уже стёрта на стороне Real Estate (хранится 7 дней). */
final class RealEstateRefreshNotFound extends RealEstateBadRequest
{
    public function userMessage(): string
    {
        return __('gshome.listings.refresh.lost');
    }
}
