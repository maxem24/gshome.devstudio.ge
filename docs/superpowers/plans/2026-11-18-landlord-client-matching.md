# Подбор пар «собственник ↔ клиент» — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** на объекте аренды в работе показывать «Вероятные клиенты», на клиенте — «Вероятные предложения», по убыванию процента совпадения.

**Architecture:** данные квартиры (цена, комнаты, микрорайон, заголовок) копируются из API в `listing_pins`. Процент считает один SQL-запрос `clients × listing_pins` в `App\Crm\Matching` при открытии карточки. PHP только переводит признаки из строки результата в текст несовпадений.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL 17 (jsonb), PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-11-18-landlord-client-matching-design.md`

## Global Constraints

- Только аренда: `listing_pins.direction = 'rent'`, `status = 'active'`, `deleted_at IS NULL`.
- Клиенты-кандидаты: `housing = 'looking'` и `deleted_at IS NULL` всегда; `Archive::scope` в подборе не используется.
- Веса: район 30, цена 25, кто будет жить 20, животные 10, комнаты 10, дом 5, люди 5 (сумма 105). Константы `Matching::WEIGHTS`.
- Цена: в `[price_min, price_max]` (пустая граница открыта) — 1; за границей не больше чем на 10% от неё — 0.5; дальше — 0.
- Комнаты: в `[rooms_min, rooms_max]` — 1; на одну мимо — 0.5; дальше — 0.
- Критерий учитывается, только если обе стороны его заполнили; процент = `round(100 × Σ вес×hit / Σ вес×known)`.
- «Мало данных»: Σ вес×known < 40. Сортировка: `enough DESC, score DESC NULLS LAST, id DESC`; лимит 10.
- Число из jsonb приводится только при `jsonb_typeof(...) = 'number'`, иначе критерий неизвестен.
- Противоположные пункты «Дома»: `furnished`↔`unfurnished`, `new_build`↔`old_building`, `renovated`/`old_renovation`/`no_renovation` попарно исключают друг друга; `pets` — отдельный критерий «Животные».
- Комментарий собственника (`preferences.note`) нигде, кроме его объекта и только для `Pins::canManage`, не выводится.
- Тесты — только `docker compose run --rm test …`; после пачки правок — `docker compose exec app composer analyse`; перед сдачей — инспекции PhpStorm по изменённым файлам и `bin/ship.sh`.
- Переводы — во все три `lang/{ru,en,ka}/gshome.php`.

## Review Focus

1. У объявления нет микрорайона или цены (API отдал `null`) → эти критерии неизвестны, а пара всё равно в списке (тест в Task 3).
2. У клиента только `price_max` (нет `price_min`) → нижняя граница открыта: $300 при max $600 — полное совпадение (тест в Task 3).
3. Собственник не заполнил предпочтения совсем → остаются район/цена/комнаты из объявления, «кто будет жить» и «животные» не штрафуют (тест в Task 3).
4. В профиле строка вместо числа (`"price_max": "600$"`, старые данные) → запрос не падает, критерий неизвестен (тест в Task 3).
5. Кандидатов нет → секция показывает «Подходящих пока нет», а не пустой блок и не 500 (тест в Task 4).

---

### Task 1: Снимок квартиры на `listing_pins`

**Files:**
- Modify: `app/RealEstate/Listing.php` (поле `?int $subdistrictId` из `$d['subdistrict']['id']`)
- Create: `database/migrations/2026_11_18_130000_add_snapshot_to_listing_pins.php`
- Modify: `app/Models/ListingPin.php` (fillable, casts, `@property`)
- Modify: `app/Crm/Pins.php` (`snapshot()`, вызов в `take()`)
- Modify: `app/Filament/Pages/ListingView.php` (`mount()` — обновить снимок активного пина)
- Create: `app/Console/Commands/SnapshotPins.php`; Modify: `routes/console.php`
- Test: `tests/Feature/Crm/PinSnapshotTest.php`, `tests/Feature/Console/SnapshotPinsTest.php`

**Interfaces:**
- Produces: колонки `listing_pins.price_usd numeric(12,2) null`, `rooms smallint null`, `subdistrict_id integer null`, `title varchar null`, `snapshot_at timestampTz null`; частичный индекс `listing_pins_rent_active_idx (id) WHERE status='active' AND direction='rent' AND deleted_at IS NULL`.
- Produces: `Pins::snapshot(ListingPin $pin, Listing $listing): bool` — `true`, если что-то изменилось и записано (тогда же `snapshot_at = now()`).
- Produces: команда `pins:snapshot {--stale= : часов с последнего снимка}`.

- [ ] **Step 1: Тесты.** `PinSnapshotTest`:
  - `test_take_fills_snapshot` — `RealEstateFake::listing(['price' => ['usd' => 600], 'rooms' => 2, 'subdistrict' => ['id' => 101, 'title' => 'Ваке'], 'title' => 'Квартира'])` → после `take()` у пина `price_usd == 600`, `rooms == 2`, `subdistrict_id == 101`, `title == 'Квартира'`, `snapshot_at` не `null`.
  - `test_snapshot_writes_only_on_change` — повторный `snapshot()` с тем же `Listing` возвращает `false` и не меняет `snapshot_at`; с ценой 650 — `true`, `price_usd == 650`.
  - `test_opening_card_refreshes_changed_price` — пин с `price_usd = 600`, API отдаёт 650, `Livewire::test(ListingView::class, ['listing' => 13])` → `price_usd == 650`.
  `SnapshotPinsTest`:
  - `test_fills_stale_rent_pins_and_skips_missing` — два активных `rent`-пина (один `snapshot_at = null`, у второго API 404) и один `sale` → `artisan('pins:snapshot')` успешно; первый заполнен, второй без изменений, в выводе есть его `listing_id`; `sale` не тронут.
  - `test_stale_option_skips_fresh` — пин с `snapshot_at = now()->subHour()` при `--stale=24` не запрашивается (`Http::assertNothingSent()` для его id).
- [ ] **Step 2: Запустить** `docker compose run --rm test php artisan test --filter='PinSnapshotTest|SnapshotPinsTest'` → FAIL.
- [ ] **Step 3: Реализация.** Миграция с колонками и частичным индексом (`DB::statement`). `Pins::take()` вызывает `snapshot()` внутри той же транзакции. `ListingView::mount()` после `buildCard` вызывает `Pins::snapshot()` для активного пина (тот же `Listing`, без второго запроса API). Команда: активные `rent`-пины, `--stale` фильтрует по `snapshot_at`, `RealEstateClient::listing()`, `null`/исключение → строка в выводе и дальше; `usleep(300_000)` между запросами. `routes/console.php`: `Schedule::command('pins:snapshot --stale=24')->dailyAt('04:30');`.
- [ ] **Step 4: Запустить** тот же фильтр → PASS; `composer analyse` → No errors.
- [ ] **Step 5: Commit** `feat(pins): snapshot price, rooms, subdistrict from the listing`.

### Task 2: Комнаты клиента числами и окно собственника без полей объявления

**Files:**
- Modify: `app/Crm/ClientProfile.php` (`KEYS`, `clean()`, `cleanLandlord()`, `linesFor()`)
- Modify: `app/Filament/Resources/Clients/Schemas/ClientForm.php` (`profileFields(bool $landlord = false)`)
- Modify: `app/Filament/Pages/ListingView.php` (вызов `profileFields(landlord: true)`)
- Create: `database/migrations/2026_11_18_131000_split_client_rooms.php`
- Modify: `lang/{ru,en,ka}/gshome.php` (`crm.clients.profile.rooms_min` «Комнат от», `rooms_max` «Комнат до»)
- Test: `tests/Feature/Crm/ClientProfileTest.php` (создать, если нет), `tests/Feature/Filament/ListingViewTest.php`

**Interfaces:**
- Produces: `clients.profile.rooms_min`, `rooms_max` — `int` 1…20; ключа `rooms` больше нет.
- Produces: `ClientForm::profileFields(bool $landlord = false): list<Component>` — при `$landlord` поля `occupants` multiple и нет `subdistrict_ids`, `rooms_min`, `rooms_max`, `price_min`, `price_max`. Параметр `manyOccupants` удаляется.
- Produces: `ClientProfile::cleanLandlord()` возвращает только `occupants`, `people`, `home`, `note`.

- [ ] **Step 1: Тесты.**
  - `test_rooms_range_is_cleaned` — `clean(['rooms_min' => '2', 'rooms_max' => 3])` → `['rooms_min' => 2, 'rooms_max' => 3]`; `rooms_max < rooms_min` → `rooms_max` отброшен; `0` и `21` отброшены.
  - `test_landlord_drops_listing_fields` — `cleanLandlord(['price_max' => 600, 'subdistrict_ids' => [1], 'rooms_min' => 2, 'note' => 'x'])` → `['note' => 'x']`.
  - `test_split_rooms_migration` — профили `{"rooms":"2"}`, `{"rooms":"2-3"}`, `{"rooms":"2–3"}`, `{"rooms":"3+"}`, `{"rooms":"студия"}`, `{"rooms":"много","note":"a"}` → `{rooms_min:2,rooms_max:2}`, `{2,3}`, `{2,3}`, `{rooms_min:3}`, `{1,1}`, `{"note":"a\nКомнат: много"}`; ключа `rooms` нет нигде. (Тест вызывает `up()` миграции напрямую на подготовленных строках.)
  - В `ListingViewTest::test_holder_saves_landlord_preferences_and_sees_them` — окно не содержит поля `price_min` (`->assertActionDataSet`/`assertFormFieldDoesNotExist` в контексте действия).
- [ ] **Step 2: Запустить** `--filter='ClientProfileTest|ListingViewTest|ClientResourceTest'` → FAIL.
- [ ] **Step 3: Реализация.** Миграция — PHP-цикл по `clients` с `profile ? 'rooms'` (данных на 18.11 нет, объём не важен); `down()` собирает строку `"min-max"` обратно. `linesFor()` выводит «Комнат: 2–3» / «Комнат: от 3».
- [ ] **Step 4: Запустить** → PASS; `composer analyse` → No errors.
- [ ] **Step 5: Commit** `feat(clients): rooms as a range; landlord form without listing fields`.

### Task 3: `App\Crm\Matching` — SQL-подбор

**Files:**
- Create: `app/Crm/Matching.php`, `app/Crm/MatchRow.php`
- Create: `database/migrations/2026_11_18_132000_add_looking_index_to_clients.php`
- Modify: `lang/{ru,en,ka}/gshome.php` (`crm.matching.*`)
- Test: `tests/Feature/Crm/MatchingTest.php`

**Interfaces:**
- Consumes: колонки снимка из Task 1; `rooms_min`/`rooms_max` из Task 2; `listing_pins.preferences`.
- Produces: `final readonly class MatchRow { public function __construct(public Client|ListingPin $other, public ?int $score, public bool $enough, public array $mismatches) }` — `$mismatches` — `list<string>`, уже переведённые.
- Produces: `Matching::clientsFor(ListingPin $pin, int $limit = 10): list<MatchRow>` (`$other` — `Client`), `Matching::pinsFor(Client $client, User $viewer, int $limit = 10): list<MatchRow>` (`$other` — `ListingPin` с `holder`); пустой список, если `Direction::Rent` нет в `OrgAccess::directions($viewer)`.
- Produces: `Matching::WEIGHTS = ['place' => 30, 'price' => 25, 'occupants' => 20, 'pets' => 10, 'rooms' => 10, 'home' => 5, 'people' => 5]`, `Matching::MIN_KNOWN = 40`.
- Produces: индекс `clients_looking_idx (id) WHERE housing='looking' AND deleted_at IS NULL`.

Запрос — один приватный метод-построитель, общий для обеих сторон: `FROM clients c CROSS JOIN listing_pins p WHERE <кандидаты> AND (c.id = ? | p.id = ?)`. Для каждого критерия `k` в `SELECT` — `known_k` (0/1) и `hit_k` (0…1); для цены и комнат ещё `price_diff` (насколько вышла за границу, $) и `pin.rooms`. Процент и `enough` считаются в том же запросе. `home` — доля пунктов клиента (без `pets`), для которых собственник отметил этот же пункт; пункт, для которого собственник отметил противоположный, — промах; пункт без отметки ни того ни другого не входит ни в числитель, ни в знаменатель; `known_home = 0`, если таких пунктов нет. PHP строит `$mismatches` только из `known_k = 1 AND hit_k < 1`.

Тексты `crm.matching.*` (ru): `place` «другой район», `price_more` «цена +$:n», `price_less` «цена −$:n», `occupants` «собственник против: :who», `pets` «животные», `rooms` «комнат :n», `home` «нет: :what», `people` «людей больше, чем допускает собственник», `little_data` «мало данных», `clients_title` «Вероятные клиенты», `offers_title` «Вероятные предложения», `empty` «Подходящих пока нет».

- [ ] **Step 1: Тесты** (фабрики `Client::factory()`, пин — через `ListingPin::query()->create([...])` с колонками снимка; хелпер `pin(array $snap, array $prefs = [])`, `looking(array $profile)`):
  - `test_full_match_is_100` — клиент `{subdistrict_ids:[101], price_min:500, price_max:700, occupants:'couple', rooms_min:2, rooms_max:2}`, пин `{subdistrict_id:101, price_usd:600, rooms:2}` + `{occupants:['couple']}` → `score 100`, `enough true`, `mismatches []`.
  - `test_price_within_ten_percent_is_half` — `price_max 600`, пин 650 → `hit` 0.5, `mismatches` содержит «цена +$50».
  - `test_open_lower_bound` — только `price_max 600`, пин 300 → без несовпадения по цене.
  - `test_unknown_does_not_penalize` — у пина `subdistrict_id = null`, `price_usd = null` → в `mismatches` нет района и цены; процент считается по остальным.
  - `test_landlord_without_preferences` — пустые `preferences` → нет текстов «собственник против» и «животные».
  - `test_pets_rejected_when_landlord_filled_home_without_pets` — клиент `home:['pets']`, собственник `home:['furnished']` → «животные»; собственник `home:[]` → нет.
  - `test_opposite_home_items` — клиент `furnished`, собственник `unfurnished` → «нет: С мебелью».
  - `test_little_data_goes_below` — пара A: известен только район (30 < 40, 100%); пара B: 5 из 6 → B выше A, у A `enough false`.
  - `test_string_numbers_do_not_break_query` — `profile->price_max = "600$"` (записать через `DB::table`) → без исключения, по цене несовпадения нет.
  - `test_candidates_filter` — архивный, `renting` и без `housing` клиенты не попадают в `clientsFor`; закрытый, `sale` и удалённый пины не попадают в `pinsFor`.
  - `test_limit_and_order` — 12 клиентов → 10 строк, проценты по убыванию.
  - `test_offers_need_rent_direction` — агент только с продажей → `pinsFor($client, $agent)` пуст.
- [ ] **Step 2: Запустить** `--filter=MatchingTest` → FAIL.
- [ ] **Step 3: Реализация** `Matching`, `MatchRow`, миграция индекса, переводы.
- [ ] **Step 4: Запустить** → PASS; `composer analyse` → No errors. Проверить план запроса на локальной базе: `mcp__phpstorm__execute_sql_query` с `EXPLAIN ANALYZE` — выполнение < 5 мс.
- [ ] **Step 5: Commit** `feat(crm): landlord-client matching query`.

### Task 4: Секции на карточках, документация

**Files:**
- Modify: `app/Filament/Pages/ListingView.php` (`matches(): ?list<MatchRow>` — `#[Computed]`; `null`, если не `rent` или нет `canManage`)
- Modify: `resources/views/filament/pages/listing-view.blade.php` (секция под плашкой)
- Modify: `app/Filament/Resources/Clients/Schemas/ClientForm.php` (`ViewEntry::make('offers')` в `infolist`, видна при `housing = looking` и не в архиве)
- Create: `resources/views/filament/clients/offers.blade.php`
- Modify: `docs/GSHome/02_Решения.md`, `docs/GSHome/08_Объявления.md`
- Test: `tests/Feature/Filament/ListingViewTest.php`, `tests/Feature/Filament/ClientResourceTest.php`

