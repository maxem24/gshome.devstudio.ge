<?php

namespace App\Crm;

use App\Models\Client;

/** Что человек ищет и кто будет жить. Собирается при создании клиента, не в анкете сделки. */
final class ClientProfile
{
    /** @var list<string> */
    public const KEYS = ['occupants', 'people', 'home', 'rooms', 'price_min', 'price_max', 'place'];

    /**
     * Оставить только известные ответы. Пустое и мусор отбрасываются.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int>
     */
    public static function clean(array $input): array
    {
        $out = [];
        foreach (['occupants' => 255, 'home' => 255, 'place' => 255, 'rooms' => 32] as $key => $max) {
            $text = self::text($input[$key] ?? null, $max);
            if ($text !== null) {
                $out[$key] = $text;
            }
        }

        $people = self::people($input['people'] ?? null);
        if ($people !== null) {
            $out['people'] = $people;
        }

        foreach (['price_min', 'price_max'] as $key) {
            $price = self::price($input[$key] ?? null);
            if ($price !== null) {
                $out[$key] = $price;
            }
        }
        if (isset($out['price_min'], $out['price_max']) && $out['price_max'] < $out['price_min']) {
            unset($out['price_max']);
        }

        return $out;
    }

    /** @return list<string> */
    public static function lines(?Client $client): array
    {
        $profile = $client === null ? [] : $client->profile;
        $lines = [];
        foreach (self::KEYS as $key) {
            if (! isset($profile[$key]) || $profile[$key] === '') {
                continue;
            }
            $value = $key === 'people' ? (string) $profile[$key] : (string) $profile[$key];
            if (in_array($key, ['price_min', 'price_max'], true)) {
                $value = '$'.$value;
            }
            $lines[] = __('gshome.crm.clients.profile.'.$key).': '.$value;
        }

        return $lines;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function people(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $n = (int) $value;

        return $n >= 1 && $n <= 30 ? $n : null;
    }

    private static function price(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $n = (int) $value;

        return $n >= 0 ? $n : null;
    }
}
