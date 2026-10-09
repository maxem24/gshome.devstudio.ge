<?php

namespace App\Models;

use App\Enums\PhoneKind;
use App\Enums\Role;
use App\Support\PhoneNumber;
use Database\Factories\PhoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['number', 'kind', 'company_id', 'user_id'])]
class Phone extends Model
{
    /** @use HasFactory<PhoneFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Phone $phone): void {
            if ($phone->user_id === null) {
                return;
            }

            $holder = User::query()->find($phone->user_id);
            if ($holder === null
                || $holder->role === Role::Owner
                || $holder->isArchived()
                || $holder->company_id !== $phone->company_id) {
                throw new InvalidArgumentException('Держатель номера — сотрудник той же компании, не в архиве.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => PhoneKind::class,
            'company_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /** @return Attribute<string, string> */
    protected function number(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => $value,
            set: fn (string $value): string => PhoneNumber::normalize($value),
        );
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