**Interfaces:**
- Consumes: `Matching::clientsFor`, `Matching::pinsFor`, `MatchRow` (Task 3); правила имени держателя — вынести из `ListingView::banner()` в `Pins::holderLabel(ListingPin $pin, User $viewer): ?string` (`null` — «у другого агента группы») и использовать в обоих местах.

Строка у объекта: `92% · Нино Беридзе · +995… · цена +$50, животные` → ссылка на `ClientResource::getUrl('view', ['record' => $client])`. Строка у клиента: `88% · <title> · $600 · в работе у <holderLabel> · …` → ссылка на `ListingView::getUrl(['listing' => $pin->listing_id])`. При `enough = false` вместо процента: `61% · мало данных`. Пустой список — `crm.matching.empty`.

- [ ] **Step 1: Тесты.**
  - `ListingViewTest::test_holder_sees_probable_clients` — `rent`-пин, ищущий клиент «Лаша» с подходящим профилем → `assertSee('Вероятные клиенты')`, `assertSee('Лаша')`, `assertSee('%')`.
  - `ListingViewTest::test_probable_clients_hidden_for_others_and_sale` — чужой агент и `sale`-пин → `assertDontSee('Вероятные клиенты')`.
  - `ListingViewTest::test_probable_clients_empty_state` — ищущих клиентов нет → `assertSee('Подходящих пока нет')`.
  - `ClientResourceTest::test_looking_client_sees_offers_without_landlord_note` — пин с `preferences.note = 'Секрет'` и держателем из другой компании → на `ViewClient` видны «Вероятные предложения», `title` пина, `в работе у другого агента группы`; `assertDontSee('Секрет')`.
  - `ClientResourceTest::test_renting_client_has_no_offers` → `assertDontSee('Вероятные предложения')`.
- [ ] **Step 2: Запустить** `--filter='ListingViewTest|ClientResourceTest'` → FAIL.
- [ ] **Step 3: Реализация**, вёрстка — `x-filament::section`, как соседние блоки. Документация: в `02_Решения.md` — решение «Подбор пар считается SQL-запросом при открытии» (что, почему, чем платим: нет уведомлений; снимок обновляется раз в сутки), в `08_Объявления.md` — абзац о секции «Вероятные клиенты» и снимке.
- [ ] **Step 4: Запустить** → PASS; `composer analyse`; инспекции `mcp__phpstorm__get_file_problems` по каждому изменённому файлу (errorsOnly); визуально — `http://gshome.localhost:8088` (карточка объекта и клиента, светлая и тёмная тема, ширина 375).
- [ ] **Step 5: Commit** `feat(crm): probable clients and offers on cards`; затем `bin/ship.sh --pr "feat(crm): landlord preferences and client matching"`.
