<?php

namespace App\Models;

use App\Access\OrgAccess;
use App\Crm\Archive;
use App\Enums\Direction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Объект в работе: группа дублей (или одно объявление без группы), закреплённая за человеком.
 *
 * @property int $listing_id
 * @property ?int $cluster_id
 * @property string $pin_key
 * @property Direction $direction
 * @property int $user_id
 * @property string $status
 * @property ?string $closed_reason
 */
#[Fillable(['listing_id', 'cluster_id', 'pin_key', 'direction', 'user_id', 'status', 'closed_reason', 'closed_at', 'last_activity_at'])]
class ListingPin extends Model
{
    use SoftDeletes;

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'listing_id' => 'integer',
            'cluster_id' => 'integer',
            'user_id' => 'integer',
            'direction' => Direction::class,
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Закрепления людей, которых смотрящий видит по ступени (OrgAccess::visibleUsers).
     *
     * @param  Builder<ListingPin>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->whereIn('user_id', OrgAccess::visibleUsers($viewer)->select('id'));
        Archive::scope($query, $viewer);
    }
}
