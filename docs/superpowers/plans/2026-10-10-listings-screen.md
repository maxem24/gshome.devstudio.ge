# Экран объявлений (этап «просмотр») — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Страница «Объекты» (сетка и список, фильтры, сортировка, переключатель направления) и карточка объекта с меткой принадлежности — живыми запросами к API Real Estate.

**Architecture:** Тонкий клиент `App\RealEstate\RealEstateClient` превращает ответы API в readonly-объекты `Listing`; `ListingQuery` собирает параметры запроса и сам подставляет разрешённое направление; `ListingRows` считает метки страницы одним проходом через `OrgAccess::ownershipMany`. Страница Filament с таблицей поверх `records()` и собственным `ApiCursorPaginator` (курсор API — непрозрачная строка). Карточка — отдельная страница с маршрутом `/listings/{listing}`.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5.10 (custom data tables, cursor pagination), PHPUnit 12, `Http::fake`.

**Spec:** `docs/superpowers/specs/2026-10-10-listings-screen-design.md`

## Global Constraints

- Всё в Docker: `docker compose exec app …`; тесты — только `docker compose run --rm test …`; один класс — `docker compose run --rm test php artisan test --filter=<Имя>`.
- После каждой задачи: `docker compose exec app composer analyse` — 0 ошибок, без baseline; `docker compose exec app vendor/bin/pint --dirty`.
- Тесты не ходят в сеть (`Http::preventStrayRequests` в `TestCase`) — только `Http::fake` через `Tests\Support\RealEstateFake`.
- Направление в запрос к API ставит только `ListingQuery` из `OrgAccess::directions()`; номера типов сделок не зашивать — брать из справочника `deal-types` по `slug` (`sale`, `rent`).
- В GS Home попадают только `sale` и `rent`; `daily_rent`, `pledge` — никогда (ни в списке, ни в карточке).
- Ключ API — только из `config('services.real_estate.key')`; в логи и сообщения не выводить.
- Проверку TLS не отключать.
- Подписи интерфейса — по-русски; язык содержимого — `app()->getLocale()`, откат на `ka`.
- Правила видимости имён и контактов — только через `OrgAccess` (`ownershipMany`, `identifiableCompanyIds`).
- Ветка `feature/listings-screen`; сообщения коммитов заканчиваются строками
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` и
  `Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg`.

## Review Focus

1. **Подделанное направление** — сдающий шлёт `?tableFilters[direction][value]=sale` или открывает `/listings/{id}` продажи: в API уходит только сдача, карточка — 404. Тесты — Task 5, Task 8.
2. **Объявление без группы дублей** (`cluster: null`) и с замаскированным номером (`phone_e164: null`) — метка «Другая компания», без ошибок. Тесты — Task 2, Task 4.
3. **Продавец «не агентство», но с названием агентства** (`is_agency=false`, `agency_name` заполнено — так бывает в данных) — категория «Агентство». Тест — Task 2.
4. **Real Estate недоступен / ключ отозван / лимит** — пустой список с понятным уведомлением, а не 500. Тесты — Task 3, Task 6.
5. **Фильтр «наши» при нуле номеров** (у смотрящего нет ни одного опознаваемого номера) — пустой результат без запроса в API (пустой `phones` API отвергает 422). Тест — Task 5.

---

### Task 1: Исключения клиента и курсорный пагинатор

**Files:**
- Create: `app/RealEstate/Exceptions/RealEstateException.php`, `RealEstateUnavailable.php`, `RealEstateAccessDenied.php`, `RealEstateRateLimited.php`, `RealEstateBadRequest.php`
- Create: `app/RealEstate/Pagination/ApiCursor.php`, `app/RealEstate/Pagination/ApiCursorPaginator.php`
- Test: `tests/Unit/RealEstate/ApiCursorPaginatorTest.php`

**Interfaces:**
- Produces: `RealEstateException` (abstract, `userMessage(): string`); `RealEstateRateLimited::$retryAfter`; `ApiCursorPaginator::__construct(iterable $items, int $perPage, ?string $currentCursor, ?string $nextCursor, ?string $prevCursor, array $options = [])`, `ApiCursorPaginator::emptyPage(int $perPage, array $options = []): self`.

- [ ] **Step 0: Ветка** — `git switch -c feature/listings-screen`

- [ ] **Step 1: Падающий тест**

`tests/Unit/RealEstate/ApiCursorPaginatorTest.php`:

```php
<?php

namespace Tests\Unit\RealEstate;

use App\RealEstate\Pagination\ApiCursorPaginator;
use PHPUnit\Framework\TestCase;

class ApiCursorPaginatorTest extends TestCase
{
    public function test_first_page_with_next_cursor(): void
    {
        $p = new ApiCursorPaginator([13 => ['id' => 13], 12 => ['id' => 12]], 2, null, 'eyJuZXh0Ijox', null);

        $this->assertTrue($p->onFirstPage());
        $this->assertTrue($p->hasMorePages());
        $this->assertSame('eyJuZXh0Ijox', $p->nextCursor()?->encode());
        $this->assertNull($p->previousCursor());
        $this->assertSame([13, 12], array_column($p->items(), 'id'));
    }

    public function test_middle_page_has_both_cursors(): void
    {
        $p = new ApiCursorPaginator([['id' => 1]], 1, 'cur', 'next', 'prev');

        $this->assertFalse($p->onFirstPage());
        $this->assertTrue($p->hasMorePages());
        $this->assertSame('prev', $p->previousCursor()?->encode());
    }

    public function test_last_page(): void
    {
        $p = new ApiCursorPaginator([['id' => 1]], 25, 'cur', null, 'prev');

        $this->assertFalse($p->hasMorePages());
        $this->assertNull($p->nextCursor());
        $this->assertTrue($p->hasPages());
    }

    public function test_empty_page(): void
    {
        $p = ApiCursorPaginator::emptyPage(25);

        $this->assertCount(0, $p->items());
        $this->assertFalse($p->hasPages());
    }
}
```

- [ ] **Step 2: Убедиться, что падает** — `docker compose run --rm test php artisan test --filter=ApiCursorPaginatorTest` → FAIL: класс не найден.

- [ ] **Step 3: Исключения**

`app/RealEstate/Exceptions/RealEstateException.php`:

```php
<?php

namespace App\RealEstate\Exceptions;

use RuntimeException;

/** Ошибка обращения к API Real Estate; userMessage() — что показать человеку. */
abstract class RealEstateException extends RuntimeException
{
    abstract public function userMessage(): string;
}
```

`RealEstateUnavailable.php`:

```php
<?php

namespace App\RealEstate\Exceptions;

final class RealEstateUnavailable extends RealEstateException
{
    public function userMessage(): string
    {
        return 'Real Estate недоступен, попробуйте позже.';
    }
}
```

`RealEstateAccessDenied.php`:

```php
<?php

namespace App\RealEstate\Exceptions;

final class RealEstateAccessDenied extends RealEstateException
{
    public function userMessage(): string
    {
        return 'Доступ к Real Estate закрыт — ключ истёк или отозван. Сообщите администратору.';
    }
}
```

`RealEstateRateLimited.php`:

```php
<?php

namespace App\RealEstate\Exceptions;

final class RealEstateRateLimited extends RealEstateException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Rate limited, retry after {$retryAfter}s");
    }

    public function userMessage(): string
    {
        return "Слишком много запросов к Real Estate, повторите через {$this->retryAfter} с.";
    }
}
```

`RealEstateBadRequest.php`:

```php
<?php

namespace App\RealEstate\Exceptions;

/** 422 — ошибка в запросе GS Home (баг клиента), человеку — общий текст. */
final class RealEstateBadRequest extends RealEstateException
{
    public function userMessage(): string
    {
        return 'Не удалось загрузить объявления. Ошибка записана в журнал.';
    }
}
```

- [ ] **Step 4: Пагинатор**

`app/RealEstate/Pagination/ApiCursor.php`:

```php
<?php

namespace App\RealEstate\Pagination;

use Illuminate\Pagination\Cursor;

/** Курсор API Real Estate — непрозрачная строка, передаётся обратно как есть. */
final class ApiCursor extends Cursor
{
    public function __construct(private readonly string $raw)
    {
        parent::__construct([]);
    }

    public function encode(): string
    {
        return $this->raw;
    }
}
```

`app/RealEstate/Pagination/ApiCursorPaginator.php`:

```php
<?php

namespace App\RealEstate\Pagination;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * Курсорная пагинация поверх API: «вперёд/назад» — курсоры из ответа API, а не
 * вычисленные Laravel по последней строке. Кнопки Filament берут nextCursor()->encode().
 *
 * @extends CursorPaginator<array-key, array<string, mixed>>
 */
final class ApiCursorPaginator extends CursorPaginator
{
    /**
     * @param  iterable<array-key, array<string, mixed>>  $items
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        iterable $items,
        int $perPage,
        ?string $currentCursor,
        private readonly ?string $nextRaw,
        private readonly ?string $prevRaw,
        array $options = [],
    ) {
        parent::__construct($items, $perPage, $currentCursor !== null ? new ApiCursor($currentCursor) : null, $options);
    }

    /** @param  array<string, mixed>  $options */
    public static function emptyPage(int $perPage, array $options = []): self
    {
        return new self([], $perPage, null, null, null, $options);
    }

    public function nextCursor(): ?Cursor
    {
        return $this->nextRaw === null ? null : new ApiCursor($this->nextRaw);
    }

    public function previousCursor(): ?Cursor
    {
        return $this->prevRaw === null ? null : new ApiCursor($this->prevRaw);
    }

    public function hasMorePages(): bool
    {
        return $this->nextRaw !== null;
    }

    public function onFirstPage(): bool
    {
        return $this->prevRaw === null;
    }
}
```

- [ ] **Step 5: Тесты** — `docker compose run --rm test php artisan test --filter=ApiCursorPaginatorTest` → PASS (4).

- [ ] **Step 6: Анализ и коммит**

```bash
docker compose exec app composer analyse && docker compose exec app vendor/bin/pint --dirty
git add app/RealEstate tests/Unit/RealEstate
git commit -m "feat(real-estate): API client exceptions and opaque-cursor paginator

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 2: Объявление `Listing` и тестовые ответы API

**Files:**
- Create: `app/Enums/SellerCategory.php`, `app/RealEstate/Listing.php`, `app/RealEstate/ListingPage.php`
- Create: `tests/Support/RealEstateFake.php`
- Test: `tests/Unit/RealEstate/ListingTest.php`

