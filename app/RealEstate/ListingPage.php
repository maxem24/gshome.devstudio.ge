<?php

namespace App\RealEstate;

final readonly class ListingPage
{
    /**
     * @param  list<Listing>  $listings
     * @param  list<int>  $missingIds  при фильтре `ids`: снятые и несуществующие (meta.missing_ids)
     */
    public function __construct(
        public array $listings,
        public ?string $nextCursor,
        public ?string $prevCursor,
        public array $missingIds = [],
    ) {}
}
