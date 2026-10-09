<?php

namespace App\Rules;

use App\Models\Phone;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Номер — грузинский и ещё не заведён, с учётом нормализации: «0555…» и
 * «+995555…» — один номер. Без этого форма пропустила бы дубль до индекса (500).
 */
final class UniquePhone implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = PhoneNumber::tryNormalize(is_string($value) ? $value : null);

        if ($number === null) {
            $fail('Нужен грузинский номер.');

            return;
        }

        $taken = Phone::query()
            ->where('number', $number)
            ->when($this->ignoreId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail('Этот номер уже заведён.');
        }
    }
}
