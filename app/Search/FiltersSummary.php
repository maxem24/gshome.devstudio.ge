<?php

namespace App\Search;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Models\User;

/** Фильтры поиска коротко: сделка, цена, площадь, комнаты, число районов. Один текст для списка поисков, страницы результата и колокольчика. */
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

        $range = fn (string $key, string $unit): ?string => match (true) {
            filled($filters["{$key}_min"] ?? null) && filled($filters["{$key}_max"] ?? null) => "{$filters["{$key}_min"]}–{$filters["{$key}_max"]} {$unit}",
            filled($filters["{$key}_min"] ?? null) => "{$filters["{$key}_min"]}+ {$unit}",
            filled($filters["{$key}_max"] ?? null) => "≤ {$filters["{$key}_max"]} {$unit}",
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
}
