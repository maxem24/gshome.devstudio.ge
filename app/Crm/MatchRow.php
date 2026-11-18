<?php

namespace App\Crm;

use App\Models\Client;
use App\Models\ListingPin;

/** Одна пара из подбора: вторая сторона, процент совпадения и что не сходится (уже переведено). */
final readonly class MatchRow
{
    /** @param  list<string>  $mismatches */
    public function __construct(
        public Client|ListingPin $other,
        public ?int $score,
        public bool $enough,
        public array $mismatches,
    ) {}
}
