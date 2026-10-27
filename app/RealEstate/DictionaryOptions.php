<?php

namespace App\RealEstate;

use Illuminate\Support\Collection;

/**
 * Опции выпадающих списков из справочников Real Estate. Подпись — на языке интерфейса,
 * а поиск по набранному тексту идёт по всем трём названиям (ka, en, ru): «Тбилиси», «Tbilisi» и «თბილისი» — один город.
 * Справочник берётся из кеша клиента, лишних запросов к API на нажатие клавиши нет.
 */
final class DictionaryOptions
{
    private const SEARCH_LIMIT = 50;

    /**
     * @param  array<string, scalar>  $params
     * @return array<int, string> id ⇒ подпись
     */
    public static function options(string $name, array $params = []): array
    {
        return self::rows($name, $params)->mapWithKeys(fn (array $r): array => [(int) $r['id'] => RealEstateClient::title($r)])->all();
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<int, string> id ⇒ подпись, только записи, где любое из трёх названий содержит $term
     */
    public static function search(string $name, array $params, string $term): array
    {
        $needle = mb_strtolower(trim($term));
        $rows = self::rows($name, $params);
        if ($needle !== '') {
            $rows = $rows->filter(fn (array $r): bool => collect(['title_ka', 'title_en', 'title_ru'])
                ->contains(fn (string $k): bool => is_string($r[$k] ?? null) && str_contains(mb_strtolower($r[$k]), $needle)));
        }

        return $rows->take(self::SEARCH_LIMIT)->mapWithKeys(fn (array $r): array => [(int) $r['id'] => RealEstateClient::title($r)])->all();
    }

    /**
     * Подписи выбранных значений (в том числе вне первых результатов поиска).
     *
     * @param  array<string, scalar>  $params
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    public static function labels(string $name, array $params, array $ids): array
    {
        $wanted = array_map('intval', $ids);

        return array_intersect_key(self::options($name, $params), array_flip($wanted));
    }

    /**
     * @param  array<string, scalar>  $params
     * @return Collection<int, array<string, mixed>>
     */
    private static function rows(string $name, array $params): Collection
    {
        return rescue(fn () => app(RealEstateClient::class)->dictionary($name, $params), collect(), report: false);
    }
}
