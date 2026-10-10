<?php

namespace App\RealEstate;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use App\RealEstate\Exceptions\TooManyPhones;

/**
 * Фильтры экрана → параметры GET /listings. Направление ставит сам по правам
 * смотрящего: выбор на экране только сужает разрешённое (ТЗ 5.6).
 */
final class ListingQuery
{
    public const PER_PAGE = 25;

    /** Предел API на phones / exclude_phones. */
    public const MAX_PHONES = 500;

    public const SORTS = [
        'new' => 'Сначала новые',
        'price_asc' => 'Цена: по возрастанию',
        'price_desc' => 'Цена: по убыванию',
        'area_asc' => 'Площадь: по возрастанию',
        'area_desc' => 'Площадь: по убыванию',
    ];

    private const SORT_PARAMS = [
        'new' => '-updated_at', 'price_asc' => 'price_usd', 'price_desc' => '-price_usd',
        'area_asc' => 'area_total', 'area_desc' => '-area_total',
    ];

    /**
     * @param  array<string, mixed>  $filters  данные фильтров таблицы Filament
     * @return array<string, string>|null null — результат заведомо пуст, в API не ходить
     */
    public static function params(User $viewer, array $filters, ?string $search): ?array
    {
        $client = app(RealEstateClient::class);
        $p = ['per_page' => (string) self::PER_PAGE, 'deal_type_id' => self::dealTypes($viewer, $filters, $client)];

        $set = function (string $key, mixed $value) use (&$p): void {
            if (filled($value)) {
                $p[$key] = (string) $value;
            }
        };
        $set('price_usd_min', $filters['price']['min'] ?? null);
        $set('price_usd_max', $filters['price']['max'] ?? null);
        $set('city_id', $filters['location']['city_id'] ?? null);
        $set('district_id', $filters['location']['district_id'] ?? null);
        $set('area_min', $filters['area']['min'] ?? null);
        $set('area_max', $filters['area']['max'] ?? null);
        $set('rooms', implode(',', $filters['rooms']['values'] ?? []));
        $set('property_type_id', $filters['property_type']['value'] ?? null);
        $set('source', $filters['source']['value'] ?? null);
        $set('is_owner', ($filters['owners_only']['isActive'] ?? false) ? '1' : null);
        // Даты без времени: API понимает их как весь день в часовом поясе Тбилиси.
        $set('created_since', self::date($filters['created']['from'] ?? null));
        $set('created_until', self::date($filters['created']['until'] ?? null));
        $set('sort', self::SORT_PARAMS[$filters['sort']['value'] ?? ''] ?? null);
        if (mb_strlen(trim((string) $search)) >= 2) {
            $p['q'] = trim((string) $search);
        }

        return $client->phoneFiltersEnabled() ? self::phoneFilters($viewer, $filters, $p) : $p;
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
        $chosen = Direction::tryFrom((string) ($filters['direction']['value'] ?? ''));
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
        if (filled($employeeId = $filters['employee']['value'] ?? null)) {
            $employee = User::query()->with('phones')->whereIn('company_id', OrgAccess::identifiableCompanyIds($viewer))->find($employeeId);
            $numbers = $employee?->phones->pluck('number')->all() ?? [];
            if ($numbers === []) {
                return null;
            }
            $p['phones'] = self::phoneList($numbers);
        }

        $ownership = $filters['ownership']['value'] ?? null;
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
}
