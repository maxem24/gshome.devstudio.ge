<?php

namespace App\RealEstate;

use App\Access\OrgAccess;
use App\Crm\Exceptions\CrmForbidden;
use App\Enums\Direction;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateUnavailable;

/**
 * Форма поиска по сайтам → тело POST /search-jobs. Направление ставит GS Home по правам
 * смотрящего, форма его только сужает; чужое направление — отказ, запрос не уходит.
 */
final class SiteSearchRequest
{
    /** @var list<string> */
    private const IDS = ['district_id', 'subdistrict_id'];

    /**
     * @param  array<string, mixed>  $form  deal (sale|rent|null), city_id, district_id[], subdistrict_id[],
     *                                      property_type_id, price_usd_min/max, rooms[], area_min/max
     * @return array<string, mixed>
     */
    public static function build(User $viewer, array $form): array
    {
        $body = ['deal_type_id' => self::dealTypeIds($viewer, $form['deal'] ?? null)];

        $city = self::id($form['city_id'] ?? null);
        $type = self::id($form['property_type_id'] ?? null);
        $body += $city !== null ? ['city_id' => $city] : [];
        $body += $type !== null ? ['property_type_id' => $type] : [];

        foreach (self::IDS as $key) {
            $ids = self::list($form[$key] ?? null, self::id(...));
            $body += $ids !== [] ? [$key => $ids] : [];
        }
        $rooms = self::list($form['rooms'] ?? null, fn (mixed $v): ?int => ($n = self::id($v)) !== null && $n <= 20 ? $n : null);
        $body += $rooms !== [] ? ['rooms' => $rooms] : [];

        foreach (['price_usd_min', 'price_usd_max', 'area_min', 'area_max'] as $key) {
            $n = self::number($form[$key] ?? null);
            $body += $n !== null ? [$key => $n] : [];
        }

        return $body;
    }

    /** @return list<int> */
    private static function dealTypeIds(User $viewer, mixed $deal): array
    {
        $allowed = OrgAccess::directions($viewer);
        $chosen = null;
        if (filled($deal)) {
            $chosen = is_string($deal) ? Direction::tryFrom($deal) : null;
            if ($chosen === null || ! in_array($chosen, $allowed, true)) {
                throw new CrmForbidden('Поиск по сделке, которой у человека нет');
            }
        }
        $ids = app(RealEstateClient::class)->dealTypeIds();

        return array_values(array_map(
            fn (Direction $d): int => $ids[$d->value] ?? throw new RealEstateUnavailable("Real Estate: нет типа сделки {$d->value}"),
            $chosen !== null ? [$chosen] : $allowed,
        ));
    }

    /**
     * @template T
     *
     * @param  \Closure(mixed): ?T  $cast
     * @return list<T>
     */
    private static function list(mixed $value, \Closure $cast): array
    {
        $out = [];
        foreach (is_array($value) ? $value : (filled($value) ? [$value] : []) as $v) {
            $c = $cast($v);
            if ($c !== null && ! in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /** Идентификатор — целое от 1, иначе «не задано». */
    private static function id(mixed $v): ?int
    {
        return (is_int($v) || (is_string($v) && preg_match('/^[1-9]\d{0,17}\z/', $v) === 1)) && (int) $v >= 1 ? (int) $v : null;
    }

    /** Число не меньше нуля, иначе «не задано». */
    private static function number(mixed $v): int|float|null
    {
        if (! (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) || ! is_finite((float) $v) || (float) $v < 0) {
            return null;
        }

        return $v + 0;
    }
}
