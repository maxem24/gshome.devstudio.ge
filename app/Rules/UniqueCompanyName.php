<?php

namespace App\Rules;

use App\Models\Company;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Уникальность названия с учётом нормализации модели (Str::squish): иначе
 * «GS Home Vake » прошло бы проверку и упало на уникальном индексе (500).
 */
final class UniqueCompanyName implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = Company::query()
            ->where('name', Str::squish((string) $value))
            ->when($this->ignoreId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail('Компания с таким названием уже есть.');
        }
    }
}
