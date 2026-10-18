<?php

namespace App\RealEstate\Exceptions;

final class RealEstateRateLimited extends RealEstateException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Rate limited, retry after {$retryAfter}s");
    }

    public function userMessage(): string
    {
        return __('gshome.errors.rate_limited', ['seconds' => $this->retryAfter]);
    }
}
