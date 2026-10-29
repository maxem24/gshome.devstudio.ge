<?php

namespace App\Search;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Models\User;
use App\RealEstate\RealEstateClient;

/** Фильтры поиска коротко: сделка, тип, цена, площадь, комнаты, число районов. Один текст для списка поисков, страницы результата и колокольчика. */
class FiltersSummary
{
    /**
     * Направление берётся из формы, а у человека с одним направлением форма его не спрашивает — тогда из его профиля.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function make(array $filters, ?User $by = null, ?string $locale = null): string
    {
        $parts = [];
        $deal = filled($filters['deal'] ?? null) ? Direction::tryFrom((string) $filters['deal']) : null;
        if ($deal === null && $by !== null && count($directions = OrgAccess::directions($by)) === 1) {
            $deal = $directions[0];
        }
        if ($deal !== null) {
            $parts[] = __("gshome.direction.{$deal->value}", [], $locale);
        }

        if (($type = self::id($filters['property_type_id'] ?? null)) !== null) {
            $parts[] = self::typeTitle($type, $locale ?? app()->getLocale());
        }

        // 150000 → «150 000»: длинную цену глазом не прочитать.
        $n = fn (mixed $v): string => is_numeric($v) ? number_format((float) $v, 0, '.', "\u{00A0}") : (string) $v;
        $range = fn (string $key, string $unit): ?string => match (true) {
            filled($filters["{$key}_min"] ?? null) && filled($filters["{$key}_max"] ?? null) => $n($filters["{$key}_min"]).'–'.$n($filters["{$key}_max"])."\u{00A0}{$unit}",
            filled($filters["{$key}_min"] ?? null) => $n($filters["{$key}_min"])."+\u{00A0}{$unit}",
            filled($filters["{$key}_max"] ?? null) => "≤\u{00A0}".$n($filters["{$key}_max"])."\u{00A0}{$unit}",
            default => null,
        };
        $parts[] = $range('price_usd', '$');
        $parts[] = $range('area', 'm²');
        if (filled($filters['rooms'] ?? null)) {
            $parts[] = implode(', ', (array) $filters['rooms']).' '.__('gshome.search.rooms', [], $locale);
        }
        if (filled($filters['district_id'] ?? null)) {
            $parts[] = count((array) $filters['district_id']).' '.__('gshome.search.districts', [], $locale);
        }
        $parts = array_values(array_filter($parts));

        return $parts === [] ? __('gshome.search.mine.any', [], $locale) : implode(' · ', $parts);
    }

    /** Название типа на языке получателя: колокольчик собирает очередь, у неё язык процесса, а не человека. */
    private static function typeTitle(int $id, string $locale): ?string
    {
        $row = rescue(fn () => app(RealEstateClient::class)->dictionary('property-types')->firstWhere('id', $id), null, report: false);
        if (! is_array($row)) {
            return null;
        }

        return (string) (($row['title_'.$locale] ?? null) ?: ($row['title_ka'] ?? $row['id']));
    }

    private static function id(mixed $v): ?int
    {
        return is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0 ? (int) $v : null;
    }
}
