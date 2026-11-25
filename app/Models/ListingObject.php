<?php

namespace App\Models;

use App\Enums\Direction;
use Database\Factories\ListingObjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Объект: общая карточка группы дублей, в которой работают несколько агентов (участия — ListingPin).
 *
 * @property int $id
 * @property string $object_key «c:{cluster_id}» или «l:{listing_id}»
 * @property int $listing_id
 * @property ?int $cluster_id
 * @property Direction $direction
 * @property string $status
 * @property ?string $closed_reason won|rented_elsewhere|fake|abandoned|merged_into
 * @property ?Carbon $closed_at
 * @property ?int $closed_by
 * @property ?int $won_deal_id
 * @property ?int $merged_into_id
 * @property ?string $price_usd снимок объявления
 * @property ?string $price_gel
 * @property ?int $rooms
 * @property ?int $subdistrict_id
 * @property ?string $title
 * @property ?Carbon $snapshot_at
 */
#[Fillable(['object_key', 'listing_id', 'cluster_id', 'direction', 'status', 'closed_reason', 'closed_at', 'closed_by', 'won_deal_id', 'merged_into_id', 'price_usd', 'price_gel', 'rooms', 'subdistrict_id', 'title', 'snapshot_at'])]
class ListingObject extends Model
{
    /** @use HasFactory<ListingObjectFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'listing_id' => 'integer',
            'cluster_id' => 'integer',
            'direction' => Direction::class,
            'closed_at' => 'datetime',
            'closed_by' => 'integer',
            'won_deal_id' => 'integer',
            'merged_into_id' => 'integer',
            'price_usd' => 'decimal:2',
            'price_gel' => 'decimal:2',
            'rooms' => 'integer',
            'subdistrict_id' => 'integer',
            'snapshot_at' => 'datetime',
        ];
    }

    /** @return HasMany<ListingPin, $this> */
    public function pins(): HasMany
    {
        return $this->hasMany(ListingPin::class, 'object_id');
    }

    /** @return HasMany<ListingPin, $this> */
    public function activePins(): HasMany
    {
        return $this->pins()->where('status', ListingPin::ACTIVE);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
