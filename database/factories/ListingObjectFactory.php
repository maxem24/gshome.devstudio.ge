<?php

namespace Database\Factories;

use App\Enums\Direction;
use App\Models\ListingObject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingObject>
 */
class ListingObjectFactory extends Factory
{
    public function definition(): array
    {
        $listingId = fake()->unique()->numberBetween(1, 9_000_000);

        return [
            'object_key' => 'l:'.$listingId,
            'listing_id' => $listingId,
            'direction' => Direction::Rent,
            'status' => ListingObject::OPEN,
        ];
    }

    public function closed(string $reason = 'abandoned'): static
    {
        return $this->state(['status' => ListingObject::CLOSED, 'closed_reason' => $reason, 'closed_at' => now()]);
    }
}
