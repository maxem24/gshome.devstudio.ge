<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Переход сделки между этапами. Только добавляется. */
#[Fillable(['deal_id', 'from_stage', 'to_stage', 'changed_by', 'changed_at'])]
class DealStageChange extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
