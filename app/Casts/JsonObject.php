<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * JSON-объект в jsonb: пустой массив пишется как `{}`, а не `[]`; читается всегда массивом.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>|null>
 */
final class JsonObject implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value === null || $value === [] ? (object) [] : $value, JSON_THROW_ON_ERROR);
    }
}
