<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/** Переключатель видимости сотрудников между двумя компаниями; действует в обе стороны. */
#[Fillable(['company_a_id', 'company_b_id', 'enabled'])]
class CompanyExchange extends Model
{
    protected static function booted(): void
    {
        static::saving(function (CompanyExchange $pair): void {
            if ($pair->company_a_id === $pair->company_b_id) {
                throw new InvalidArgumentException('Пара обмена — две разные компании.');
            }

            if ($pair->company_a_id > $pair->company_b_id) {
                [$pair->company_a_id, $pair->company_b_id] = [$pair->company_b_id, $pair->company_a_id];
            }
        });
    }

    protected function casts(): array
    {
        return [
            'company_a_id' => 'integer',
            'company_b_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function companyA(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_a_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function companyB(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_b_id');
    }

    public static function enabledBetween(int $x, int $y): bool
    {
        return static::query()
            ->where('company_a_id', min($x, $y))
            ->where('company_b_id', max($x, $y))
            ->where('enabled', true)
            ->exists();
    }

    public static function existsBetween(int $x, int $y, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('company_a_id', min($x, $y))
            ->where('company_b_id', max($x, $y))
            ->when($ignoreId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }
}
