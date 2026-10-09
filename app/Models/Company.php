<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'archived_at'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /** @return Attribute<string, string> */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => $value,
            set: fn (string $value): string => Str::squish($value),
        );
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<Company> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
