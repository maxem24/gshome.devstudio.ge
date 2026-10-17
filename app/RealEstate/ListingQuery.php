<?php

namespace App\RealEstate;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Enums\ListingScope;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use App\RealEstate\Exceptions\TooManyPhones;

/**
 * Фильтры экрана → параметры GET /listings. Направление ставит сам по правам
 * смотрящего: выбор на экране только сужает разрешённое (ТЗ 5.6).
 */
final class ListingQuery
{
    public const PER_PAGE = 50;

    /** Предел API на phones / exclude_phones. */
    public const MAX_PHONES = 500;

    /** @return array<string, string> */
    public static function sortOptions(): array
    {
        return [
            'new' => __('gshome.listings.sort_new'),
            'price_asc' => __('gshome.listings.sort_price_asc'),
            'price_desc' => __('gshome.listings.sort_price_desc'),
            'area_asc' => __('gshome.listings.sort_area_asc'),
            'area_desc' => __('gshome.listings.sort_area_desc'),
        ];
    }

    /** Предел API на длину поиска `q`. */
    private const MAX_SEARCH = 100;

    /** Допустимые значения фильтра «Комнаты» — всё прочее из адреса отбрасывается. */
    private const ROOMS = ['1', '2', '3', '4', '5+'];

    /** Допустимые значения фильтра «Источник». */
    private const SOURCES = ['myhome_ge', 'ss_ge'];

    private const SORT_PARAMS = [
        'new' => '-updated_at', 'price_asc' => 'price_usd', 'price_desc' => '-price_usd',
        'area_asc' => 'area_total', 'area_desc' => '-area_total',
    ];

    /**
     * @param  array<string, mixed>  $filters  данные фильтров таблицы Filament
     * @return array<string, string>|null null — результат заведомо пуст, в API не ходить
     */
    public static function params(User $viewer, array $filters, ?string $search, ListingScope $scope = ListingScope::All): ?array
    {
        $client = app(RealEstateClient::class);
        $p = ['per_page' => (string) self::PER_PAGE, 'deal_type_id' => self::dealTypes($viewer, $filters, $client)];

        $set = function (string $key, mixed $value) use (&$p): void {
            if (filled($value)) {
                $p[$key] = (string) $value;
            }
        };
        $set('price_usd_min', self::number(self::scalar($filters, 'price', 'min')));
        $set('price_usd_max', self::number(self::scalar($filters, 'price', 'max')));
        $set('city_id', self::id(self::scalar($filters, 'location', 'city_id')));
        $set('district_id', self::id(self::scalar($filters, 'location', 'district_id')));
        $set('area_min', self::number(self::scalar($filters, 'area', 'min')));
        $set('area_max', self::number(self::scalar($filters, 'area', 'max')));
        $set('rooms', implode(',', self::rooms($filters)));
        $set('property_type_id', self::id(self::scalar($filters, 'property_type', 'value')));
        $set('source', in_array($source = self::scalar($filters, 'source', 'value'), self::SOURCES, true) ? $source : null);
        $set('is_owner', self::scalar($filters, 'owners_only', 'isActive') ? '1' : null);
        // Даты без времени: API понимает их как весь день в часовом поясе Тбилиси.
        // «По» раньше «с» API отвергает (after_or_equal, 422) — границы меняем местами: человек имел в виду этот период.
        $since = self::date(self::scalar($filters, 'created', 'from'));
        $until = self::date(self::scalar($filters, 'created', 'until'));
        if ($since !== null && $until !== null && $until < $since) {
            [$since, $until] = [$until, $since];
        }
        $set('created_since', $since);
        $set('created_until', $until);
        $set('sort', self::SORT_PARAMS[self::scalar($filters, 'sort', 'value') ?? ''] ?? null);
        $search = trim((string) $search);
        // max:100 в API; длиннее — 422, поэтому обрезаем (и после обрезки снова проверяем min:2).
        $search = trim(mb_substr($search, 0, self::MAX_SEARCH));
        if (mb_strlen($search) >= 2) {
            $p['q'] = $search;
        }

        $p = $client->phoneFiltersEnabled() ? self::phoneFilters($viewer, $filters, $p) : $p;

        return $p === null ? null : self::applyScope($viewer, $scope, $p);
    }