**Interfaces:**
- Consumes: `Direction` (оргструктура), `PhoneNumber::tryNormalize`.
- Produces: `Listing::fromApi(array $data, string $locale): Listing`; свойства — см. Step 3; `Listing::direction(): ?Direction`, `Listing::priceLabel(): string`; `SellerCategory` (`Owner|Agency|Agent`, `getLabel()`); `ListingPage` (`listings: list<Listing>`, `nextCursor`, `prevCursor`); `RealEstateFake::configure(bool $phoneFilters = true)`, `::listing(array $overrides = []): array`, `::page(array $listings, ?string $next = null, ?string $prev = null): array`, `::fake(array $pages = [], array $cards = [], ?callable $onList = null): void`, константа `RealEstateFake::URL`.

- [ ] **Step 1: Тестовые ответы (помощник для всех тестов)**

`tests/Support/RealEstateFake.php`:

```php
<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
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
     * @param  list<array<string, mixed>>  $pages  ответы GET /listings по порядку (последний повторяется)
     * @param  array<int, array<string, mixed>>  $cards  id => объявление для GET /listings/{id}
     */
    public static function fake(array $pages = [], array $cards = []): void
    {
        $pages = $pages ?: [self::page([])];
        $i = 0;
        Http::fake(function (Request $request) use (&$i, $pages, $cards) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/v1/dictionaries/deal-types' => Http::response(['data' => self::dealTypes()]),
                str_starts_with($path, '/api/v1/dictionaries/') => Http::response(['data' => [
                    ['id' => 1, 'slug' => 'tbilisi', 'title_ka' => 'თბილისი', 'title_en' => 'Tbilisi', 'title_ru' => 'Тбилиси'],
                ]]),
                $path === '/api/v1/listings' => Http::response($pages[min($i++, count($pages) - 1)]),
                (bool) preg_match('#^/api/v1/listings/(\d+)$#', $path, $m) => isset($cards[(int) $m[1]])
                    ? Http::response(['data' => $cards[(int) $m[1]]])
                    : Http::response(['error' => ['code' => 'not_found', 'message' => 'Не найдено.']], 404),
                default => Http::response(['error' => ['code' => 'not_found']], 404),
            };
        });
    }
}
```

- [ ] **Step 2: Падающие тесты**

`tests/Unit/RealEstate/ListingTest.php`:

```php
<?php

namespace Tests\Unit\RealEstate;

use App\Enums\Direction;
use App\Enums\SellerCategory;
use App\RealEstate\Listing;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingTest extends TestCase
{
    public function test_parses_core_fields(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(), 'ru');

        $this->assertSame(13, $l->id);
        $this->assertSame(Direction::Sale, $l->direction());
        $this->assertSame('Квартира в Ваке', $l->title);
        $this->assertSame('+995555123456', $l->contactPhoneE164);
        $this->assertSame(SellerCategory::Owner, $l->sellerCategory);
        $this->assertSame([], $l->clusterPhonesE164);
        $this->assertSame(1, $l->clusterListingsCount);
        $this->assertSame('https://re.test/storage/1_t.webp', $l->mainThumb());
    }

    public function test_title_falls_back_to_georgian(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['title' => ['ru' => null], 'description' => ['ru' => null]]), 'ru');

        $this->assertSame('ბინა ვაკეში', $l->title);
        $this->assertSame('აღწერა', $l->description);
    }

    public function test_masked_phone_has_no_e164(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['contact' => ['phone' => '555402***', 'phone_e164' => null]]), 'ru');

        $this->assertNull($l->contactPhoneE164);
    }

    public function test_old_api_without_e164_is_normalized_here(): void
    {
        $data = RealEstateFake::listing();
        unset($data['contact']['phone_e164']);

        $this->assertSame('+995555123456', Listing::fromApi($data, 'ru')->contactPhoneE164);
    }

    public function test_agency_name_without_flag_is_agency(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['seller' => ['is_owner' => false, 'is_agency' => false, 'agency_name' => 'ელსერვისი']]), 'ru');

        $this->assertSame(SellerCategory::Agency, $l->sellerCategory);
    }

    public function test_neither_owner_nor_agency_is_agent(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['seller' => ['is_owner' => false, 'is_agency' => false, 'agency_name' => null]]), 'ru');

        $this->assertSame(SellerCategory::Agent, $l->sellerCategory);
    }

    public function test_cluster_phones_and_members(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['cluster' => [
            'id' => 5, 'listings_count' => 2, 'phones_e164' => ['+995555123456', '+995555614795'],
            'listings' => [['id' => 55, 'source' => 'ss_ge', 'source_url' => 'https://x/55', 'is_primary' => false,
                'seller' => ['is_owner' => false, 'is_agency' => false, 'agency_name' => null],
                'contact' => ['name' => 'Агент', 'phone' => '555614795', 'phone_e164' => '+995555614795', 'has_viber' => false, 'has_whatsapp' => false],
                'price' => ['usd' => 89000.0, 'gel' => 240300.0], 'updated_at' => '2026-10-07T10:00:00+00:00']],
        ]]), 'ru');

        $this->assertSame(['+995555123456', '+995555614795'], $l->clusterPhonesE164);
        $this->assertSame(2, $l->clusterListingsCount);
        $this->assertSame(55, $l->clusterListings[0]['id']);
        $this->assertSame('+995555614795', $l->clusterListings[0]['phone_e164']);
    }

    public function test_price_label_by_direction(): void
    {
        $this->assertSame('$90 000', Listing::fromApi(RealEstateFake::listing(), 'ru')->priceLabel());
        $rent = Listing::fromApi(RealEstateFake::listing(['deal_type' => ['id' => 2, 'slug' => 'rent'], 'price' => ['usd' => 800.0]]), 'ru');
        $this->assertSame('$800 / мес', $rent->priceLabel());
        $this->assertSame('цена не указана', Listing::fromApi(RealEstateFake::listing(['price' => ['usd' => null]]), 'ru')->priceLabel());
    }
}
```

- [ ] **Step 3: Убедиться, что падают** — `--filter=ListingTest` → FAIL: класс не найден.

- [ ] **Step 4: Реализация**

`app/Enums/SellerCategory.php`:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Кто выставил объявление на площадке (ТЗ 4.5, решение 2026-10-10). */
enum SellerCategory: string implements HasLabel
{
    case Owner = 'owner';
    case Agency = 'agency';
    case Agent = 'agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Собственник',
            self::Agency => 'Агентство',
            self::Agent => 'Агент',
        };
    }
}
```

`app/RealEstate/Listing.php`:

```php
<?php

namespace App\RealEstate;

use App\Enums\Direction;
use App\Enums\SellerCategory;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;

/** Объявление из API Real Estate (только чтение). */
final readonly class Listing
{
    /**
     * @param  list<array{url: string, url_thumb: ?string, is_main: bool}>  $images
     * @param  list<string>  $amenities
     * @param  list<string>  $subwayStations
     * @param  list<string>  $clusterPhonesE164
     * @param  list<array{id: int, source: string, source_url: string, seller_category: SellerCategory, agency_name: ?string, contact_name: ?string, phone_e164: ?string, price_usd: ?float}>  $clusterListings
     */
    public function __construct(
        public int $id,
        public string $source,
        public string $sourceUrl,
        public ?string $dealTypeSlug,
        public ?string $dealTypeTitle,
        public ?string $propertyType,
        public ?string $city,
        public ?string $district,
        public ?string $address,
        public ?float $lat,
        public ?float $lng,
        public ?float $areaTotal,
        public ?int $rooms,
        public ?int $floor,
        public ?int $floorsTotal,
        public ?float $priceUsd,
        public ?float $priceGel,
        public bool $negotiable,
        public ?string $title,
        public ?string $description,
        public array $images,
        public array $amenities,
        public array $subwayStations,
        public SellerCategory $sellerCategory,
        public ?string $agencyName,
        public ?string $contactName,
        public ?string $contactPhone,
        public ?string $contactPhoneE164,
        public bool $hasViber,
        public bool $hasWhatsapp,
        public int $clusterListingsCount,
        public array $clusterPhonesE164,
        public array $clusterListings,
        public ?Carbon $sourceCreatedAt,
    ) {}

    /** @param  array<string, mixed>  $d */
    public static function fromApi(array $d, string $locale): self
    {
        $text = fn (?array $t): ?string => ($t[$locale] ?? null) ?: ($t['ka'] ?? null);
        $phone = $d['contact']['phone'] ?? null;
        $cluster = $d['cluster'] ?? null;

        return new self(
            id: (int) $d['id'],
            source: (string) $d['source'],
            sourceUrl: (string) $d['source_url'],
            dealTypeSlug: $d['deal_type']['slug'] ?? null,
            dealTypeTitle: $d['deal_type']['title'] ?? null,
            propertyType: $d['property_type']['title'] ?? null,
            city: $d['city']['title'] ?? null,
            district: $d['district']['title'] ?? null,
            address: $d['address'] ?? null,
            lat: isset($d['lat']) ? (float) $d['lat'] : null,
            lng: isset($d['lng']) ? (float) $d['lng'] : null,
            areaTotal: isset($d['area_total']) ? (float) $d['area_total'] : null,
            rooms: isset($d['rooms']) ? (int) $d['rooms'] : null,
            floor: isset($d['floor']) ? (int) $d['floor'] : null,
            floorsTotal: isset($d['floors_total']) ? (int) $d['floors_total'] : null,
            priceUsd: isset($d['price']['usd']) ? (float) $d['price']['usd'] : null,
            priceGel: isset($d['price']['gel']) ? (float) $d['price']['gel'] : null,
            negotiable: (bool) ($d['price']['negotiable'] ?? false),
            title: $text($d['title'] ?? null),
            description: $text($d['description'] ?? null),
            images: array_values(array_map(fn (array $i): array => [
                'url' => (string) $i['url'], 'url_thumb' => $i['url_thumb'] ?? null, 'is_main' => (bool) ($i['is_main'] ?? false),
            ], $d['images'] ?? [])),
            amenities: array_values(array_map(fn (array $a): string => (string) ($a['title'] ?? $a['slug'] ?? ''), $d['amenities'] ?? [])),
            subwayStations: array_values(array_map(
                fn (array $s): string => trim(($s['title'] ?? '').(isset($s['walk_minutes']) ? " ({$s['walk_minutes']} мин)" : '')),
                $d['subway_stations'] ?? [],
            )),
            sellerCategory: self::category($d['seller'] ?? []),
            agencyName: $d['seller']['agency_name'] ?? null,
            contactName: $d['contact']['name'] ?? null,
            contactPhone: $phone,
            contactPhoneE164: array_key_exists('phone_e164', $d['contact'] ?? [])
                ? $d['contact']['phone_e164']
                : PhoneNumber::tryNormalize($phone),
            hasViber: (bool) ($d['contact']['has_viber'] ?? false),
            hasWhatsapp: (bool) ($d['contact']['has_whatsapp'] ?? false),
            clusterListingsCount: (int) ($cluster['listings_count'] ?? 1),
            clusterPhonesE164: array_values($cluster['phones_e164'] ?? []),
            clusterListings: array_values(array_map(fn (array $m): array => [
                'id' => (int) $m['id'],
                'source' => (string) $m['source'],
                'source_url' => (string) $m['source_url'],
                'seller_category' => self::category($m['seller'] ?? []),
                'agency_name' => $m['seller']['agency_name'] ?? null,
                'contact_name' => $m['contact']['name'] ?? null,
                'phone_e164' => $m['contact']['phone_e164'] ?? null,
                'price_usd' => isset($m['price']['usd']) ? (float) $m['price']['usd'] : null,
            ], $cluster['listings'] ?? [])),
            sourceCreatedAt: isset($d['source_created_at']) ? Carbon::parse($d['source_created_at']) : null,
        );
    }

    /** @param  array<string, mixed>  $seller */
    private static function category(array $seller): SellerCategory
    {
        return match (true) {
            (bool) ($seller['is_owner'] ?? false) => SellerCategory::Owner,
            (bool) ($seller['is_agency'] ?? false) || filled($seller['agency_name'] ?? null) => SellerCategory::Agency,
            default => SellerCategory::Agent,
        };
    }

    public function direction(): ?Direction
    {
        return Direction::tryFrom((string) $this->dealTypeSlug);
    }

    public function priceLabel(): string
    {
        if ($this->priceUsd === null) {
            return 'цена не указана';
        }
        $amount = '$'.number_format($this->priceUsd, 0, '.', ' ');

        return $this->direction() === Direction::Rent ? "{$amount} / мес" : $amount;
    }

    public function mainThumb(): ?string
    {
        $main = collect($this->images)->firstWhere('is_main', true) ?? ($this->images[0] ?? null);

        return $main === null ? null : ($main['url_thumb'] ?? $main['url']);
    }

    public function facts(): string
    {
        return collect([
            $this->rooms !== null ? "{$this->rooms} комн." : null,
            $this->areaTotal !== null ? rtrim(rtrim(number_format($this->areaTotal, 1, '.', ' '), '0'), '.').' м²' : null,
            $this->floor !== null ? "{$this->floor}".($this->floorsTotal ? "/{$this->floorsTotal}" : '').' эт.' : null,
            $this->propertyType,
        ])->filter()->implode(' · ');
    }
}
```

`app/RealEstate/ListingPage.php`:

```php
<?php

