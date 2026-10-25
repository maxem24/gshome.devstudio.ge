<?php

namespace App\Models;

use App\Enums\DealStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Activity;

/**
 * Сделка: клиент по закреплённому объекту.
 *
 * @property int $listing_pin_id
 * @property int $client_id
 * @property int $user_id
 * @property string $stage
 * @property DealStatus $status
 * @property ?string $amount
 * @property ?string $currency
 * @property ?string $lost_reason
 */
#[Fillable(['listing_pin_id', 'client_id', 'user_id', 'stage', 'status', 'amount', 'currency', 'lost_reason', 'closed_at'])]
class Deal extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'listing_pin_id' => 'integer',
            'client_id' => 'integer',
            'user_id' => 'integer',
            'status' => DealStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ListingPin, $this> */
    public function pin(): BelongsTo
    {
        return $this->belongsTo(ListingPin::class, 'listing_pin_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<DealStageChange, $this> */
    public function stageChanges(): HasMany
    {
        return $this->hasMany(DealStageChange::class);
    }

    /** @return HasMany<Viewing, $this> */
    public function viewings(): HasMany
    {
        return $this->hasMany(Viewing::class);
    }

    /** @return MorphMany<Activity, $this> записи журнала про сделку */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function isOpen(): bool
    {
        return $this->status === DealStatus::Open;
    }
}
