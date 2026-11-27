<?php

namespace App\Models;

use App\Access\OrgAccess;
use App\Crm\Archive;
use App\Enums\CommissionKind;
use App\Enums\Direction;
use App\Enums\UnavailableReason;
use Carbon\Carbon;
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
 * @property ?int $object_id
 * @property ?UnavailableReason $unavailable_reason
 * @property array<string, mixed> $preferences кому собственник готов сдать (LandlordPreferences)
 * @property ?Carbon $agreed_at
 * @property ?int $agreed_by
 * @property ?string $agreed_price
 * @property ?string $agreed_currency
 * @property ?string $owner_price
 * @property ?CommissionKind $commission_kind
 * @property ?int $commission_percent
 * @property ?int $commission_months
 * @property ?string $commission_amount
 * @property ?string $commission_note
 * @property ?string $agreement_note
 */
#[Fillable(['listing_id', 'cluster_id', 'pin_key', 'direction', 'user_id', 'status', 'closed_reason', 'object_id', 'unavailable_reason', 'closed_at', 'last_activity_at', 'preferences', 'agreed_at', 'agreed_by', 'agreed_price', 'agreed_currency', 'owner_price', 'commission_kind', 'commission_percent', 'commission_months', 'commission_amount', 'commission_note', 'available_from', 'min_term_months', 'myhome_id', 'ss_id', 'agreement_note'])]
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
            'object_id' => 'integer',
            'unavailable_reason' => UnavailableReason::class,
            'commission_kind' => CommissionKind::class,
            'agreed_at' => 'datetime',
            'available_from' => 'date',
            'agreed_price' => 'decimal:2',
            'owner_price' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<ListingObject, $this> */
    public function object(): BelongsTo
    {
        return $this->belongsTo(ListingObject::class, 'object_id');
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