namespace App\RealEstate;

final readonly class ListingPage
{
    /** @param  list<Listing>  $listings */
    public function __construct(
        public array $listings,
        public ?string $nextCursor,
        public ?string $prevCursor,
    ) {}
}
```

- [ ] **Step 5: Тесты** — `--filter=ListingTest` → PASS (8). Если `test_price_label_by_direction` падает на неразрывном пробеле — оставить обычный пробел в `number_format` и в тесте (как написано).

- [ ] **Step 6: Анализ и коммит** — `analyse`, `pint --dirty`;
  `git add app tests && git commit` с сообщением `feat(real-estate): Listing value object from API data` и строками атрибуции.

---

### Task 3: Клиент API

**Files:**
- Modify: `config/services.php` (`phone_filters`), `.env.example` (`REAL_ESTATE_PHONE_FILTERS=false`), `.env` (`REAL_ESTATE_PHONE_FILTERS=true` — локально API уже умеет)
- Create: `app/RealEstate/RealEstateClient.php`
- Test: `tests/Feature/RealEstate/RealEstateClientTest.php`

**Interfaces:**
- Consumes: Task 1 исключения, Task 2 `Listing`, `ListingPage`, `RealEstateFake`.
- Produces: `RealEstateClient::listings(array $params, ?string $cursor): ListingPage`; `::listing(int $id): ?Listing`; `::dictionary(string $name, array $params = []): Collection<int, array>`; `::dealTypeIds(): array<string, int>` (slug ⇒ id); `::phoneFiltersEnabled(): bool`; биндинг — синглтон через контейнер (`app(RealEstateClient::class)`).

- [ ] **Step 1: Падающие тесты**

`tests/Feature/RealEstate/RealEstateClientTest.php`:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\RealEstate\Exceptions\RealEstateAccessDenied;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use App\RealEstate\RealEstateClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class RealEstateClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
    }

    private function client(): RealEstateClient
    {
        return app(RealEstateClient::class);
    }

    public function test_listings_page_with_cursor_and_key_and_language(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing()], next: 'N1', prev: 'P1')]);

        $page = $this->client()->listings(['deal_type_id' => '1'], 'CUR');

        $this->assertSame(13, $page->listings[0]->id);
        $this->assertSame('N1', $page->nextCursor);
        $this->assertSame('P1', $page->prevCursor);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?')
            && $r['cursor'] === 'CUR' && $r['deal_type_id'] === '1' && $r['lang'] === app()->getLocale()
            && $r->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function test_listing_404_is_null(): void
    {
        RealEstateFake::fake();

        $this->assertNull($this->client()->listing(999));
    }

    public function test_listing_found(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);

        $this->assertSame(13, $this->client()->listing(13)?->id);
    }

    /** @return array<string, array{int, array<string, mixed>, array<string, string>, class-string}> */
    public static function errors(): array
    {
        return [
            '401' => [401, ['error' => ['code' => 'token_revoked']], [], RealEstateAccessDenied::class],
            '403' => [403, ['error' => ['code' => 'client_disabled']], [], RealEstateAccessDenied::class],
            '429' => [429, ['error' => ['code' => 'rate_limited']], ['Retry-After' => '17'], RealEstateRateLimited::class],
            '422' => [422, ['error' => ['code' => 'validation_failed', 'fields' => ['x' => ['bad']]]], [], RealEstateBadRequest::class],
            '503' => [503, ['error' => ['code' => 'server_error']], [], RealEstateUnavailable::class],
        ];
    }

    /** Отдельный случай на каждый код: Http::fake в цикле копит подмены, и побеждает первая. */
    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function test_errors_map_to_exceptions(int $status, array $body, array $headers, string $exception): void
    {
        Http::fake(['*' => Http::response($body, $status, $headers)]);

        try {
            $this->client()->listings([], null);
            $this->fail("{$status} не бросил исключение");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
            if ($e instanceof RealEstateRateLimited) {
                $this->assertSame(17, $e->retryAfter);
            }
        }
    }

    public function test_connection_error_is_unavailable(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);

        $this->expectException(RealEstateUnavailable::class);
        $this->client()->listings([], null);
    }

    public function test_dictionaries_are_cached_listings_are_not(): void
    {
        RealEstateFake::fake([RealEstateFake::page([])]);

        $this->client()->dealTypeIds();
        $this->client()->dealTypeIds();
        $this->client()->listings([], null);
        $this->client()->listings([], null);

        Http::assertSentCount(3); // 1 справочник + 2 списка
        $this->assertSame(['sale' => 1, 'rent' => 2, 'daily_rent' => 3, 'pledge' => 4], $this->client()->dealTypeIds());
    }

    public function test_key_never_appears_in_exception_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'token_invalid']], 401)]);

        try {
            $this->client()->listings([], null);
        } catch (RealEstateAccessDenied $e) {
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        }
    }
}
```

- [ ] **Step 2: Убедиться, что падают** — `--filter=RealEstateClientTest` → FAIL.

- [ ] **Step 3: Настройки**

В `config/services.php` в блок `'real_estate'` добавить:

```php
        // Фильтры phones / exclude_phones есть в API с ветки Real Estate feature/listings-api;
        // на проде включать после её выкладки (неизвестный параметр API отвергает 422).
        'phone_filters' => (bool) env('REAL_ESTATE_PHONE_FILTERS', false),
```

В `.env.example` после `REAL_ESTATE_API_KEY=` добавить строку `REAL_ESTATE_PHONE_FILTERS=false`; в локальный `.env` — `REAL_ESTATE_PHONE_FILTERS=true`. Затем `docker compose exec app php artisan config:clear`.

- [ ] **Step 4: Клиент**

`app/RealEstate/RealEstateClient.php`:

```php
<?php

namespace App\RealEstate;

use App\RealEstate\Exceptions\RealEstateAccessDenied;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Тонкий клиент API Real Estate (~/Sites/real-estate.devstudio.ge/docs/RealEstate/16_API.md).
 * Объявления не кешируются (решение «мгновенно»), справочники — сутки.
 */
class RealEstateClient
{
    private const DICTIONARY_TTL = 86400;

    /** @param  array<string, string>  $params */
    public function listings(array $params, ?string $cursor): ListingPage
    {
        $query = $params + ['lang' => app()->getLocale()] + ($cursor !== null ? ['cursor' => $cursor] : []);
        $json = $this->get('/listings', $query)->json();

        return new ListingPage(
            array_values(array_map(fn (array $d): Listing => Listing::fromApi($d, app()->getLocale()), $json['data'] ?? [])),
            $json['meta']['next_cursor'] ?? null,
            $json['meta']['prev_cursor'] ?? null,
        );
    }

    public function listing(int $id): ?Listing
    {
        $response = $this->get("/listings/{$id}", ['lang' => app()->getLocale()], allowNotFound: true);

        return $response === null ? null : Listing::fromApi($response->json('data'), app()->getLocale());
    }

    /**
     * @param  array<string, scalar>  $params
     * @return Collection<int, array<string, mixed>>
     */
    public function dictionary(string $name, array $params = []): Collection
    {
        $key = 'real-estate:dict:'.$name.':'.md5(serialize($params));

        return collect(Cache::remember($key, self::DICTIONARY_TTL, fn (): array => $this->get("/dictionaries/{$name}", $params)->json('data') ?? []));
    }

    /** @return array<string, int> slug ⇒ id */
    public function dealTypeIds(): array
    {
        return $this->dictionary('deal-types')->mapWithKeys(fn (array $t): array => [(string) $t['slug'] => (int) $t['id']])->all();
    }

    /** Заголовок справочной записи на языке интерфейса, иначе грузинский. */
    public static function title(array $row): string
    {
        return (string) (($row['title_'.app()->getLocale()] ?? null) ?: ($row['title_ka'] ?? $row['slug'] ?? $row['id']));
    }

    public function phoneFiltersEnabled(): bool
    {
        return (bool) config('services.real_estate.phone_filters');
    }

    /** @param  array<string, scalar>  $query */
    private function get(string $path, array $query, bool $allowNotFound = false): ?Response
    {
        try {
            $response = $this->http()->get($path, $query);
        } catch (ConnectionException) {
            throw new RealEstateUnavailable('Real Estate: нет связи');
        } catch (RequestException $e) {
            $response = $e->response;
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        return match (true) {
            $response->successful() => $response,
            in_array($response->status(), [401, 403], true) => $this->denied($response),
            $response->status() === 429 => throw new RealEstateRateLimited((int) ($response->header('Retry-After') ?: 60)),
            $response->status() === 422 => $this->badRequest($path, $response),
            default => throw new RealEstateUnavailable("Real Estate: HTTP {$response->status()}"),
        };
    }

    private function denied(Response $response): never
    {
        $code = (string) $response->json('error.code');
        Log::error('Real Estate API: доступ закрыт', ['code' => $code, 'status' => $response->status()]);

        throw new RealEstateAccessDenied("Real Estate: {$code}");
    }

    private function badRequest(string $path, Response $response): never
    {
        Log::error('Real Estate API: 422 — ошибка в запросе GS Home', ['path' => $path, 'fields' => $response->json('error.fields')]);

        throw new RealEstateBadRequest('Real Estate: validation_failed');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.real_estate.url'))
            ->withToken((string) config('services.real_estate.key'))
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 200, fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }
}
```

