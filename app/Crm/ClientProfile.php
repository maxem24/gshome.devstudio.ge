<?php

namespace App\Crm;

use App\Enums\ClientHome;
use App\Enums\ClientOccupant;
use App\Models\Client;

/** Что человек ищет и кто будет жить. Собирается при создании клиента, не в анкете сделки. */
final class ClientProfile
{
    /** @var list<string> */
    public const KEYS = ['occupants', 'people', 'home', 'rooms_min', 'rooms_max', 'price_min', 'price_max', 'subdistrict_ids', 'note'];

    /**
     * Оставить только известные ответы. Свободный текст — только комментарий.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|list<string>|list<int>>
     */
    public static function clean(array $input): array
    {
        $out = [];

        $occupant = self::enumValue($input['occupants'] ?? null, ClientOccupant::class);
        if ($occupant !== null) {
            $out['occupants'] = $occupant;
        }

        $people = self::people($input['people'] ?? null);
        if ($people !== null) {
            $out['people'] = $people;
        }

        $home = self::enumList($input['home'] ?? null, ClientHome::class);
        if ($home !== []) {
            $out['home'] = $home;
        }

        foreach (['rooms_min', 'rooms_max'] as $key) {
            $rooms = self::rooms($input[$key] ?? null);
            if ($rooms !== null) {
                $out[$key] = $rooms;
            }
        }
        if (isset($out['rooms_min'], $out['rooms_max']) && $out['rooms_max'] < $out['rooms_min']) {
            unset($out['rooms_max']);
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

        $places = self::ids($input['subdistrict_ids'] ?? null);
        if ($places !== []) {
            $out['subdistrict_ids'] = $places;
        }

        $note = self::text($input['note'] ?? null, 2000);
        if ($note !== null) {
            $out['note'] = $note;
        }

        return $out;
    }

    /**
     * Предпочтения собственника: тот же список, но «кто будет жить» — несколько вариантов.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|list<string>|list<int>>
     */
    public static function cleanLandlord(array $input): array
    {
        $occupants = self::enumList($input['occupants'] ?? null, ClientOccupant::class);
        // Цена, район и комнаты собственника берутся из объявления (снимок на listing_pins).
        $rest = self::clean(array_intersect_key($input, array_flip(['people', 'home', 'note'])));

        return $occupants === [] ? $rest : ['occupants' => $occupants] + $rest;
    }

    /** @return list<string> */
    public static function lines(?Client $client): array
    {
        return self::linesFor($client === null ? [] : $client->profile);
    }

    /**
     * Строки для показа: профиль клиента или предпочтения собственника.
     *
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function linesFor(array $profile): array
    {
        $lines = [];

        $who = self::enumList($profile['occupants'] ?? null, ClientOccupant::class);
        if ($who !== []) {
            $lines[] = __('gshome.crm.clients.profile.occupants').': '.implode(', ', array_map(
                fn (string $key): string => ClientOccupant::from($key)->getLabel(),
                $who,
            ));
        }
        if (isset($profile['people']) && $profile['people'] !== '') {
            $lines[] = __('gshome.crm.clients.profile.people').': '.$profile['people'];
        }
        $homes = self::enumList($profile['home'] ?? null, ClientHome::class);
        if ($homes !== []) {
            $lines[] = __('gshome.crm.clients.profile.home').': '.implode(', ', array_map(
                fn (string $key): string => ClientHome::from($key)->getLabel(),
                $homes,
            ));
        }
        $min = self::rooms($profile['rooms_min'] ?? null);
        $max = self::rooms($profile['rooms_max'] ?? null);
        if ($min !== null || $max !== null) {
            $range = match (true) {
                $max === null => __('gshome.crm.clients.profile.rooms_from', ['n' => $min]),
                $min === null => __('gshome.crm.clients.profile.rooms_to', ['n' => $max]),
                $min === $max => (string) $min,
                default => $min.'–'.$max,
            };
            $lines[] = __('gshome.crm.clients.profile.rooms').': '.$range;
        }
        foreach (['price_min', 'price_max'] as $key) {
            if (isset($profile[$key]) && $profile[$key] !== '') {
                $lines[] = __('gshome.crm.clients.profile.'.$key).': $'.$profile[$key];
            }
        }
        $places = self::ids($profile['subdistrict_ids'] ?? null);
        if ($places !== []) {
            $lines[] = __('gshome.crm.clients.profile.place').': '.implode(', ', ClientPlaces::labels($places));
        }
        if (is_string($profile['note'] ?? null) && $profile['note'] !== '') {
            $lines[] = __('gshome.crm.clients.profile.note').': '.$profile['note'];
        }

        return $lines;
    }

    /** @param  class-string<ClientOccupant|ClientHome>  $enum */
    private static function enumValue(mixed $value, string $enum): ?string
    {
        if ($value instanceof $enum) {
            return $value->value;
        }

        return is_string($value) ? $enum::tryFrom($value)?->value : null;
    }

    /**
     * @param  class-string<ClientOccupant|ClientHome>  $enum
     * @return list<string>
     */
    private static function enumList(mixed $value, string $enum): array
    {
        if ($value instanceof $enum) {
            return [$value->value];
        }
        if (is_string($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $key = self::enumValue($item, $enum);
            if ($key !== null) {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
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

    private static function rooms(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $n = (int) $value;

        return $n >= 1 && $n <= 20 ? $n : null;
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
