<?php

namespace App\RealEstate\Exceptions;

/** 503 refresh_busy: у сборщиков слишком много карточек в очереди; Retry-After обычно 30 с. */
final class RealEstateRefreshBusy extends RealEstateException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Refresh busy, retry after {$retryAfter}s");
    }

    public function userMessage(): string
    {
        return __('gshome.listings.refresh.busy');
    }
}