Если после `retry(..., throw: false)` ответ 5xx приходит как `Response` (без исключения) — ветка `default` превращает его в `RealEstateUnavailable`; если `retry` на последней попытке всё же бросает `RequestException` — его ловит `catch` и разбирает тот же `match`.

- [ ] **Step 5: Тесты** — `--filter=RealEstateClientTest` → PASS (11: 5 случаев ошибок + 6). Затем весь сьют.

- [ ] **Step 6: Анализ и коммит** — `feat(real-estate): API client with error mapping and cached dictionaries`.

---

### Task 4: Метки страницы одним проходом

**Files:**
- Modify: `app/Models/CompanyExchange.php` (`enabledPartnersOf`), `app/Access/OrgAccess.php` (`ownershipMany`, `identifiableCompanyIds`, `identifiablePhones`; `ownership` через `ownershipMany`)
- Create: `app/RealEstate/ListingRows.php`
- Test: `tests/Feature/Access/OwnershipManyTest.php`, `tests/Feature/RealEstate/ListingRowsTest.php`

**Interfaces:**
- Consumes: `Listing` (Task 2), существующие `Ownership`, `OwnershipLabel`, `Phone`, `CompanyExchange`.
- Produces: `CompanyExchange::enabledPartnersOf(int $companyId): list<int>`; `OrgAccess::ownershipMany(User $viewer, array $phonesE164): array<string, Ownership>`; `OrgAccess::identifiableCompanyIds(User $viewer): list<int>`; `OrgAccess::identifiablePhones(User $viewer): list<string>`; `ListingRows::groupOwnership(Ownership $own, Listing $listing, array $resolved): ?Ownership`; `ListingRows::build(User $viewer, array $listings): array<int, array<string, mixed>>` — ключи строки см. Step 4.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Access/OwnershipManyTest.php`:

```php
<?php

namespace Tests\Feature\Access;

use App\Access\OrgAccess;
use App\Enums\OwnershipLabel;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnershipManyTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_single_ownership_for_every_number_and_role(): void
    {
        [$a, $b, $c] = Company::factory()->count(3)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $leadA = User::factory()->teamLead($a)->create();
        $numbers = [];
        foreach ([$a, $b, $c] as $company) {
            $holder = User::factory()->teamLead($company)->create();
            $numbers[] = Phone::factory()->heldBy($holder)->create()->number;
        }
        $numbers[] = '+995599000000'; // не наш

        foreach ([$leadA, User::factory()->companyOwner($a)->create(), User::factory()->owner()->create()] as $viewer) {
            $many = OrgAccess::ownershipMany($viewer, $numbers);
            foreach ($numbers as $n) {
                $this->assertEquals(OrgAccess::ownership($viewer, $n), $many[$n], "{$viewer->role->value} {$n}");
            }
        }
    }

    public function test_page_of_many_numbers_costs_constant_queries(): void
    {
        $a = Company::factory()->create();
        $viewer = User::factory()->teamLead($a)->create();
        $numbers = Phone::factory()->count(20)->create(['company_id' => $a->id])->pluck('number')->all();

        DB::enableQueryLog();
        OrgAccess::ownershipMany($viewer, $numbers);
        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()));
    }

    public function test_identifiable_companies_by_role(): void
    {
        [$a, $b, $c] = Company::factory()->count(3)->create()->all();
        $archived = Company::factory()->archived()->create();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $c->id, 'enabled' => false]);
        $lead = User::factory()->teamLead($a)->create();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], OrgAccess::identifiableCompanyIds($lead));
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], OrgAccess::identifiableCompanyIds(User::factory()->companyOwner($a)->create()));
        $this->assertNotContains($archived->id, OrgAccess::identifiableCompanyIds(User::factory()->owner()->create()));
    }

    public function test_masked_or_empty_numbers_are_other_company(): void
    {
        $viewer = User::factory()->owner()->create();

        $this->assertSame([], OrgAccess::ownershipMany($viewer, [null, '']));
        $this->assertSame(OwnershipLabel::OtherCompany, OrgAccess::ownership($viewer, '555402***')->label);
    }
}
```

`tests/Feature/RealEstate/ListingRowsTest.php`:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\OwnershipLabel;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use App\RealEstate\Listing;
use App\RealEstate\ListingRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingRowsTest extends TestCase
{
    use RefreshDatabase;

    private function listing(array $o): Listing
    {
        return Listing::fromApi(RealEstateFake::listing($o), 'ru');
    }

    public function test_own_label_and_group_label(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $viewer = User::factory()->teamLead($a)->create();
        $ourAgent = User::factory()->agent($viewer)->create(['name' => 'Нино']);
        $our = Phone::factory()->heldBy($ourAgent)->create()->number;

        $rows = ListingRows::build($viewer, [
            $this->listing(['id' => 1, 'contact' => ['phone_e164' => $our]]),
            $this->listing(['id' => 2, 'contact' => ['phone_e164' => '+995599000001'], 'cluster' => ['id' => 9, 'listings_count' => 2, 'phones_e164' => ['+995599000001', $our]]]),
            $this->listing(['id' => 3, 'contact' => ['phone' => '555402***', 'phone_e164' => null]]),
        ]);

        $this->assertSame([1, 2, 3], array_keys($rows));
        $this->assertSame(OwnershipLabel::OurEmployee->getLabel(), $rows[1]['ownership_label']);
        $this->assertSame('Нино', $rows[1]['ownership_name']);
        $this->assertNull($rows[1]['group_label']);
        $this->assertSame(OwnershipLabel::OtherCompany->getLabel(), $rows[2]['ownership_label']);
        $this->assertSame('В группе есть наш сотрудник: Нино', $rows[2]['group_label']);
        $this->assertSame(OwnershipLabel::OtherCompany->getLabel(), $rows[3]['ownership_label']);
        $this->assertNull($rows[3]['group_label']);
    }

    public function test_group_label_picks_most_specific_and_ignores_own_number(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $viewer = User::factory()->teamLead($a)->create();
        $linked = Phone::factory()->heldBy(User::factory()->teamLead($b)->create(['name' => 'Связанный']))->create()->number;
        $ours = Phone::factory()->heldBy(User::factory()->agent($viewer)->create(['name' => 'Свой']))->create()->number;

        $rows = ListingRows::build($viewer, [
            $this->listing(['id' => 1, 'contact' => ['phone_e164' => '+995599000002'], 'cluster' => ['id' => 1, 'listings_count' => 3, 'phones_e164' => ['+995599000002', $linked, $ours]]]),
        ]);

        $this->assertSame('В группе есть наш сотрудник: Свой', $rows[1]['group_label']);
    }

    public function test_group_of_other_companies_only_has_no_group_label(): void
    {
        $viewer = User::factory()->teamLead()->create();
        $foreign = Phone::factory()->heldBy(User::factory()->teamLead()->create())->create()->number; // другая наша компания без обмена

        $rows = ListingRows::build($viewer, [
            $this->listing(['id' => 1, 'contact' => ['phone_e164' => '+995599000003'], 'cluster' => ['id' => 1, 'listings_count' => 2, 'phones_e164' => ['+995599000003', $foreign]]]),
        ]);

        $this->assertNull($rows[1]['group_label']);
    }
}
```

- [ ] **Step 2: Убедиться, что падают** — `--filter="OwnershipManyTest|ListingRowsTest"` → FAIL.

- [ ] **Step 3: Партнёры по обмену и `OrgAccess`**

В `app/Models/CompanyExchange.php` добавить:

```php
    /** @return list<int> компании, с которыми у данной включён обмен (в обе стороны) */
    public static function enabledPartnersOf(int $companyId): array
    {
        return static::query()
            ->where('enabled', true)
            ->where(fn ($q) => $q->where('company_a_id', $companyId)->orWhere('company_b_id', $companyId))
            ->get(['company_a_id', 'company_b_id'])
            ->map(fn (CompanyExchange $p): int => $p->company_a_id === $companyId ? $p->company_b_id : $p->company_a_id)
            ->values()
            ->all();
    }
```

В `app/Access/OrgAccess.php` заменить методы `ownership` и `labelFor` на (импорт `App\Models\Company`):

```php
    /**
     * Метка принадлежности объявления по телефону с него (ТЗ 5.7).
     * Номер не наш, мусор или компания в архиве — «другая компания».
     */
    public static function ownership(User $viewer, ?string $rawPhone): Ownership
    {
        $number = PhoneNumber::tryNormalize($rawPhone);

        return $number === null ? Ownership::other() : self::ownershipMany($viewer, [$number])[$number];
    }

    /**
     * Метки для всех номеров страницы сразу: один запрос к phones, один к парам обмена.
     *
     * @param  array<int, string|null>  $phonesE164  номера уже в E.164 (из API)
     * @return array<string, Ownership> номер ⇒ метка
     */
    public static function ownershipMany(User $viewer, array $phonesE164): array
    {
        $numbers = collect($phonesE164)->filter(fn ($n): bool => is_string($n) && $n !== '')->unique()->values();
        if ($numbers->isEmpty()) {
            return [];
        }

        $phones = Phone::query()->with(['company', 'holder'])->whereIn('number', $numbers)->get()->keyBy('number');
        $partners = $viewer->role->hasTeam() ? CompanyExchange::enabledPartnersOf((int) $viewer->company_id) : [];

        return $numbers->mapWithKeys(fn (string $n): array => [$n => self::ownershipFor($viewer, $phones->get($n), $partners)])->all();
    }

    /** @param  list<int>  $partners */
    private static function ownershipFor(User $viewer, ?Phone $phone, array $partners): Ownership
    {
        if ($phone === null || $phone->company->isArchived()) {
            return Ownership::other();
        }

        $label = match ($viewer->role) {
            Role::Owner, Role::CompanyOwner => OwnershipLabel::GroupCompany,
            Role::TeamLead, Role::Agent => match (true) {
                $phone->company_id === $viewer->company_id => OwnershipLabel::OurEmployee,
                in_array($phone->company_id, $partners, true) => OwnershipLabel::LinkedCompany,
                default => OwnershipLabel::OtherCompany,
            },
        };
        if ($label === OwnershipLabel::OtherCompany) {
            return Ownership::other();
        }

        // Архивный или не назначенный держатель — имени нет; контакт — сам номер:
        // SIM осталась в компании. Заблокированный временно — имя видно.
        $holder = $phone->holder;
        $contactName = $holder !== null && ! $holder->isArchived() ? $holder->name : null;

        return new Ownership($label, $phone->company->name, $contactName, $phone->number);
    }

    /** @return list<int> компании группы, чьих людей смотрящий видит на объявлениях по имени */
    public static function identifiableCompanyIds(User $viewer): array
    {
        $active = Company::query()->whereNull('archived_at');

        if ($viewer->role->hasTeam()) {
            $active->whereIn('id', [(int) $viewer->company_id, ...CompanyExchange::enabledPartnersOf((int) $viewer->company_id)]);
        }

        return $active->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return list<string> номера этих компаний — для фильтров «наши» / «чужие» */
    public static function identifiablePhones(User $viewer): array
    {
        return Phone::query()->whereIn('company_id', self::identifiableCompanyIds($viewer))->pluck('number')->all();
    }
```

