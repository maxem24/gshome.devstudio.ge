<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Ответы API Real Estate по формату ~/Sites/real-estate.devstudio.ge/docs/RealEstate/16_API.md. */
final class RealEstateFake
{
    public const URL = 'https://re.test/api/v1';

    public static function configure(bool $phoneFilters = true): void
    {
        config([
            'services.real_estate.url' => self::URL,
            'services.real_estate.key' => 'test-key',
            'services.real_estate.phone_filters' => $phoneFilters,
        ]);
    }

    /** @return array<string, mixed> */
    public static function listing(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 13, 'source' => 'ss_ge', 'source_url' => 'https://home.ss.ge/13', 'external_id' => '13',
            'status' => 'active', 'is_primary' => true, 'cluster' => null,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'Продаётся'],
            'property_type' => ['id' => 1, 'slug' => 'apartment', 'title' => 'Квартира'],
            'city' => ['id' => 1, 'title' => 'Тбилиси'], 'district' => ['id' => 1, 'title' => 'Ваке-Сабуртало'], 'subdistrict' => null,
            'address' => 'Тбилиси, ул. Чавчавадзе, 10', 'lat' => 41.71, 'lng' => 44.77,
            'area_total' => 65.0, 'rooms' => 2, 'floor' => 5, 'floors_total' => 9,
            'price' => ['usd' => 90000.0, 'gel' => 243000.0, 'per_sqm_usd' => 1384.6, 'per_sqm_gel' => 3738.5, 'negotiable' => false],
            'title' => ['ka' => 'ბინა ვაკეში', 'en' => null, 'ru' => 'Квартира в Ваке'],
            'description' => ['ka' => 'აღწერა', 'en' => null, 'ru' => 'Описание'],
            'images' => [['url' => 'https://re.test/storage/1.webp', 'url_thumb' => 'https://re.test/storage/1_t.webp', 'is_main' => true, 'order' => 1]],
            'amenities' => [['id' => 1, 'slug' => 'elevator', 'title' => 'Лифт']],
            'subway_stations' => [],
            'seller' => ['is_owner' => true, 'is_agency' => false, 'agency_name' => null],
            'contact' => ['name' => 'Гиорги', 'phone' => '555123456', 'phone_e164' => '+995555123456', 'has_viber' => false, 'has_whatsapp' => true],
            'source_created_at' => '2026-10-05T10:00:00+00:00', 'source_updated_at' => '2026-10-06T10:00:00+00:00', 'updated_at' => '2026-10-06T10:00:00+00:00',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    public static function page(array $listings, ?string $next = null, ?string $prev = null): array
    {
        return ['data' => $listings, 'links' => [], 'meta' => ['per_page' => 25, 'next_cursor' => $next, 'prev_cursor' => $prev, 'path' => self::URL.'/listings']];
    }

    /** @return list<array<string, mixed>> */
    public static function dealTypes(): array
    {
        return [
            ['id' => 1, 'slug' => 'sale', 'title_ka' => 'იყიდება', 'title_en' => 'Sale', 'title_ru' => 'Продаётся'],
            ['id' => 2, 'slug' => 'rent', 'title_ka' => 'ქირავდება', 'title_en' => 'Rent', 'title_ru' => 'Сдаётся'],
            ['id' => 3, 'slug' => 'daily_rent', 'title_ka' => 'დღიურად', 'title_en' => 'Daily', 'title_ru' => 'Посуточно'],
            ['id' => 4, 'slug' => 'pledge', 'title_ka' => 'გირავდება', 'title_en' => 'Pledge', 'title_ru' => 'Залог'],
        ];
    }

