<?php

namespace App\Models;

use App\Enums\ViewingOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Показ объекта клиенту в рамках сделки. */
#[Fillable(['deal_id', 'scheduled_at', 'notes', 'outcome', 'outcome_at'])]
class Viewing extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'outcome' => ViewingOutcome::class,
            'outcome_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
