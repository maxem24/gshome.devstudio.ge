<?php

namespace App\RealEstate;

final readonly class ListingPage
{
    /** @param  list<Listing>  $listings */
    public function __construct(
        public array $listings,
        public ?string $nextCursor,
        public ?string $prevCursor,
    ) {}
}