(Метод `labelFor` удалить — его логика теперь в `ownershipFor`.)

- [ ] **Step 4: Строки страницы**

`app/RealEstate/ListingRows.php`:

```php
<?php

namespace App\RealEstate;

use App\Access\OrgAccess;
use App\Access\Ownership;
use App\Enums\OwnershipLabel;
use App\Models\User;

/**
 * Строки таблицы «Объекты»: данные объявления + метки смотрящего. Метки всей
 * страницы — одним вызовом ownershipMany (номера объявлений и их групп дублей).
 */
final class ListingRows
{
    private const GROUP_PRIORITY = [OwnershipLabel::OurEmployee, OwnershipLabel::LinkedCompany, OwnershipLabel::GroupCompany];

    /**
     * @param  list<Listing>  $listings
     * @return array<int, array<string, mixed>> id объявления ⇒ строка
     */
    public static function build(User $viewer, array $listings): array
    {
        $numbers = collect($listings)->flatMap(fn (Listing $l): array => [$l->contactPhoneE164, ...$l->clusterPhonesE164])->all();
        $resolved = OrgAccess::ownershipMany($viewer, $numbers);

        $rows = [];
        foreach ($listings as $l) {
            $own = $l->contactPhoneE164 !== null ? ($resolved[$l->contactPhoneE164] ?? Ownership::other()) : Ownership::other();
            $group = self::groupOwnership($own, $l, $resolved);
            $rows[$l->id] = [
                'id' => $l->id,
                'title' => $l->title ?? '—',
                'address' => $l->address,
                'direction' => $l->direction()?->getLabel(),
                'price' => $l->priceLabel(),
                'facts' => $l->facts(),
                'property_type' => $l->propertyType,
                'district' => $l->district,
                'source' => $l->source === 'myhome_ge' ? 'myhome.ge' : 'ss.ge',
                'created_at' => $l->sourceCreatedAt?->format('d.m.Y'),
                'category' => $l->sellerCategory->getLabel(),
                'image' => $l->mainThumb(),
                'ownership_label' => $own->label->getLabel(),
                'ownership_color' => self::color($own->label),
                'ownership_company' => $own->companyName,
                'ownership_name' => $own->contactName,
                'group_label' => $group === null ? null : 'В группе есть наш сотрудник: '.($group->contactName ?? $group->companyName),
            ];
        }

        return $rows;
    }

    /** @param  array<string, Ownership>  $resolved */
    public static function groupOwnership(Ownership $own, Listing $listing, array $resolved): ?Ownership
    {
        if ($own->label !== OwnershipLabel::OtherCompany) {
            return null;
        }

        $candidates = collect($listing->clusterPhonesE164)
            ->reject(fn (string $n): bool => $n === $listing->contactPhoneE164)
            ->map(fn (string $n): ?Ownership => $resolved[$n] ?? null)
            ->filter(fn (?Ownership $o): bool => $o !== null && $o->label !== OwnershipLabel::OtherCompany);

        foreach (self::GROUP_PRIORITY as $label) {
            $found = $candidates->first(fn (Ownership $o): bool => $o->label === $label);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public static function color(OwnershipLabel $label): string
    {
        return match ($label) {
            OwnershipLabel::OurEmployee => 'success',
            OwnershipLabel::LinkedCompany => 'info',
            OwnershipLabel::GroupCompany => 'primary',
            OwnershipLabel::OtherCompany => 'gray',
        };
    }
}
```

- [ ] **Step 5: Тесты** — `--filter="OwnershipManyTest|ListingRowsTest|OwnershipTest|OrgAccessScopeTest"` → PASS (старые тесты меток — без изменений). Затем весь сьют.

- [ ] **Step 6: Анализ и коммит** — `feat(access): page ownership in one pass; duplicate-group label`.

---

### Task 5: Параметры запроса `ListingQuery`

**Files:**
- Create: `app/RealEstate/ListingQuery.php`
- Test: `tests/Feature/RealEstate/ListingQueryTest.php`

**Interfaces:**
- Consumes: `OrgAccess::directions`, `identifiablePhones`, `identifiableCompanyIds`; `RealEstateClient::dealTypeIds()`, `phoneFiltersEnabled()`.
- Produces: `ListingQuery::params(User $viewer, array $filters, ?string $search): ?array` — `array<string, string>` параметров API или `null`, если результат заведомо пуст (запрос не делать). Ключи фильтров (Filament): `direction.value`, `price.min/max`, `location.city_id/district_id`, `area.min/max`, `rooms.values`, `property_type.value`, `source.value`, `owners_only.isActive`, `created.from/until`, `sort.value`, `employee.value`, `ownership.value`. `ListingQuery::SORTS` (значение ⇒ подпись).

- [ ] **Step 1: Падающие тесты**

`tests/Feature/RealEstate/ListingQueryTest.php`:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\Direction;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use App\RealEstate\ListingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        RealEstateFake::fake();
    }

    public function test_renting_agent_gets_only_rent_even_when_asking_for_sale(): void
    {
        $lead = User::factory()->teamLead(direction: Direction::Rent)->create();

        $this->assertSame('2', ListingQuery::params($lead, [], null)['deal_type_id']);
        $this->assertSame('2', ListingQuery::params($lead, ['direction' => ['value' => 'sale']], null)['deal_type_id']);
    }

    public function test_both_directions_and_switcher(): void
    {
        $lead = User::factory()->teamLead()->create(['both_directions' => true]);

        $this->assertSame('1,2', ListingQuery::params($lead, [], null)['deal_type_id']);
        $this->assertSame('2', ListingQuery::params($lead, ['direction' => ['value' => 'rent']], null)['deal_type_id']);
    }

    public function test_filters_map_to_api_parameters(): void
    {
        $owner = User::factory()->owner()->create();

        $p = ListingQuery::params($owner, [
            'price' => ['min' => '50000', 'max' => '120000'],
            'location' => ['city_id' => '1', 'district_id' => '3'],
            'area' => ['min' => '40', 'max' => null],
            'rooms' => ['values' => ['2', '5+']],
            'property_type' => ['value' => '1'],
            'source' => ['value' => 'ss_ge'],
            'owners_only' => ['isActive' => true],
            'created' => ['from' => '2026-10-01', 'until' => '2026-10-09'],
            'sort' => ['value' => 'price_desc'],
        ], 'Ваке');

        $this->assertSame('50000', $p['price_usd_min']);
        $this->assertSame('120000', $p['price_usd_max']);
        $this->assertSame('1', $p['city_id']);
        $this->assertSame('3', $p['district_id']);
        $this->assertSame('40', $p['area_min']);
        $this->assertArrayNotHasKey('area_max', $p);
        $this->assertSame('2,5+', $p['rooms']);
        $this->assertSame('1', $p['property_type_id']);
        $this->assertSame('ss_ge', $p['source']);
        $this->assertSame('1', $p['is_owner']);
        $this->assertSame('2026-10-01', $p['created_since']);
        $this->assertStringStartsWith('2026-10-09T23:59:59', $p['created_until']);
        $this->assertSame('-price_usd', $p['sort']);
        $this->assertSame('Ваке', $p['q']);
        $this->assertSame('25', $p['per_page']);
    }

    public function test_short_search_is_ignored(): void
    {
        $this->assertArrayNotHasKey('q', ListingQuery::params(User::factory()->owner()->create(), [], 'В'));
    }

    public function test_ownership_filters_use_identifiable_phones(): void
    {
        [$a, $b, $c] = Company::factory()->count(3)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $lead = User::factory()->teamLead($a)->create();
        $pa = Phone::factory()->create(['company_id' => $a->id])->number;
        $pb = Phone::factory()->create(['company_id' => $b->id])->number;
        $pc = Phone::factory()->create(['company_id' => $c->id])->number;

        $ours = explode(',', ListingQuery::params($lead, ['ownership' => ['value' => 'ours']], null)['phones']);
        $this->assertEqualsCanonicalizing([$pa, $pb], $ours);
        $this->assertNotContains($pc, $ours);

        $others = ListingQuery::params($lead, ['ownership' => ['value' => 'others']], null);
        $this->assertEqualsCanonicalizing([$pa, $pb], explode(',', $others['exclude_phones']));
    }

    public function test_employee_filter_only_for_identifiable_people(): void
    {
        $a = Company::factory()->create();
        $lead = User::factory()->teamLead($a)->create();
        $colleague = User::factory()->agent($lead)->create();
        $phone = Phone::factory()->heldBy($colleague)->create()->number;
        $stranger = User::factory()->teamLead()->create(); // другая компания без обмена
        Phone::factory()->heldBy($stranger)->create();

        $this->assertSame($phone, ListingQuery::params($lead, ['employee' => ['value' => (string) $colleague->id]], null)['phones']);
        $this->assertNull(ListingQuery::params($lead, ['employee' => ['value' => (string) $stranger->id]], null));
    }

    public function test_ours_without_any_numbers_means_empty_result_without_request(): void
    {
        $lead = User::factory()->teamLead()->create();

        $this->assertNull(ListingQuery::params($lead, ['ownership' => ['value' => 'ours']], null));
    }

    public function test_phone_filters_ignored_when_disabled(): void
    {
        RealEstateFake::configure(phoneFilters: false);
        $lead = User::factory()->teamLead()->create();
        Phone::factory()->heldBy($lead)->create();

        $p = ListingQuery::params($lead, ['ownership' => ['value' => 'ours']], null);
        $this->assertArrayNotHasKey('phones', $p);
    }
}
```

- [ ] **Step 2: Убедиться, что падают** — `--filter=ListingQueryTest` → FAIL.

- [ ] **Step 3: Реализация**

`app/RealEstate/ListingQuery.php`:

```php
<?php

