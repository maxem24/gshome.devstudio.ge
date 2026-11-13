# Quick Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Empty dashboard with a mobile-first quick search (deal, type, place, price, area, bedrooms; mode «Свежий» = sites, «По базе» = Objects screen) plus recent searches; the same bar collapsed above «Объекты».

**Architecture:** Livewire components `QuickSearch` and `RecentSearches` inside a custom Filament `Dashboard`; «Свежий» goes through existing `SiteSearches::start`, «По базе» redirects to `Listings` with `FindListing::baseFilters`. Panels are Alpine (bottom sheet on phones, dropdown from `sm`), Livewire round-trips only on «Готово» / submit.

**Tech Stack:** Laravel 13, Filament 5, Livewire, Alpine, Tailwind 4 (panel theme), PHPUnit 12 in Docker.

**Spec:** `docs/superpowers/specs/2026-11-13-quick-search-design.md`. Independent of the Real Estate plan: `bedrooms` is already accepted by the API.

## Global Constraints

- Tests only in `docker compose run --rm test …` (`php artisan test --filter=X`); analysis `docker compose exec app composer analyse`; full `bin/ship.sh` before PR.
- Rights on the server: direction via `OrgAccess` / `SiteSearchRequest`; «По базе» only for own directions (`FindListing::inOwnDirections`).
- Strings in `lang/{ru,en,ka}/gshome.php` under `quick_search.*` (ka — machine translation, like the rest).
- Price only USD; bedrooms 1, 2, 3, 4, 5+ («5+» → `bedrooms_min: 5` for search jobs, `5+` for `GET /listings`).
- Touch targets ≥ 44 px; works at 375 px without horizontal scroll; dark theme.
- Defaults: mode `fresh`, type «Квартира», city Тбилиси, deal = person's direction; last values in session.

## Review Focus

- Person with one direction: no deal switch, search goes in their direction. (Task 4 `test_single_direction_hides_deal_switch`.)
- Rate limit / Real Estate down on «Свежий»: danger notification, form keeps values. (Task 4 `test_rate_limited_keeps_form`.)
- `/me` fails: no collector warning, search not blocked. (Task 3 `test_me_failure_returns_null`.)
- Form with no place, no type and no price: submit disabled. (Task 4 `test_empty_form_cannot_submit`.)
- «По базе» with the other direction (switch on) → disabled with hint, no redirect. (Task 4 `test_base_mode_other_direction_blocked`.)

---

### Task 1: Dashboard without widgets