    /**
     * Ответ API на POST/GET /search-jobs (поле data). Для status=done добавляет listings.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function searchJob(array $overrides = []): array
    {
        $job = array_replace_recursive([
            'id' => '0b0e7d3a-5c1f-4a53-9a55-3a1f0c9d2e11', 'status' => 'queued', 'message' => 'Поиск идёт, обычно 1–3 мин',
            'pages_done' => 0, 'found' => 0, 'truncated' => false,
            'sites' => [
                'myhome_ge' => ['status' => 'queued', 'found' => 0, 'pages_done' => 0, 'message' => 'Поиск идёт'],
                'ss_ge' => ['status' => 'skipped', 'found' => 0, 'pages_done' => 0, 'message' => 'Для ss.ge нужен город или район'],
            ],
        ], $overrides);
        if ($job['status'] === 'done') {
            $job += ['listings' => []];
        }

        return $job;
    }

    /**
     * Ответ API на POST/GET актуализации (поле data) по 16_API_актуализация.md.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function refresh(array $overrides = []): array
    {
        return array_replace([
            'id' => '9f3c2a1e-7b4d-4c55-9a0e-1d2f3a4b5c6d', 'status' => 'queued', 'listing_id' => 13,
            'listings_total' => 1, 'listings_refreshing' => 1, 'truncated' => false, 'paused' => false,
            'eta_seconds' => 15, 'poll_after_ms' => 2000,
            'created_at' => '2026-10-27T20:15:00+04:00', 'deadline_at' => now()->addMinutes(10)->toIso8601String(),
            'items' => [[
                'id' => 13, 'source' => 'ss_ge', 'source_url' => 'https://home.ss.ge/13', 'status' => 'active',
                'outcome' => 'pending', 'checked_at' => null, 'deactivated_at' => null, 'error' => null, 'paused_until' => null,
            ]],
            'listing' => null,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $pages  ответы GET /listings по порядку (последний повторяется)
     * @param  array<int, array<string, mixed>>  $cards  id => объявление для GET /listings/{id}
     * @param  list<PromiseInterface>  $searchPosts  ответы POST /search-jobs по порядку (последний повторяется), напр. Http::response(['data' => searchJob()], 202)
     * @param  list<PromiseInterface|\Closure>  $searchGets  то же для GET /search-jobs/{id} (Closure — напр. Http::failedConnection())
     * @param  list<PromiseInterface>  $refreshPosts  ответы POST /listings/{id}/refresh по порядку (последний повторяется)
     * @param  list<PromiseInterface>  $refreshGets  то же для GET /listing-refreshes/{id}
     */
    public static function fake(array $pages = [], array $cards = [], array $searchPosts = [], array $searchGets = [], array $refreshPosts = [], array $refreshGets = []): void
    {
        // Новые ответы — новое состояние API: короткий кеш клиента от прошлых ответов не должен их заслонять.
        Cache::flush();
        $pages = $pages ?: [self::page([])];
        $i = 0;
        $p = 0;
        $g = 0;
        $rp = 0;
        $rg = 0;
        Http::fake(function (Request $request) use (&$i, &$p, &$g, &$rp, &$rg, $pages, $cards, $searchPosts, $searchGets, $refreshPosts, $refreshGets) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/v1/dictionaries/deal-types' => Http::response(['data' => self::dealTypes()]),
                $path === '/api/v1/dictionaries/amenities' => Http::response(['data' => [
                    ['id' => 1, 'slug' => 'elevator', 'title_ka' => 'ლიფტი', 'title_en' => 'Elevator', 'title_ru' => 'Лифт'],
                    ['id' => 2, 'slug' => 'gas', 'title_ka' => 'გაზი', 'title_en' => 'Gas', 'title_ru' => 'Газ'],
                ]]),
                str_starts_with($path, '/api/v1/dictionaries/') => Http::response(['data' => [
                    ['id' => 1, 'slug' => 'tbilisi', 'title_ka' => 'თბილისი', 'title_en' => 'Tbilisi', 'title_ru' => 'Тбилиси'],
                ]]),
                $path === '/api/v1/search-jobs' && $searchPosts !== [] => $searchPosts[min($p++, count($searchPosts) - 1)],
                str_starts_with($path, '/api/v1/search-jobs/') && $searchGets !== [] => $searchGets[min($g++, count($searchGets) - 1)],
                $request->method() === 'POST' && preg_match('#^/api/v1/listings/\d+/refresh$#', $path) && $refreshPosts !== [] => $refreshPosts[min($rp++, count($refreshPosts) - 1)],
                str_starts_with($path, '/api/v1/listing-refreshes/') && $refreshGets !== [] => $refreshGets[min($rg++, count($refreshGets) - 1)],
                $path === '/api/v1/listings' => Http::response($pages[min($i++, count($pages) - 1)]),
                (bool) preg_match('#^/api/v1/listings/(\d+)$#', $path, $m) => isset($cards[(int) $m[1]])
                    ? Http::response(['data' => $cards[(int) $m[1]]])
                    : Http::response(['error' => ['code' => 'not_found', 'message' => 'Не найдено.']], 404),
                default => Http::response(['error' => ['code' => 'not_found']], 404),
            };
        });
    }
}