namespace App\RealEstate;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Фильтры экрана → параметры GET /listings. Направление ставит сам по правам
 * смотрящего: выбор на экране только сужает разрешённое (ТЗ 5.6).
 */
final class ListingQuery
{
    public const PER_PAGE = 25;

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
        $set('created_since', $filters['created']['from'] ?? null);
        if (filled($until = $filters['created']['until'] ?? null)) {
            $p['created_until'] = Carbon::parse($until)->endOfDay()->toIso8601String();
        }
        $set('sort', self::SORT_PARAMS[$filters['sort']['value'] ?? ''] ?? null);
        if (mb_strlen(trim((string) $search)) >= 2) {
            $p['q'] = trim((string) $search);
        }

        return $client->phoneFiltersEnabled() ? self::phoneFilters($viewer, $filters, $p) : $p;
    }

    /** @param  array<string, mixed>  $filters */
    private static function dealTypes(User $viewer, array $filters, RealEstateClient $client): string
    {
        $allowed = OrgAccess::directions($viewer);
        $chosen = Direction::tryFrom((string) ($filters['direction']['value'] ?? ''));
        $directions = $chosen !== null && in_array($chosen, $allowed, true) ? [$chosen] : $allowed;
        $ids = $client->dealTypeIds();

        return collect($directions)->map(fn (Direction $d): int => $ids[$d->value]
            ?? throw new \App\RealEstate\Exceptions\RealEstateUnavailable("Real Estate: нет типа сделки {$d->value}"))
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
            $p['phones'] = implode(',', $numbers);
        }

        $ownership = $filters['ownership']['value'] ?? null;
        if ($ownership === 'ours' || $ownership === 'others') {
            $numbers = OrgAccess::identifiablePhones($viewer);
            if ($ownership === 'ours') {
                if ($numbers === []) {
                    return null;
                }
                $p['phones'] = isset($p['phones']) ? $p['phones'] : implode(',', $numbers);
            } elseif ($numbers !== []) {
                $p['exclude_phones'] = implode(',', $numbers);
            }
        }

        return $p;
    }
}
```

- [ ] **Step 4: Тесты** — `--filter=ListingQueryTest` → PASS (8).

- [ ] **Step 5: Анализ и коммит** — `feat(real-estate): listing query with enforced direction and phone filters`.

---

### Task 6: Страница «Объекты» — список, фильтры, ошибки

**Files:**
- Create: `app/Filament/Pages/Listings.php`, `resources/views/filament/pages/listings.blade.php`
- Test: `tests/Feature/Filament/ListingsPageTest.php`

**Interfaces:**
- Consumes: Tasks 1–5.
- Produces: страница `/admin/listings` (`App\Filament\Pages\Listings`), публичное свойство `$display` (`list|grid`, в адресе `?display=`); Task 7 добавит вид «сетка», Task 8 — `recordUrl` на карточку.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Filament/ListingsPageTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\Listings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
    }

    public function test_every_role_opens_the_page(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing()])]);
        $lead = User::factory()->teamLead()->create();

        foreach ([User::factory()->owner()->create(), User::factory()->companyOwner()->create(), $lead, User::factory()->agent($lead)->create()] as $user) {
            $this->actingAs($user)->get('/admin/listings')->assertOk();
        }
    }

    public function test_list_shows_listing_with_label(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing(['title' => ['ru' => 'Светлая квартира']])])]);
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(Listings::class)
            ->assertSee('Светлая квартира')
            ->assertSee('$90 000')
            ->assertSee('Другая компания')
            ->assertSee('Собственник');
    }

    public function test_renting_lead_never_sends_sale_even_with_forged_filter(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->teamLead(direction: Direction::Rent)->create());

        Livewire::test(Listings::class)->filterTable('direction', 'sale');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && $r['deal_type_id'] === '2');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && str_contains((string) $r['deal_type_id'], '1'));
    }

    public function test_direction_filter_only_for_both_directions(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->teamLead()->create());
        Livewire::test(Listings::class)->assertTableFilterHidden('direction');

        $this->actingAs(User::factory()->teamLead()->create(['both_directions' => true]));
        Livewire::test(Listings::class)->assertTableFilterVisible('direction');
    }

    public function test_filter_becomes_api_parameter(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->filterTable('rooms', ['2', '3']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && $r['rooms'] === '2,3');
    }

    public function test_next_page_sends_api_cursor(): void
    {
        RealEstateFake::fake([
            RealEstateFake::page([RealEstateFake::listing(['id' => 1])], next: 'NEXT1'),
            RealEstateFake::page([RealEstateFake::listing(['id' => 2])], prev: 'PREV1'),
        ]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->call('setPage', 'NEXT1', 'page');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && $r['cursor'] === 'NEXT1');
    }

    public function test_unavailable_api_gives_empty_list_and_notice(): void
    {
        Http::fake(['*/dictionaries/*' => Http::response(['data' => RealEstateFake::dealTypes()]), '*' => Http::response([], 503)]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->assertOk()
            ->assertNotified('Real Estate недоступен, попробуйте позже.');
    }

    public function test_phone_filters_hidden_when_disabled(): void
    {
        RealEstateFake::configure(phoneFilters: false);
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->assertTableFilterHidden('ownership')
            ->assertTableFilterHidden('employee');
    }
}
```

- [ ] **Step 2: Убедиться, что падают** — `--filter=ListingsPageTest` → FAIL (нет страницы).

- [ ] **Step 3: Страница**

`resources/views/filament/pages/listings.blade.php`:

```blade
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
```

`app/Filament/Pages/Listings.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Models\Phone;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\ListingQuery;
use App\RealEstate\ListingRows;
use App\RealEstate\Pagination\ApiCursorPaginator;
use App\RealEstate\RealEstateClient;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Объекты — живые запросы к API Real Estate (решение 2026-10-10). Направление —
 * только из прав смотрящего (ListingQuery), метки — ListingRows.
 */
class Listings extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.listings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $navigationLabel = 'Объекты';

    protected static ?string $title = 'Объекты';

    protected static ?int $navigationSort = -1;

    #[Url]
    public string $display = 'list';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (array $filters, ?string $search, int|string $page): ApiCursorPaginator => $this->fetch($filters, $search, $page))
            ->paginationMode(PaginationMode::Cursor)
            ->paginated([ListingQuery::PER_PAGE])
            ->columns($this->listColumns())
            ->filters($this->filters(), layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->emptyStateHeading('Объявлений не найдено')
            ->emptyStateDescription('Измените фильтры или направление.');
    }

    /** @param  array<string, mixed>  $filters */
    public function fetch(array $filters, ?string $search, int|string $page): ApiCursorPaginator
    {
        $options = ['path' => request()->url(), 'cursorName' => $this->getTablePaginationPageName()];
        $cursor = is_string($page) && ! ctype_digit($page) ? $page : null;

        try {
            $params = ListingQuery::params($this->viewer(), $filters, $search);
            if ($params === null) {
                return ApiCursorPaginator::emptyPage(ListingQuery::PER_PAGE, $options);
            }
            $result = app(RealEstateClient::class)->listings($params, $cursor);
        } catch (RealEstateException $e) {
            Notification::make()->danger()->title($e->userMessage())->send();

            return ApiCursorPaginator::emptyPage(ListingQuery::PER_PAGE, $options);
        }

        return new ApiCursorPaginator(
            ListingRows::build($this->viewer(), $result->listings),
            ListingQuery::PER_PAGE,
            $cursor,
            $result->nextCursor,
            $result->prevCursor,
            $options,
        );
    }

    /** @return array<int, TextColumn> */
    private function listColumns(): array
    {
        return [
            TextColumn::make('title')->label('Объект')->description(fn (array $record): ?string => $record['address'])->wrap()->searchable(),
            TextColumn::make('price')->label('Цена'),
            TextColumn::make('direction')->label('Направление')->badge(),
            TextColumn::make('facts')->label('Параметры')->wrap(),
            TextColumn::make('source')->label('Источник'),
            TextColumn::make('created_at')->label('Появилось'),
            TextColumn::make('category')->label('Кто добавил'),
            TextColumn::make('ownership_label')->label('Принадлежность')->badge()
                ->color(fn (array $record): string => $record['ownership_color'])
                ->description(fn (array $record): ?string => collect([$record['ownership_company'], $record['ownership_name'], $record['group_label']])->filter()->implode(' · ') ?: null),
        ];
    }

    /** @return array<int, Filter|SelectFilter> */
    private function filters(): array
    {
        $client = app(RealEstateClient::class);
        $directions = OrgAccess::directions($this->viewer());
        $dictionary = fn (string $name, array $params = []): array => rescue(
            fn (): array => $client->dictionary($name, $params)->mapWithKeys(fn (array $r): array => [$r['id'] => RealEstateClient::title($r)])->all(),
            [],
            report: false,
        );

        return [
            SelectFilter::make('direction')->label('Направление')
                ->options(collect($directions)->mapWithKeys(fn (Direction $d): array => [$d->value => $d->getLabel()])->all())
                ->placeholder('Все')
                ->visible(count($directions) > 1),
            Filter::make('price')->schema([
                TextInput::make('min')->label('Цена от, $')->numeric(),
                TextInput::make('max')->label('Цена до, $')->numeric(),
            ])->columns(2),
            Filter::make('location')->schema([
                Select::make('city_id')->label('Город')->options(fn (): array => $dictionary('cities'))->searchable()->live(),
                Select::make('district_id')->label('Район')
                    ->options(fn (Get $get): array => filled($get('city_id')) ? $dictionary('districts', ['city_id' => (int) $get('city_id')]) : [])
                    ->searchable(),
            ])->columns(2),
            Filter::make('area')->schema([
                TextInput::make('min')->label('Площадь от, м²')->numeric(),
                TextInput::make('max')->label('Площадь до, м²')->numeric(),
            ])->columns(2),
            SelectFilter::make('rooms')->label('Комнаты')->multiple()
                ->options(['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5+' => '5+']),
            SelectFilter::make('property_type')->label('Тип недвижимости')->options(fn (): array => $dictionary('property-types')),
            SelectFilter::make('source')->label('Источник')->options(['myhome_ge' => 'myhome.ge', 'ss_ge' => 'ss.ge']),
            Filter::make('owners_only')->label('Только собственники')->toggle(),
            Filter::make('created')->schema([
                DatePicker::make('from')->label('Появилось с'),
                DatePicker::make('until')->label('Появилось по'),
            ])->columns(2),
            SelectFilter::make('sort')->label('Сортировка')->options(ListingQuery::SORTS)->placeholder('Сначала новые'),
            SelectFilter::make('ownership')->label('Принадлежность')
                ->options(['ours' => 'Наши (видимые мне)', 'others' => 'Другие компании'])
                ->visible($client->phoneFiltersEnabled()),
            SelectFilter::make('employee')->label('Сотрудник')
                ->options(fn (): array => $this->employeeOptions())
                ->searchable()
                ->visible($client->phoneFiltersEnabled()),
        ];
    }

    /** @return array<int, string> люди с номерами в компаниях, которых смотрящий видит по имени */
    private function employeeOptions(): array
    {
        return User::query()
            ->whereIn('id', Phone::query()->whereNotNull('user_id')->whereIn('company_id', OrgAccess::identifiableCompanyIds($this->viewer()))->select('user_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
```