**Files:**
- Create: `app/Filament/Pages/Dashboard.php`, `resources/views/filament/pages/dashboard.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (`->pages([Dashboard::class])` → ours; remove `->widgets([AccountWidget, FilamentInfoWidget])`)
- Test: `tests/Feature/Filament/DashboardTest.php`

**Interfaces — Produces:** `App\Filament\Pages\Dashboard extends \Filament\Pages\Dashboard` with `getWidgets(): array` returning `[]`, `$view = 'filament.pages.dashboard'` rendering `@livewire(QuickSearch)` and `@livewire(RecentSearches)` (Tasks 4, 6; until then an empty page). `CrmFeed` class stays; it is no longer on the dashboard.

- [ ] **Step 1:** `test_dashboard_has_no_widgets`: as an agent GET `/admin` → 200, `assertDontSee` CrmFeed heading and Filament info text (`filament.widgets.filament-info` strings), `assertSee(__('gshome.quick_search.title'))` (add the key now).
- [ ] **Step 2:** run → FAIL. **Step 3:** implement. **Step 4:** run with `AdminPanelAccessTest` → PASS.
- [ ] **Step 5:** commit `feat(dashboard): hide widgets`.

### Task 2: Bedrooms in search jobs and on «Объекты»

**Files:**
- Modify: `app/RealEstate/SiteSearchRequest.php` (`build`), `app/RealEstate/ListingQuery.php`, `app/Filament/Pages/Listings.php` (filter), `app/Livewire/FindListing.php` (`baseFilters`)
- Test: `tests/Feature/Search/SiteSearchesTest.php` (or the existing SiteSearchRequest test), `tests/Feature/Filament/ListingsPageTest.php`, `tests/Feature/Filament/FindListingTest.php`

**Interfaces — Produces:** form key `bedrooms` (list of `'1'..'4','5'` where `'5'` = 5+); `SiteSearchRequest::build` → `bedrooms` / `bedrooms_min` by the same rule as private `rooms()` (generalise it to `counts(string $key, list<int>)`); Listings `SelectFilter::make('bedrooms')` options `1,2,3,4,5+`; `ListingQuery` sends API `bedrooms=2,3` / `5+` (encode `+` as `%2B`, same handling as `rooms`); `FindListing::baseFilters` copies `bedrooms` to `filters[bedrooms][values]`.

- [ ] **Step 1:** Tests: `test_bedrooms_five_plus_becomes_bedrooms_min` (`['bedrooms'=>['5']]` → body `bedrooms_min: 5`), `test_bedrooms_mixed` (`['2','5']` → `bedrooms: [2,5,6,7,8,9,10]`), Listings `test_bedrooms_filter_reaches_api` (`Http::fake`, assert query `bedrooms=2,5%2B`).
- [ ] **Step 2–4:** FAIL → implement → PASS (`--filter='SiteSearches|ListingsPage|FindListing'`).
- [ ] **Step 5:** analyse; commit `feat(search): bedrooms filter`.

### Task 3: Collectors online signal

**Files:**
- Modify: `app/RealEstate/RealEstateClient.php`
- Test: `tests/Feature/RealEstate/RealEstateClientTest.php`

**Interfaces — Produces:** `public function collectorsOnline(): ?bool` — `true` if `GET /me` → `refresh_sources` non-empty, `false` if empty array, `null` on any failure or missing field; cache key `real-estate:me:collectors` 60 s (failure cached 60 s as `null`). Reuse the `/me` call path of `importSources()`; do not change its 10-min cache.

- [ ] **Step 1:** tests `test_collectors_online_true_false`, `test_me_failure_returns_null` (`Http::fake` 500 → `null`, no exception).
- [ ] **Step 2–4:** FAIL → implement → PASS. **Step 5:** commit `feat(realestate): collectors online signal`.

### Task 4: `QuickSearch` component — behaviour

**Files:**
- Create: `app/Livewire/QuickSearch.php`, `resources/views/livewire/quick-search.blade.php` (minimal markup here, styled in Task 5)
- Test: `tests/Feature/Livewire/QuickSearchTest.php`

**Interfaces — Consumes:** `SiteSearches::start(User, array): SearchStart`, `MySearches::notifyStart(SearchStart)`, `FindListing::normalizeDeal`, `FindListing::inOwnDirections`, `FindListing::baseFilters`, `DictionaryOptions`, `RealEstateClient::collectorsOnline()`.
**Produces:**
```php
final class QuickSearch extends Component {
    public string $mode = 'fresh';            // 'fresh'|'base'
    public bool $compact = false;             // true above «Объекты»
    public ?string $deal = null;              // Direction value
    public ?int $property_type_id = null; public ?int $city_id = null;
    /** @var list<int> */ public array $district_id = []; /** @var list<int> */ public array $subdistrict_id = [];
    public ?int $price_usd_min = null; public ?int $price_usd_max = null; public ?int $area_min = null; public ?int $area_max = null;
    /** @var list<string> */ public array $bedrooms = [];
    public function submit(): void;           // fresh → start; base → redirect
    public function useBase(): void;          // mode = 'base' (from the collector warning)
    public function canSubmit(): bool;        // place or type or price set
    /** @return array<string, mixed> */ public function formData(): array; // keys as FindListing form + bedrooms
    public function ssWillSkip(): bool;       // no type or no city/district
}
```
Session key `quick_search.last` stores `formData()` + `mode` after submit; `mount()` restores it, else defaults (type «Квартира» id from `DictionaryOptions`, city Tbilisi id, deal from `normalizeDeal([], directions)`). Errors on start mirror `FindListing::runSearch` (`CrmForbidden` → 403, `RealEstateRateLimited` → `gshome.search.rate_limited`, `RealEstateException` → `userMessage()`), always a danger `Notification`, state kept.

- [ ] **Step 1:** Tests: `test_fresh_starts_site_search_with_body` (fake `POST /search-jobs`, assert body has `bedrooms`, `area_min`, `price_usd_max`, `district_id`), `test_base_redirects_to_listings_without_post` (assert redirect URL contains `filters[bedrooms][values]` and no HTTP POST recorded), `test_base_mode_other_direction_blocked`, `test_single_direction_hides_deal_switch`, `test_rate_limited_keeps_form`, `test_empty_form_cannot_submit`, `test_last_values_restored_from_session`, `test_collectors_offline_shows_warning` (fake `/me` `refresh_sources: []` → `assertSee(__('gshome.quick_search.collectors_offline'))`).
- [ ] **Step 2–4:** FAIL → implement → PASS (`--filter=QuickSearchTest`).
- [ ] **Step 5:** analyse; commit `feat(search): quick search component`.

### Task 5: `QuickSearch` look — mobile first

**Files:**
- Modify: `resources/views/livewire/quick-search.blade.php`, `resources/css/filament/admin/theme.css` (add `@source '../../../../resources/views/livewire/**/*';` and `@source '../../../../app/Livewire/**/*';`), `lang/{ru,en,ka}/gshome.php`
- Create (if it keeps the view readable): `resources/views/components/quick-search/sheet.blade.php` (one panel: bottom sheet below `sm`, dropdown from `sm`)

Layout per the spec sketch: mode segmented control (top); deal segmented control (only with two directions); type select; place button opening a sheet with searchable city → district → subdistrict (same server search as `FindListing` via `DictionaryOptions::search`); chips «Цена», «Площадь», «Спальни» (`rounded-full`, filled chip shows value: «до $90 000», «50–90 м²», «2, 3 сп.»); full-width primary button («Искать на сайтах» / «Показать»), caption under it («myhome.ge и ss.ge · 1–3 мин» / «сразу, уже собранное»); grey ss.ge hint when `ssWillSkip()`; amber collector warning with «Искать по базе» (`wire:click="useBase"`). Price presets: sale `50 000 / 100 000 / 150 000`, rent `500 / 800 / 1 200` (max bound). Area presets `до 50 / 50–80 / 80–120 / от 120`. Sheet: `fixed inset-x-0 bottom-0`, `pb-[env(safe-area-inset-bottom)]`, backdrop, focus trap (`x-trap` if Alpine Focus is loaded by Filament, else manual), Esc closes; «Сбросить» / «Готово» — «Готово» does `$wire.set` for that panel's fields once. `md+`: everything in one row like myhome. `compact` mode: one summary line («🔍 Ваке · Квартира · до $90к»), tap expands.

- [ ] **Step 1:** `docker compose run --rm node npm run build` → builds.
- [ ] **Step 2:** Check in browser at http://gshome.localhost:8088 (agent login per memory note) — 375, 768, 1280 widths; then `visual-audit` skill on `/admin` and `/admin/listings` at 375/768/1280; fix overflow and overlaps it reports.
- [ ] **Step 3:** iOS Simulator (iPhone 16 iOS26): open sheet, focus «от» — keyboard does not cover the field, sheet scrolls; screenshot.
- [ ] **Step 4:** `QuickSearchTest` still PASS; commit `feat(search): quick search mobile layout`.

### Task 6: Recent searches and the bar above «Объекты»

**Files:**
- Create: `app/Livewire/RecentSearches.php`, `resources/views/livewire/recent-searches.blade.php`
- Modify: `resources/views/filament/pages/dashboard.blade.php`, `resources/views/filament/pages/listings.blade.php` (insert `@livewire(QuickSearch::class, ['compact' => true])` above the scope buttons)
- Test: `tests/Feature/Livewire/RecentSearchesTest.php`, `tests/Feature/Filament/ListingsPageTest.php`

**Interfaces — Produces:** `RecentSearches::searches(): Collection<int, ListingSearch>` — 3 latest of the current user (`user_id = auth id`, newest first); each row: `FiltersSummary::make($s->filters, $user)`, status (`isRunning()` → «ищем…», else `shown()` count), link `SearchResult::getUrl(['search' => $id])`. Root element has `wire:poll.10s` only when any row `isRunning()`.

- [ ] **Step 1:** Tests: `test_shows_three_latest_own_searches` (4 own + 1 colleague → 3 own), `test_polls_only_while_running` (`assertSeeHtml('wire:poll.10s')` / `assertDontSeeHtml`), Listings `test_listings_page_has_compact_quick_search`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** commit `feat(dashboard): recent searches; quick search on listings`.

### Task 7: Docs and ship

**Files:**
- Modify: `docs/GSHome/12_Поиск_на_сайтах.md` («Где что»: QuickSearch, RecentSearches, collectorsOnline; правило «По базе — только свои направления»), `docs/GSHome/08_Объявления.md` (фильтр спален), `docs/GSHome/02_Решения.md` (13.11: главная без виджетов; режимы «Свежий / По базе»; предупреждение о сборщиках — что, почему, чем платим)

- [ ] **Step 1:** write docs.
- [ ] **Step 2:** PhpStorm `get_file_problems` on every changed PHP file → no errors.
- [ ] **Step 3:** `bin/ship.sh` → green; commit `docs(search): quick search`.
