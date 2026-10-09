<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Уникальность email с учётом нормализации модели (нижний регистр).
 * Обычный unique сравнил бы «Giorgi@…» с «giorgi@…» как разные, и вместо
 * ошибки формы был бы 500 от уникального индекса.
 */
final class UniqueEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = Str::lower(trim((string) $value));

        $taken = User::query()
            ->where('email', $email)
            ->when($this->ignoreId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail('Человек с таким email уже есть.');
        }
    }
}