- [ ] **Step 4: Тесты** — `--filter=ListingsPageTest` → PASS (8).

Если `test_next_page_sends_api_cursor` не находит `cursor=NEXT1` — посмотреть, что Filament передаёт в `$page` при `setPage('NEXT1', 'page')` (`dump($page)` в `fetch` при последовательном прогоне `composer test:sequential -- --filter=...`), и привести разбор `$cursor` в `fetch()` к фактическому значению; записать решение в ledger.

- [ ] **Step 5: Анализ и коммит** — `feat(listings): Objects page — list, filters, sorting, cursor paging, API errors`.

---

### Task 7: Вид «Сетка» и переключатель

**Files:**
- Modify: `app/Filament/Pages/Listings.php`
- Test: `tests/Feature/Filament/ListingsPageTest.php` (добавить тесты)

**Interfaces:**
- Consumes: Task 6.
- Produces: действия шапки `showGrid`, `showList`; при `display=grid` — колонки-раскладка `Stack` и `contentGrid`.

- [ ] **Step 1: Падающие тесты** — добавить в `ListingsPageTest`:

```php
    public function test_grid_and_list_show_the_same_records(): void
    {
        RealEstateFake::fake([RealEstateFake::page([
            RealEstateFake::listing(['id' => 1, 'title' => ['ru' => 'Первая']]),
            RealEstateFake::listing(['id' => 2, 'title' => ['ru' => 'Вторая']]),
        ])]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->assertSee('Первая')->assertSee('Вторая')
            ->callAction('showGrid')
            ->assertSet('display', 'grid')
            ->assertSee('Первая')->assertSee('Вторая')
            ->assertSee('https://re.test/storage/1_t.webp', escape: false);
    }

    public function test_display_switch_actions(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->assertActionVisible('showGrid')->assertActionHidden('showList')
            ->callAction('showGrid')
            ->assertActionVisible('showList')->assertActionHidden('showGrid');
    }
```

- [ ] **Step 2: Убедиться, что падают** — `--filter=ListingsPageTest` → 2 FAIL.

- [ ] **Step 3: Реализация** — в `Listings.php` (импорты `Filament\Actions\Action`, `Filament\Tables\Columns\ImageColumn`, `Filament\Tables\Columns\Layout\Stack`, `Filament\Support\Enums\FontWeight`):

В `table()` заменить строку `->columns($this->listColumns())` на:

```php
            ->columns($this->display === 'grid' ? $this->gridColumns() : $this->listColumns())
            ->contentGrid($this->display === 'grid' ? ['md' => 2, 'xl' => 3] : null)
```

Добавить методы:

```php
    protected function getHeaderActions(): array
    {
        return [
            Action::make('showGrid')->label('Сетка')->icon(Heroicon::OutlinedSquares2x2)->color('gray')
                ->visible(fn (): bool => $this->display !== 'grid')
                ->action(fn () => $this->display = 'grid'),
            Action::make('showList')->label('Список')->icon(Heroicon::OutlinedListBullet)->color('gray')
                ->visible(fn (): bool => $this->display === 'grid')
                ->action(fn () => $this->display = 'list'),
        ];
    }

    /** @return array<int, Stack> плитка сетки (ТЗ 4.2) */
    private function gridColumns(): array
    {
        return [
            Stack::make([
                ImageColumn::make('image')->label('')->height(180)->extraImgAttributes(['class' => 'w-full object-cover rounded-lg'])
                    ->defaultImageUrl(null),
                TextColumn::make('price')->weight(FontWeight::Bold)->size('lg'),
                TextColumn::make('direction')->badge(),
                TextColumn::make('address')->color('gray')->wrap(),
                TextColumn::make('facts')->wrap(),
                TextColumn::make('ownership_label')->badge()->color(fn (array $record): string => $record['ownership_color'])
                    ->description(fn (array $record): ?string => collect([$record['ownership_company'], $record['ownership_name']])->filter()->implode(' · ') ?: null),
                TextColumn::make('group_label')->color('success')->size('sm')->wrap(),
            ])->space(2),
        ];
    }
```

- [ ] **Step 4: Тесты** — `--filter=ListingsPageTest` → PASS (10).

- [ ] **Step 5: Анализ и коммит** — `feat(listings): grid view and display switch`.

---

### Task 8: Карточка объекта

**Files:**
- Create: `app/Filament/Pages/ListingView.php`, `resources/views/filament/pages/listing-view.blade.php`
- Modify: `app/Filament/Pages/Listings.php` (`->recordUrl(...)`)
- Test: `tests/Feature/Filament/ListingViewTest.php`

**Interfaces:**
- Consumes: Tasks 2–4, `OrgAccess::directions`.
- Produces: маршрут `/admin/listings/{listing}`, `ListingView::getUrl(['listing' => $id])`; публичное `array $card`; действия шапки `contactOwn`, `contactGroup`.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Filament/ListingViewTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\ListingView;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
    }

    public function test_url_and_route(): void
    {
        $this->assertStringEndsWith('/admin/listings/13', ListingView::getUrl(['listing' => 13]));
    }

    public function test_card_shows_details_and_our_employee(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $phone = Phone::factory()->heldBy($agent)->create()->number;
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['contact' => ['phone_e164' => $phone]])]);
        $this->actingAs($lead);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee('Квартира в Ваке')
            ->assertSee('$90 000')
            ->assertSee('Наш сотрудник')
            ->assertSee('Нино')
            ->assertSee('https://home.ss.ge/13', escape: false)
            ->assertActionVisible('contactOwn')
            ->assertActionHidden('contactGroup');
    }

    public function test_other_company_has_no_contact_action(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee('Другая компания')
            ->assertActionHidden('contactOwn');
    }

    public function test_group_label_and_duplicates_list(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $phone = Phone::factory()->heldBy($agent)->create()->number;
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['cluster' => [
            'id' => 5, 'listings_count' => 2, 'phones_e164' => ['+995555123456', $phone],
            'listings' => [['id' => 55, 'source' => 'ss_ge', 'source_url' => 'https://home.ss.ge/55', 'is_primary' => false,
                'seller' => ['is_owner' => false, 'is_agency' => false, 'agency_name' => null],
                'contact' => ['name' => 'Нино', 'phone' => '555', 'phone_e164' => $phone, 'has_viber' => false, 'has_whatsapp' => false],
                'price' => ['usd' => 89000.0, 'gel' => 240300.0], 'updated_at' => '2026-10-07T10:00:00+00:00']],
        ]])]);
        $this->actingAs($lead);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee('В группе есть наш сотрудник: Нино')
            ->assertSee('https://home.ss.ge/55', escape: false)
            ->assertActionVisible('contactGroup');
    }

    public function test_other_direction_is_404(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]); // продажа
        $this->actingAs(User::factory()->teamLead(direction: Direction::Rent)->create());

        $this->get('/admin/listings/13')->assertNotFound();
    }

    public function test_daily_rent_and_pledge_are_404_even_for_owner(): void
    {
        RealEstateFake::fake(cards: [
            13 => RealEstateFake::listing(['deal_type' => ['id' => 3, 'slug' => 'daily_rent']]),
            14 => RealEstateFake::listing(['id' => 14, 'deal_type' => ['id' => 4, 'slug' => 'pledge']]),
        ]);
        $this->actingAs(User::factory()->owner()->create());

        $this->get('/admin/listings/13')->assertNotFound();
        $this->get('/admin/listings/14')->assertNotFound();
    }

    public function test_missing_listing_is_404(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        $this->get('/admin/listings/999')->assertNotFound();
    }
}
```

Добавить в `ListingsPageTest`:

```php
    public function test_row_links_to_card(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing()])]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->assertSee('/admin/listings/13', escape: false);
    }
```

- [ ] **Step 2: Убедиться, что падают** — `--filter="ListingViewTest|test_row_links_to_card"` → FAIL.

- [ ] **Step 3: Карточка**

`app/Filament/Pages/ListingView.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Access\Ownership;
use App\Enums\Direction;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\ListingRows;
use App\RealEstate\RealEstateClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/** Карточка объекта (ТЗ 4.4). Чужое направление, посуточно, залог, снятое — 404. */
class ListingView extends Page
{
    protected string $view = 'filament.pages.listing-view';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'listing';

    /** @var array<string, mixed> */
    public array $card = [];

