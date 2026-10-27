# Поиск на сайтах и кнопка «Найти квартиру» — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Агент из любого места админки задаёт фильтры, смотрит базу или запускает поиск на myhome.ge и ss.ge через сборщика Real Estate; результат приходит в колокольчик и на экран «Мои поиски».

**Architecture:** `RealEstateClient` получает два метода API поиска; `App\Search\SiteSearches` ставит поиск, хранит его в `listing_searches` и опрашивает Real Estate фоновой задачей `PollListingSearch` (очередь, отложенный повтор). UI: кнопка в шапке (render hook) с Livewire-окном, экраны «Мои поиски» и «Результат поиска». Права — только `OrgAccess`.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, PostgreSQL, Horizon, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-10-27-site-search-design.md`. API Real Estate: `~/Sites/real-estate.devstudio.ge/docs/RealEstate/16_API_поиск.md` (запрос, ответ, статусы, `sites`, `partial`), фильтры — `16_API_фильтры.md`.

## Global Constraints

- Всё в Docker: `docker compose exec app …`; тесты — только `docker compose run --rm test php artisan test --filter=<Имя>`; полный сьют — один раз в конце задачи.
- После каждой задачи: `docker compose exec app composer analyse` — 0 ошибок; `docker compose exec app vendor/bin/pint --dirty`.
- Тесты не ходят в сеть — `Http::fake` через `Tests\Support\RealEstateFake` (расширить хелперами для `search-jobs`).
- Права — только `OrgAccess` (`directions`, `visibleUsers`, `canSee`); каждое действие Livewire перепроверяет права на сервере; параметр замыкания с запросом называется `$query`.
- Направление (`deal_type_id`) в запрос к Real Estate ставит GS Home из `OrgAccess::directions`, не форма.
- Копии карточек не храним: только id найденных объявлений.
- Ничего не удаляется: `SoftDeletes`; журнал — только `App\Crm\Journal`.
- Подписи — `lang/{ru,en,ka}/gshome.php`, ключи `search.*`.
- Ветка `feat/site-search`; коммит заканчивается строкой `Co-Authored-By: Claude <модель> 5.5 <noreply@anthropic.com>` той модели, что писала.

## Review Focus

1. Агент команды продажи подделывает в форме сделку «сдача» — в Real Estate уходит только продажа (или 403/ошибка формы), поиск не создаётся с чужим направлением. Тест — Task 1.
2. Real Estate отвечает 422 `filters_required`, 429, 500/таймаут — понятное сообщение, запись не создаётся (или создаётся `failed` без бесконечного опроса), без 500. Тесты — Task 1, Task 2.
3. Опрос не крутится вечно: 30 минут — и `timed_out`; опрос уже законченного поиска не продолжается; два одновременных запуска задачи не шлют два уведомления. Тесты — Task 2.
4. Чужой поиск по подделанному id: агент открывает результат поиска коллеги — 404/403. Тест — Task 4.
5. Найденное объявление чужого направления (Real Estate вернул) или снятое — на экране результата не показывается, без падения. Тест — Task 4.

## Модели

| задачи | модель |
|---|---|
| 1–4 | Sonnet |
| 5 (документация) | Haiku |
| итоговое ревью ветки | Opus |

---

### Task 1: Клиент API поиска и сборка тела запроса

**Files:** Modify `app/RealEstate/RealEstateClient.php`; Create `app/RealEstate/SiteSearchStatus.php`, `app/RealEstate/SiteSearchRequest.php`, `app/RealEstate/Exceptions/RealEstateFiltersRequired.php`; Modify `tests/Support/RealEstateFake.php`; Test `tests/Feature/RealEstate/SiteSearchClientTest.php`.

**Interfaces:**
- `SiteSearchRequest::build(User $viewer, array $form): array` — тело JSON для `POST /search-jobs`: списки массивами (`district_id: [..]`, `rooms: [..]`), числа числами; `deal_type_id` — из `OrgAccess::directions($viewer)` (если в форме выбрана сделка, которой нет у человека, — `CrmForbidden`/`InvalidArgumentException`); не кладёт `phones`, `sort`, `cursor`, `per_page`, `source`, `lang`, пустые поля. Поля формы: `deal` (`sale|rent|null`), `city_id`, `district_id[]`, `subdistrict_id[]`, `property_type_id`, `price_usd_min`, `price_usd_max`, `rooms[]`, `area_min`, `area_max`. Id типов сделки — как в `ListingQuery::dealTypes` (справочник `deal-types` по slug).
- `RealEstateClient::startSearch(array $body): SiteSearchStatus` — `POST /search-jobs` с `Content-Type: application/json`; 422 с кодом `filters_required` → `RealEstateFiltersRequired`; остальные ошибки — как у `listings()` (существующие исключения, пометка «недоступен»).
- `RealEstateClient::searchStatus(string $id): SiteSearchStatus` — `GET /search-jobs/{id}?lang=…`; 404 → `null`-safe: бросить `RealEstateBadRequest`.
- `SiteSearchStatus` readonly: `id`, `status`, `message`, `found`, `truncated`, `sites` (`array<string, array{status, found, pages_done, message, until?}>`), `listingIds` (`list<int>` из `listings[].id`), `isFinished(): bool` (`done|failed|cancelled`).

- [ ] Тесты: тело запроса (массивы, направление агента продажи — только sale, подделка `deal=rent` → исключение, owner без выбора — оба типа); 202 → статус `queued`; сразу `done` из кеша → `listingIds`; 422 `filters_required` → исключение; 429 → `RealEstateRateLimited`; `GET` `done` с `sites` и `listings`.
- [ ] FAIL → реализация → PASS + analyse + pint → коммит `feat(search): Real Estate search-jobs client`.

### Task 2: Поиски, опрос и уведомление

**Files:** Create migration `…_create_listing_searches_table.php`, `app/Models/ListingSearch.php`, `app/Search/SiteSearches.php`, `app/Jobs/PollListingSearch.php`, `app/Notifications/SiteSearchFinished.php`; Modify `app/Crm/Journal.php` (`SEARCH_STARTED = 'search.started'`, `SEARCH_FINISHED = 'search.finished'`, не в FEED_EVENTS); Test `tests/Feature/Search/SiteSearchesTest.php`.

`listing_searches`: `user_id`, `filters jsonb` (форма), `body_hash` (sha1 тела запроса — для повтора), `remote_id uuid null`, `status` (`queued|running|done|failed|cancelled|timed_out`), `message`, `sites jsonb`, `found int`, `truncated bool`, `listing_ids jsonb`, `started_at`, `finished_at`, `notified_at`, timestamps tz, `deleted_at`.

**Interfaces:**
- `SiteSearches::start(User $by, array $form): ListingSearch` — тело через `SiteSearchRequest::build`; если у того же человека есть поиск с тем же `body_hash` в работе → вернуть его; есть `done` моложе 30 мин → вернуть его (без запроса); иначе `startSearch`, запись, журнал `SEARCH_STARTED`, если не закончен — `PollListingSearch::dispatch($search->id)->delay(10 s)`; если закончен сразу — `finish()`. Исключения клиента пробрасывает (UI покажет).
- `SiteSearches::refresh(ListingSearch $s): void` — `searchStatus`, обновить поля; закончен → `finish()`.
- `SiteSearches::finish(ListingSearch $s): void` — под `lockForUpdate`: если `notified_at` пуст — уведомление `SiteSearchFinished` автору (Filament database format, ссылка на результат — маршрут из Task 4, до него — `null`-safe), журнал `SEARCH_FINISHED`, `notified_at = now`.
- `PollListingSearch(int $searchId)` — `ShouldQueue`; поиск закончен → ничего; старше `config('search.timeout_minutes', 30)` → `timed_out` + уведомление; иначе `refresh`, не закончен → снова себя с задержкой `config('search.poll_seconds', 10)`. Ошибки сети — повтор с той же задержкой, не исключение наружу.
- `ListingSearch::scopeVisibleTo(Builder, User)` — `user_id in OrgAccess::visibleUsers`.

- [ ] Тесты: старт → запись + задача в очереди (`Queue::fake`); повтор с теми же фильтрами во время работы → тот же поиск, без второго POST; свежий `done` → без POST; опрос `done` → уведомление один раз (два вызова `finish` → одно); тайм-аут 30 мин → `timed_out` + уведомление; закрытый поиск не опрашивается; сетевая ошибка → повтор без исключения.
- [ ] FAIL → реализация → PASS + analyse + pint → коммит `feat(search): background site search with polling and bell`.

### Task 3: Кнопка «Найти квартиру» в шапке и окно поиска

**Files:** Create `app/Livewire/FindListing.php` (или Filament-действие в render hook `PanelsRenderHook::TOPBAR_END` / `USER_MENU_BEFORE`), view, регистрация хука в `AdminPanelProvider`; Test `tests/Feature/Filament/FindListingTest.php`.

- Форма (поля из Task 1); справочники — `RealEstateClient::dictionary()` как в `app/Filament/Pages/Listings.php` (`location`: город → район → подрайон; `property-types`). Поле «сделка» видно только тем, у кого больше одного направления (`OrgAccess::directions`). Подсказка про ss.ge (спецификация).
- «Показать в базе» → редирект на `Listings` с `tableFilters` в формате экрана (см. `Listings.php` строки ~145–157, 522–560: `price.min/max`, `location.city_id/district_id`, `area.min/max`, `rooms`, `property_type`, `direction`).
- «Искать на сайтах» → `SiteSearches::start`; успех → уведомление «Поиск запущен…» и закрыть окно; `RealEstateFiltersRequired` / `RealEstateRateLimited` / `RealEstateException` → уведомление с понятным текстом, окно не закрывать.
- [ ] Тесты: кнопка есть в шапке для всех ролей; «Показать в базе» даёт правильный URL; «Искать» вызывает старт (фейк API) и создаёт запись; 422 → текст «Сузьте поиск…»; агент продажи не видит поля «сделка».
- [ ] FAIL → реализация → PASS + analyse + pint → коммит `feat(search): find-listing button and search dialog`.

### Task 4: «Мои поиски» и экран результата

**Files:** Create `app/Filament/Pages/MySearches.php` (+ blade), `app/Filament/Pages/SearchResult.php` (маршрут `/searches/{search}`, + blade); Modify `app/Notifications/SiteSearchFinished.php` (ссылка на результат); Test `tests/Feature/Filament/MySearchesTest.php`.

- «Мои поиски» (меню CRM): `ListingSearch::visibleTo`, новые сверху; колонки — человек (для руководителей), фильтры коротко, статус и `message`, сайты (`sites.*.message`), найдено, время; кнопка «Искать снова» (тот же `filters` → `SiteSearches::start`, права — только автор).
- Результат: доступ — `visibleTo` (иначе 404). Карточки по `listing_ids` (до 100) через `RealEstateClient::listingsByIds` (или фильтр `ids`), метки — `ListingRows::build($viewer, …)`; объявления не того направления (`OrgAccess::directions`) и снятые — не показываются, внизу «скрыто N». `partial` — пометка. Шапка — общий статус и сайты; пока поиск идёт — `wire:poll.10s` перечитывает запись (не API).
- [ ] Тесты: агент видит только свои поиски, тимлид — своих агентов; чужой id результата → 404; результат показывает карточки и скрывает чужое направление; идущий поиск показывает статус; «Искать снова» создаёт новый (или возвращает идущий).
- [ ] FAIL → реализация → PASS + analyse + pint → полный сьют → коммит `feat(search): my searches and results`.

### Task 5: Документация — Haiku

Create `docs/GSHome/12_Поиск_на_сайтах.md` (где что, правила, ограничения: 60 поисков/ч на ключ, 30 мин ожидания, сборщики — компьютеры агентов); строка в `00_Навигатор.md`; решение 2026-10-27 в `02_Решения.md` (поиск по кнопке в фоне, опрос вместо webhook, кеш 30 мин у себя, ссылка — позже, нужен API в Real Estate). Коммит `docs(search): site search guide and decisions`.

### Task 6: Сдача

- [ ] Инспекции PhpStorm по изменённым файлам; итоговое ревью ветки (Opus); `bin/ship.sh`.