    /**
     * Скалярное значение фильтра `$group.$key` строкой. Фильтры приходят из адреса (?filters[…]),
     * поэтому массив или чужая форма вместо скаляра — не ошибка, а «фильтр не задан».
     *
     * @param  array<string, mixed>  $filters
     */
    private static function scalar(array $filters, string $group, string $key): ?string
    {
        $value = is_array($filters[$group] ?? null) ? ($filters[$group][$key] ?? null) : null;

        return match (true) {
            is_bool($value) => $value ? '1' : null,
            is_string($value), is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }

    /**
     * Идентификатор — целое от 1 без ведущих нулей (и влезающее в bigint), иначе «фильтр не задан»:
     * API ответил бы 422 (`007`, `0`), PostgreSQL — ошибкой типа.
     */
    private static function id(?string $value): ?string
    {
        return $value !== null && preg_match('/^[1-9]\d{0,17}\z/', $value) === 1 ? $value : null;
    }

    /** Число не меньше нуля, иначе «фильтр не задан». */
    private static function number(?string $value): ?string
    {
        return $value !== null && is_numeric($value) && (float) $value >= 0 && is_finite((float) $value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private static function rooms(array $filters): array
    {
        $values = is_array($filters['rooms'] ?? null) ? ($filters['rooms']['values'] ?? null) : null;

        return is_array($values)
            ? array_values(array_filter(array_unique(array_filter($values, 'is_string')), fn (string $v): bool => in_array($v, self::ROOMS, true)))
            : [];
    }

    /** Дата из формы «ГГГГ-ММ-ДД»; подделанное или неверное значение молча игнорируется. */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }

    /** @param  list<string>  $numbers */
    private static function phoneList(array $numbers): string
    {
        if (count($numbers) > self::MAX_PHONES) {
            throw new TooManyPhones('Real Estate: больше '.self::MAX_PHONES.' номеров в фильтре');
        }

        return implode(',', $numbers);
    }

    /** @param  array<string, mixed>  $filters */
    private static function dealTypes(User $viewer, array $filters, RealEstateClient $client): string
    {
        $allowed = OrgAccess::directions($viewer);
        $chosen = Direction::tryFrom(self::scalar($filters, 'direction', 'value') ?? '');
        $directions = $chosen !== null && in_array($chosen, $allowed, true) ? [$chosen] : $allowed;
        $ids = $client->dealTypeIds();

        return collect($directions)->map(fn (Direction $d): int => $ids[$d->value]
            ?? throw new RealEstateUnavailable("Real Estate: нет типа сделки {$d->value}"))
            ->implode(',');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $p
     * @return array<string, string>|null
     */
    private static function phoneFilters(User $viewer, array $filters, array $p): ?array
    {
        if (($employeeId = self::id(self::scalar($filters, 'employee', 'value'))) !== null) {
            $employee = User::query()->with('phones')->whereIn('company_id', OrgAccess::identifiableCompanyIds($viewer))->find($employeeId);
            $numbers = $employee?->phones->pluck('number')->all() ?? [];
            if ($numbers === []) {
                return null;
            }
            $p['phones'] = self::phoneList($numbers);
        }

        $ownership = self::scalar($filters, 'ownership', 'value');
        if ($ownership === 'ours' || $ownership === 'others') {
            $numbers = OrgAccess::identifiablePhones($viewer);
            if ($ownership === 'ours') {
                if ($numbers === []) {
                    return null;
                }
                $p['phones'] ??= self::phoneList($numbers);
            } elseif ($numbers !== []) {
                $p['exclude_phones'] = self::phoneList($numbers);
            }
        }

        return $p;
    }

    /**
     * Кнопки над списком. «Агентства» — `is_agency=1` (партнёрский API это принимает).
     * «Наше агентство» и «Мои» — только номера, и только когда фильтр телефонов включён.
     *
     * @param  array<string, string>  $p
     * @return array<string, string>|null
     */
    private static function applyScope(User $viewer, ListingScope $scope, array $p): ?array
    {
        if ($scope === ListingScope::Owners) {
            $p['is_owner'] = '1';

            return $p;
        }

        if ($scope === ListingScope::Agencies) {
            unset($p['is_owner']);
            $p['is_agency'] = '1';

            return $p;
        }

        if ($scope === ListingScope::All) {
            return $p;
        }

        if (! app(RealEstateClient::class)->phoneFiltersEnabled()) {
            return null;
        }

        $numbers = $scope === ListingScope::Mine
            ? OrgAccess::holderPhones($viewer)
            : OrgAccess::agencyPhones($viewer);
        if (isset($p['phones'])) {
            $numbers = array_values(array_intersect($numbers, explode(',', $p['phones'])));
        }
        if ($numbers === []) {
            return null;
        }

        unset($p['exclude_phones']);
        $p['phones'] = self::phoneList($numbers);
        // Главное в группе часто объявление собственника. Для «моих» нужны копии самого агента.
        if ($scope === ListingScope::Mine) {
            $p['only_primary'] = '0';
        }

        return $p;
    }
}