    public ?string $error = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/listings/{listing}';
    }

    public function mount(int|string $listing): void
    {
        $viewer = $this->viewer();

        try {
            $l = app(RealEstateClient::class)->listing((int) $listing);
        } catch (RealEstateException $e) {
            $this->error = $e->userMessage();

            return;
        }

        $direction = $l?->direction();
        abort_if($l === null || $direction === null || ! in_array($direction, OrgAccess::directions($viewer), true), 404);

        $numbers = [$l->contactPhoneE164, ...$l->clusterPhonesE164, ...array_column($l->clusterListings, 'phone_e164')];
        $resolved = OrgAccess::ownershipMany($viewer, $numbers);
        $own = $l->contactPhoneE164 !== null ? ($resolved[$l->contactPhoneE164] ?? Ownership::other()) : Ownership::other();
        $group = ListingRows::groupOwnership($own, $l, $resolved);
        $toArray = fn (?Ownership $o): ?array => $o === null ? null : [
            'label' => $o->label->getLabel(), 'color' => ListingRows::color($o->label),
            'company' => $o->companyName, 'name' => $o->contactName, 'phone' => $o->contactPhone,
            'can_contact' => $o->contactPhone !== null,
        ];

        $this->card = [
            'id' => $l->id,
            'title' => $l->title ?? '—',
            'direction' => $direction->getLabel(),
            'price' => $l->priceLabel(),
            'price_gel' => $l->priceGel !== null && $direction === Direction::Sale ? number_format($l->priceGel, 0, '.', ' ').' ₾' : null,
            'negotiable' => $l->negotiable,
            'facts' => $l->facts(),
            'address' => $l->address,
            'district' => collect([$l->city, $l->district])->filter()->implode(', '),
            'map' => $l->lat !== null && $l->lng !== null ? "https://maps.google.com/?q={$l->lat},{$l->lng}" : null,
            'description' => $l->description,
            'images' => $l->images,
            'amenities' => $l->amenities,
            'subway' => $l->subwayStations,
            'seller' => $l->sellerCategory->getLabel().($l->agencyName ? " · {$l->agencyName}" : ''),
            'contact_name' => $l->contactName,
            'contact_phone' => $l->contactPhone,
            'viber' => $l->hasViber,
            'whatsapp' => $l->hasWhatsapp,
            'source' => $l->source === 'myhome_ge' ? 'myhome.ge' : 'ss.ge',
            'source_url' => $l->sourceUrl,
            'created_at' => $l->sourceCreatedAt?->format('d.m.Y'),
            'own' => $toArray($own),
            'group' => $toArray($group),
            'cluster' => array_map(fn (array $m): array => [
                'source' => $m['source'] === 'myhome_ge' ? 'myhome.ge' : 'ss.ge',
                'source_url' => $m['source_url'],
                'seller' => $m['seller_category']->getLabel().($m['agency_name'] ? " · {$m['agency_name']}" : ''),
                'price' => $m['price_usd'] !== null ? '$'.number_format($m['price_usd'], 0, '.', ' ') : '—',
                'ownership' => $toArray($m['phone_e164'] !== null ? ($resolved[$m['phone_e164']] ?? Ownership::other()) : Ownership::other()),
            ], $l->clusterListings),
        ];
    }

    public function getTitle(): string
    {
        return $this->card['title'] ?? 'Объект';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->contactAction('contactOwn', 'own'),
            $this->contactAction('contactGroup', 'group', 'Связаться (группа)'),
            Action::make('source')->label('Исходное объявление')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn (): ?string => $this->card['source_url'] ?? null, shouldOpenInNewTab: true)
                ->visible(fn (): bool => isset($this->card['source_url'])),
        ];
    }

    private function contactAction(string $name, string $key, string $label = 'Связаться'): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedPhone)
            ->visible(fn (): bool => (bool) ($this->card[$key]['can_contact'] ?? false))
            ->modalHeading(fn (): string => (string) ($this->card[$key]['name'] ?? $this->card[$key]['company'] ?? 'Контакт'))
            ->modalDescription(fn (): string => (string) ($this->card[$key]['company'] ?? ''))
            ->modalContent(fn () => view('filament.pages.partials.contact', ['phone' => $this->card[$key]['phone'] ?? null]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть');
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
```

`resources/views/filament/pages/partials/contact.blade.php`:

```blade
<div class="space-y-2 text-sm">
    <a href="tel:{{ $phone }}" class="text-lg font-semibold text-primary-600">{{ $phone }}</a>
    <p class="text-gray-500">Нажмите номер, чтобы позвонить, или скопируйте его.</p>
</div>
```

`resources/views/filament/pages/listing-view.blade.php`:

```blade
<x-filament-panels::page>
    @if ($error)
        <x-filament::section><p>{{ $error }}</p></x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge :color="$card['own']['color']">{{ $card['own']['label'] }}</x-filament::badge>
                <span>{{ collect([$card['own']['company'], $card['own']['name']])->filter()->implode(' · ') }}</span>
                @if ($card['group'])
                    <x-filament::badge :color="$card['group']['color']">В группе есть наш сотрудник: {{ $card['group']['name'] ?? $card['group']['company'] }}</x-filament::badge>
                @endif
            </div>
        </x-filament::section>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                @if ($card['images'])
                    <x-filament::section heading="Фото">
                        <div class="grid grid-cols-2 gap-2 md:grid-cols-3">
                            @foreach ($card['images'] as $image)
                                <a href="{{ $image['url'] }}" target="_blank" rel="noopener">
                                    <img src="{{ $image['url_thumb'] ?? $image['url'] }}" alt="" class="h-40 w-full rounded-lg object-cover" loading="lazy">
                                </a>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif
                @if ($card['description'])
                    <x-filament::section heading="Описание">
                        <p class="whitespace-pre-line">{{ $card['description'] }}</p>
                    </x-filament::section>
                @endif
                @if ($card['cluster'])
                    <x-filament::section heading="Группа дублей">
                        <ul class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($card['cluster'] as $member)
                                <li class="flex flex-wrap items-center gap-3 py-2">
                                    <a href="{{ $member['source_url'] }}" target="_blank" rel="noopener" class="text-primary-600">{{ $member['source'] }}</a>
                                    <span>{{ $member['seller'] }}</span>
                                    <span>{{ $member['price'] }}</span>
                                    <x-filament::badge :color="$member['ownership']['color']">{{ $member['ownership']['label'] }}</x-filament::badge>
                                    <span>{{ collect([$member['ownership']['company'], $member['ownership']['name']])->filter()->implode(' · ') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </x-filament::section>
                @endif
            </div>
            <div class="space-y-6">
                <x-filament::section>
                    <p class="text-2xl font-bold">{{ $card['price'] }}</p>
                    @if ($card['price_gel'])<p class="text-gray-500">{{ $card['price_gel'] }}</p>@endif
                    @if ($card['negotiable'])<p class="text-sm text-gray-500">Торг</p>@endif
                    <p class="mt-2"><x-filament::badge>{{ $card['direction'] }}</x-filament::badge></p>
                </x-filament::section>
                <x-filament::section heading="Характеристики">
                    <dl class="space-y-1 text-sm">
                        <div>{{ $card['facts'] }}</div>
                        <div>{{ $card['district'] }}</div>
                        <div>{{ $card['address'] }} @if ($card['map'])<a href="{{ $card['map'] }}" target="_blank" rel="noopener" class="text-primary-600">на карте</a>@endif</div>
                        @if ($card['amenities'])<div>{{ implode(', ', $card['amenities']) }}</div>@endif
                        @if ($card['subway'])<div>Метро: {{ implode(', ', $card['subway']) }}</div>@endif
                        <div>Появилось: {{ $card['created_at'] ?? '—' }} · {{ $card['source'] }}</div>
                    </dl>
                </x-filament::section>
                <x-filament::section heading="Продавец">
                    <dl class="space-y-1 text-sm">
                        <div>{{ $card['seller'] }}</div>
                        <div>{{ $card['contact_name'] }}</div>
                        <div>{{ $card['contact_phone'] }} @if ($card['viber']) · Viber @endif @if ($card['whatsapp']) · WhatsApp @endif</div>
                    </dl>
                </x-filament::section>
            </div>
        </div>
    @endif
</x-filament-panels::page>
```

В `Listings::table()` добавить:

```php
            ->recordUrl(fn (array $record): string => ListingView::getUrl(['listing' => $record['id']]))
```

- [ ] **Step 4: Тесты** — `--filter="ListingViewTest|ListingsPageTest"` → PASS.

Если `test_url_and_route` даёт другой путь — маршрут страницы строится иначе, чем ожидалось: проверить `php artisan route:list --path=admin/listings` и привести `getRoutePath`/`$slug` к виду `/admin/listings/{listing}`; записать решение в ledger.

- [ ] **Step 5: Анализ и коммит** — `feat(listings): listing card with ownership, contacts and duplicate group`.

---

### Task 9: Документация, проверка вживую, ворота

**Files:**
- Create: `docs/GSHome/08_Объявления.md`
- Modify: `docs/GSHome/00_Навигатор.md`, `CLAUDE.md`

- [ ] **Step 1: Документ** — `docs/GSHome/08_Объявления.md`:

```markdown
---
tags: [gshome, listings, api]
---

# Объявления

Спецификация — `docs/superpowers/specs/2026-10-10-listings-screen-design.md`. Требования к API —
[[07_Требования_к_API_Real_Estate]]. Метки — [[05_Оргструктура]].

## Где что

| что | где |
|---|---|
| клиент API, ошибки → исключения, кеш справочников | `App\RealEstate\RealEstateClient`, `App\RealEstate\Exceptions\*` |
| объявление из ответа API | `App\RealEstate\Listing` (язык с откатом на `ka`, категория продавца, номер E.164) |
| параметры запроса, **направление по правам** | `App\RealEstate\ListingQuery` |
| строки таблицы, метки страницы, вторая метка группы | `App\RealEstate\ListingRows` (+ `OrgAccess::ownershipMany`) |
| курсор API в таблице Filament | `App\RealEstate\Pagination\ApiCursorPaginator` |
| экраны | `App\Filament\Pages\Listings` (`/admin/listings`), `ListingView` (`/admin/listings/{id}`) |

## Правила, которые легко сломать

- Направление в запрос ставит только `ListingQuery` из `OrgAccess::directions()`. Параметр из
  браузера только сужает разрешённое.
- `daily_rent` и `pledge` в GS Home не попадают: список их не запрашивает, карточка отвечает 404.
- Метки страницы — одним вызовом `ownershipMany`; не звать `ownership()` в цикле.
- Фильтры по телефонам — за `REAL_ESTATE_PHONE_FILTERS`: API отвергает неизвестные параметры (422).
- Объявления не кешируются (решение «мгновенно»); справочники — сутки.

## Тестовые данные

`tools/real-estate-demo-listings.php` — 54 объявления в локальной базе Real Estate
(см. [[03_Среда_разработки]]).
```

В `00_Навигатор.md` добавить строку `| 08 | [[08_Объявления]] | Экран «Объекты» и карточка: клиент API, метки, направление |`; в `CLAUDE.md` в таблицу документов — `| 08_Объявления.md | экран объектов поверх API Real Estate; направление — только через ListingQuery |`.

- [ ] **Step 2: Ворота** — `bin/ship.sh` → `ЧИСТО`; инспекции PhpStorm (`mcp__phpstorm__lint_files`) по всем изменённым PHP-файлам — без ошибок (кроме известных «Multiple definitions … _ide_helper» и «whereKeyNot … int|null»).

- [ ] **Step 3: Вживую** — `docker compose up -d web-localhost`; `http://gshome.localhost:8088/admin/listings` под:
  - владельцем (`max+cl@absoluteweb.com`) — переключатель направлений, метки «Одна из наших компаний»;
  - продающим тимлидом Vake (`max+cl.vake.sale.lead1@absoluteweb.com` / `password`) — только продажа, «Наш сотрудник» на объявлениях Vake, «Сотрудник связанной компании» на Saburtalo, вторая метка на группах дублей;
  - сдающим сотрудником Vake — только сдача; карточка продажи по прямой ссылке — 404;
  - сетка и список; фильтры комнат/цены/района; «Наши» / «Другие»; фильтр «Сотрудник»; «Вперёд».
  Найденное — исправить по TDD (тест → фикс), не на глаз.

- [ ] **Step 4: Коммит** — `docs: listings screen knowledge`.
