# Несколько агентов на объекте, курс НБГ, договор с собственником, отчёт — план

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** одну квартиру ведут несколько агентов независимо; договор с собственником у каждого свой; суммы с курсом НБГ; отчёт «Работа с собственниками» в ₾ / $; демо для директора.

**Architecture:** новая таблица `listing_objects` (квартира: ключ группы, снимок, статус, кто закрыл) — одна строка на квартиру и единственная точка блокировки; `listing_pins` становится участием агента (`object_id`, UNIQUE(object_id, user_id) среди активных). Сделки по-прежнему на участии. Пять PR по порядку, каждый можно выкатывать на прод.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, PostgreSQL 17/18, PHPUnit 12 + paratest, Larastan 5.

**Spec:** `docs/superpowers/specs/2026-11-25-owner-agreement-design.md`

## Global Constraints

- Инварианты — CHECK и частичные уникальные индексы в PostgreSQL, не только код (`docs/GSHome/05_Оргструктура.md`).
- Права — только `App\Access\OrgAccess` и `Pins::canManage`; ресурс/страница правил не придумывают.
- Переводы во всех трёх `lang/{ka,ru,en}/gshome.php`.
- Тесты только `docker compose run --rm test composer test` (точечно `-- --filter`); после каждой пачки правок `docker compose exec app composer analyse`.
- Перед PR: `mcp__phpstorm__get_file_problems` по каждому изменённому файлу, затем `bin/ship.sh --pr "…"`.
- Решения — запись в `docs/GSHome/02_Решения.md` (дата, что, почему, чем платим).
- Коммиты заканчиваются строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Два агента жмут «Сделка состоялась» почти одновременно** — выигрывает один, второй получает понятный отказ «объект уже сдан»; ни одной пары `won` на одном объекте. Тест в Task 4.
2. **Объявление вошло в группу дублей, где уже есть открытый объект** — объекты склеиваются, у агента не оказывается двух активных участий, сделки не теряются (включая одного клиента в обоих участиях). Тест в Task 2 и Task 3.
3. **Агент «освободил» объект последним** — объект закрыт `abandoned`, повторное «Взять в работу» создаёт новый объект и проходит. Тест в Task 3.
4. **Снятое с сайта объявление (API 404)** — карточка находит участие по `listing_id`/группе, кнопки работают как сейчас. Тест в Task 6.
5. **Агент компании без обмена** — не видит в «Ведут» чужих, а уведомление о закрытии приходит без имени. Тест в Task 5.

---

# PR 1 — объекты и несколько агентов (подробно)

Ветка `feat/multi-agent-objects` от `main` (в ней уже коммиты дизайна и записи о чистке прода).

**Отклонение от спецификации R8 (решение плана, сообщить владельцу):** передача участия при архиве сейчас в коде не делается вовсе (архив не трогает пины — `Archive::scope` их прячет). PR 1 убирает передачу из карточки и `Pins::transfer` целиком; поведение архива не меняется. Коллега при необходимости просто берёт объект в работу сам. Склейка «два участия одного агента» нужна только в Task 2 (склейка объектов).

## Файлы PR 1

| файл | ответственность |
|---|---|
| `database/migrations/2026_11_26_100000_create_listing_objects.php` | таблица объектов, `object_id` на пинах, перенос снимка, индексы, причины, бэкфилл |
| `app/Models/ListingObject.php` | модель объекта |
| `app/Enums/UnavailableReason.php` | причины «недоступен» и их охват |
| `app/Crm/ListingObjects.php` | найти/создать/склеить/заблокировать объект, снимок |
| `app/Crm/Pins.php` | участие: взять, мой пин, закрыть; без передачи |
| `app/Crm/Events/ObjectClosed.php`, `app/Notifications/ObjectClosed.php`, `app/Listeners/NotifyObjectClosed.php` | уведомление остальным ведущим |
| `app/Crm/Deals.php` | `win` закрывает объект для всех; `othersOnObject` |
| `app/Filament/Pages/ListingView.php`, `resources/views/filament/pages/listing-view.blade.php` | «Ведут», мой пин, причина недоступности, без передачи |
| `app/Crm/Matching.php`, `app/Crm/MatchRows.php`, `app/Console/Commands/SnapshotPins.php` | снимок и подбор по объекту |
| удалить: `app/Crm/Events/PinTransferred.php`, `app/Listeners/ReassignDealsOnPinTransferred.php` | передача |

### Task 1: Схема объектов и миграция данных

**Files:**
- Create: `database/migrations/2026_11_26_100000_create_listing_objects.php`, `app/Models/ListingObject.php`, `database/factories/ListingObjectFactory.php`, `app/Enums/UnavailableReason.php`
- Modify: `app/Models/ListingPin.php` (fillable/casts: `object_id`, `unavailable_reason`; убрать снимок; `object(): BelongsTo`)
- Test: `tests/Feature/Crm/ListingObjectSchemaTest.php`

**Interfaces:**
- Produces: `ListingObject` (`object_key`, `listing_id`, `cluster_id`, `direction: Direction`, `status` `open|closed`, `closed_reason` `won|rented_elsewhere|fake|abandoned|merged_into`, `closed_at`, `closed_by`, `won_deal_id`, `merged_into_id`, `price_usd`, `price_gel`, `rooms`, `subdistrict_id`, `title`, `snapshot_at`; `pins(): HasMany`, `activePins(): HasMany`, `isOpen(): bool`, константы `OPEN`, `CLOSED`); `ListingPin::object(): BelongsTo`; `ListingPin::$unavailable_reason: ?UnavailableReason`.
- `enum UnavailableReason: string { RentedElsewhere='rented_elsewhere'; Fake='fake'; OwnerRefused='owner_refused'; Other='other'; public function closesObject(): bool }` — true для первых двух; `HasLabel`.
- Пин `closed_reason`: `won|unavailable|released|closed_by_other|merged`.

- [ ] **Step 1: Тест схемы** — `test_one_open_object_per_key` (второй `open` с тем же `object_key` → `UniqueConstraintViolationException`; закрытый — можно), `test_one_active_pin_per_agent_per_object` (второй активный пин того же `user_id` и `object_id` → исключение; другой агент — можно), `test_closed_object_requires_reason` (CHECK `(status='open') = (closed_reason IS NULL)`), `test_pin_unavailable_reason_only_when_unavailable` (CHECK `unavailable_reason IS NULL OR closed_reason='unavailable'`).
- [ ] **Step 2: Запустить** `docker compose run --rm test composer test -- --filter ListingObjectSchemaTest` → FAIL (нет таблицы).
- [ ] **Step 3: Миграция.** `listing_objects` по таблице выше (`object_key` varchar(40), снимок как сейчас на пинах + `price_gel` numeric(12,2)); CHECK статуса и причины; `CREATE UNIQUE INDEX listing_objects_open_key ON listing_objects (object_key) WHERE status='open'`; индекс `listing_id`. На `listing_pins`: `object_id` FK (сначала nullable) и `unavailable_reason` varchar(20). **Бэкфилл SQL:** на каждый пин — объект (`object_key = pin_key`, статус/причина: `active→open`; `won→closed/won`; `released`,`unavailable→closed/abandoned`; `closed_at`, снимок копируется), `object_id` проставить; затем `object_id NOT NULL`. Снять `listing_pins_active_key`, создать `listing_pins_active_agent ON listing_pins (object_id, user_id) WHERE status='active' AND deleted_at IS NULL`. Расширить CHECK причин пина. Удалить колонки снимка с пинов. `down()` — обратный перенос снимка и старый индекс (на свежей базе; при двух активных пинах на ключ падает — так и задумано).
- [ ] **Step 4: Модель, фабрика, enum.**
- [ ] **Step 5:** тест PASS; `composer analyse` чисто.
- [ ] **Step 6: Проверка бэкфилла на копии прода.** `ssh gshome` → `pg_dump -Fc` в `~/backups/`; `scp` в scratchpad; `createdb gshome_prodcopy` в контейнере postgres; `pg_restore`; `DB_DATABASE=gshome_prodcopy php artisan migrate` в контейнере `app`; ожидание: `listing_objects` = 6 строк, все пины с `object_id`, у сделок ничего не поменялось. Удалить базу копии.
- [ ] **Step 7: Commit** `feat(objects): listing_objects table, pins become participations`.

### Task 2: Сервис объектов — найти, создать, склеить, заблокировать, снимок

**Files:**
- Create: `app/Crm/ListingObjects.php`
- Modify: `app/Crm/Pins.php` (перенести `snapshot` и `keyFor` сюда; в Pins оставить делегирование не нужно — вызывающие переходят на новый сервис)
- Test: `tests/Feature/Crm/ListingObjectsTest.php`; перенести `tests/Feature/Crm/PinSnapshotTest.php` на объект

**Interfaces:**
- Produces:
  - `ListingObjects::keyFor(Listing $l): string` — `c:{cluster}` / `l:{id}`.
  - `ListingObjects::findOpen(int $listingId, ?int $clusterId): ?ListingObject` — открытые по ключу **или** `listing_id`; больше одного — склеивает в старший и возвращает его.
  - `ListingObjects::openFor(Listing $l): ListingObject` — `findOpen` или создать (direction из объявления, снимок сразу).
  - `ListingObjects::lock(ListingObject $o): ListingObject` — `lockForUpdate`, свежая строка; закрыт или склеен → `CrmForbidden('Object closed.')` (для склеенного — сообщение с id старшего).
  - `ListingObjects::merge(ListingObject $keep, ListingObject $gone): void` — в транзакции, блокировки по возрастанию id; пины `gone` → `object_id = keep`; `gone` → `closed/merged_into`, `merged_into_id`; если у агента теперь два активных пина в `keep` — младший закрывается `merged`, его открытые сделки переносятся на старший; сделка того же клиента, уже есть у старшего → перенесённая становится `unavailable`.
  - `ListingObjects::snapshot(ListingObject $o, Listing $l, bool $touch = false): bool` — как нынешний `Pins::snapshot`, плюс `price_gel`.
  - `ListingObjects::closeIfAbandoned(ListingObject $o): void` — нет активных пинов → `closed/abandoned`.
- Consumes: `ListingObject` (Task 1).

- [ ] **Step 1: Тесты** — `test_open_for_creates_once_per_cluster` (два вызова → один объект), `test_find_by_listing_id_when_key_is_stale` (объект `l:41`, объявление 41 пришло с группой 5 → найден), `test_merge_moves_pins_and_closes_younger` (`merged_into_id`, пины на старшем), `test_merge_same_agent_keeps_older_pin_and_moves_deals` (у Нино пины в обоих: младший `merged`, его открытая сделка на старшем; та же пара клиент+объект → перенесённая `unavailable`), `test_lock_rejects_closed_object`, снимковые тесты из `PinSnapshotTest` на объекте.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: Реализация.**
- [ ] **Step 4:** PASS, `composer analyse`.
- [ ] **Step 5: Commit** `feat(objects): find, create, merge and lock objects`.

### Task 3: Участие — взять, мой пин, освободить, недоступен (без передачи)

**Files:**
- Modify: `app/Crm/Pins.php`, `app/Crm/Journal.php` (константа `PIN_TRANSFERRED` остаётся — старые записи журнала; добавить `OBJECT_CLOSED = 'object.closed'`), `app/Providers/*` или `EventServiceProvider` — снять регистрацию `ReassignDealsOnPinTransferred`
- Delete: `app/Crm/Events/PinTransferred.php`, `app/Listeners/ReassignDealsOnPinTransferred.php`
- Modify: `app/Crm/Exceptions/PinTaken.php` — смысл «вы уже ведёте этот объект»
- Test: `tests/Feature/Crm/PinsTest.php` — удалить тесты передачи и «занято другим», добавить новые

**Interfaces:**
- Consumes: `ListingObjects` (Task 2).
- Produces:
  - `Pins::take(Listing $l, User $by): ListingPin` — в транзакции `openFor` + `lock`; у `$by` уже активный пин в объекте → `PinTaken`. Другим агентам — не отказ.
  - `Pins::mine(ListingObject $o, User $u): ?ListingPin` — активный пин этого человека.
  - `Pins::managed(ListingObject $o, User $viewer): ?ListingPin` — `mine` или самый старый активный пин, которым `$viewer` вправе управлять (`canManage`) — для руководителя на карточке.
  - `Pins::activeOf(ListingObject $o): Collection<int, ListingPin>` — активные пины, `with('holder')`, по id.
  - `Pins::markUnavailable(ListingPin $pin, UnavailableReason $reason, User $by): void` — `closesObject()` → `closeObject($o, $reason->value, $by, except: $pin)` + свой пин `unavailable` с причиной; иначе только свой пин + `closeIfAbandoned`.
  - `Pins::release(ListingPin $pin, User $by): void` — как сейчас (нельзя с открытыми сделками) + `closeIfAbandoned`.
  - `Pins::closeObject(ListingObject $o, string $reason, User $by, ?ListingPin $except = null, ?Deal $wonDeal = null): void` — **вызывается внутри транзакции с уже взятой блокировкой объекта**: объект `closed/$reason` (+`closed_by`, `won_deal_id`), остальные активные пины `closed_by_other` (их открытые сделки → `unavailable` через `PinClosed`), событие `ObjectClosed($o, $by, $notify = пины, закрытые этим вызовом)`.
  - Удаляются: `transfer`, `canReceive`, `activeFor`, `activeForIds`, `snapshot`, `keyFor`.
  - `close()` больше не публичный путь для `won`: остаётся приватный `closePin(ListingPin, string $reason, string $event, User $by, ?UnavailableReason $why = null)`.
  - `CloseDealsOnPinClosed` реагирует на `won`, `unavailable`, `closed_by_other`.

- [ ] **Step 1: Тесты** — `test_two_agents_take_same_listing` (два пина, один объект), `test_same_agent_cannot_take_twice` (`PinTaken`), `test_release_last_pin_abandons_object` и `test_take_after_abandon_creates_new_object`, `test_owner_refused_closes_only_mine` (у Георгия пин активен, объект открыт), `test_fake_closes_object_for_all` (Георгий `closed_by_other`, его открытая сделка `unavailable`, объект `closed/fake`, журнал `object.closed`), `test_release_with_open_deals_forbidden` (как сейчас), `test_leader_manages_oldest_visible_pin` (`managed`).
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: Реализация**; удалить файлы передачи и регистрацию слушателя; `grep -rn "transfer\|activeFor" app tests` — пусто, кроме `Journal::PIN_TRANSFERRED` и его перевода.
- [ ] **Step 4:** `--filter 'PinsTest|ListingObjects'` PASS, `composer analyse`.
- [ ] **Step 5: Commit** `feat(pins): several agents per object; no transfer`.

### Task 4: Сделка состоялась — объект закрывается у всех; «сдали двое» невозможно

**Files:**
- Modify: `app/Crm/Deals.php` (`win`, `open`, новый `othersOnObject`), `app/Listeners/CloseDealsOnPinClosed.php`
- Test: `tests/Feature/Crm/DealsTest.php`, `tests/Feature/Crm/DealPartnerTest.php` (тесты, опиравшиеся на передачу/эксклюзив — `:242`, `:293`, `:201` — переписать под участия)

**Interfaces:**
- Consumes: `ListingObjects::lock`, `Pins::closeObject` (Tasks 2–3).
- Produces: `Deals::win(...)` — та же сигнатура; в транзакции **первой** блокируется объект (`ListingObjects::lock($deal->pin->object)`), затем `lockOpen($deal)`; затем сделка `won`, свой пин `won`, `Pins::closeObject($o, 'won', $by, except: $pin, wonDeal: $deal)`. `Deals::othersOnObject(ListingPin $pin, Client $client): Collection<int, Deal>` — открытые сделки этого клиента на **других** пинах того же объекта, `with('agent')`.
- `Deals::open` — блокирует объект первым (порядок блокировок един), проверка «клиент уже в сделке на этом пине» остаётся.

- [ ] **Step 1: Тесты** — `test_win_closes_object_for_everyone` (пин Георгия `closed_by_other`, его открытая сделка `unavailable`, объект `closed/won`, `won_deal_id`), `test_second_win_on_same_object_is_rejected` (после первого `win` второй — `CrmForbidden`; в базе один `won`), `test_others_on_object_lists_same_client_elsewhere`, `test_open_deal_on_closed_object_rejected`.
- [ ] **Step 2:** FAIL. **Step 3:** реализация. **Step 4:** `--filter 'Deals|DealPartner|Viewing'` PASS (`ViewingRemindersTest:175` поправить, если опирался на передачу). **Step 5: Commit** `feat(deals): winning closes the object for all agents`.

### Task 5: Уведомление остальным ведущим

**Files:**
- Create: `app/Crm/Events/ObjectClosed.php` (`ListingObject $object`, `User $by`, `string $reason`, `Collection<int, ListingPin> $closedPins`), `app/Listeners/NotifyObjectClosed.php` (после коммита: `ShouldHandleEventsAfterCommit`), `app/Notifications/ObjectClosed.php` (канал `database`, как `TaskAssigned`)
- Test: `tests/Feature/Crm/ObjectClosedNotificationTest.php`

**Interfaces:**
- Текст: `gshome.crm.objects.closed_by` «:title — сдан/продан агентом :name» / `closed_by_other_company` «… агентом другой компании» / для `fake` и `rented_elsewhere` — свои строки. Имя — по правилу `Pins::holderLabel` (переименовать во внутреннее `Pins::nameFor(User $holder, User $viewer): ?string`, `holderLabel` вызывает его).
- Получатели — держатели `closedPins`, кроме `$by`.

- [ ] **Step 1: Тесты** — `test_other_holders_notified_with_name_same_company`, `test_holder_of_company_without_exchange_gets_no_name`, `test_closer_not_notified`, `test_no_notification_when_rolled_back` (исключение после `closeObject` в транзакции → уведомлений нет).
- [ ] **Step 2–4:** FAIL → реализация → PASS. **Step 5: Commit** `feat(objects): notify other agents when an object closes`.

### Task 6: Карточка объекта — «Ведут», мой пин, причина недоступности

**Files:**
- Modify: `app/Filament/Pages/ListingView.php` (`pin()`, `banner()`, `matches()`, `dealPin()`, `mount` снимок, действия), `resources/views/filament/pages/listing-view.blade.php`, `app/Filament/Pages/Concerns/OpensDealsByPhone.php` (предупреждение R3), `lang/{ka,ru,en}/gshome.php`
- Test: `tests/Feature/Filament/ListingViewTest.php` (переписать `:489`, `:522`, `:553`)

**Interfaces:**
- `ListingView::object(): ?ListingObject` (`#[Computed]`) — `ListingObjects::findOpen(id, cluster_id из карточки)`; работает и для снятого объявления (только по карточке).
- `ListingView::pin(): ?ListingPin` — `Pins::managed($object, $viewer)`.
- `ListingView::banner(): ?array{holders: list<array{name: ?string, mine: bool}>, preferences: list<string>, closed: ?array{reason: string, at: string, name: ?string}}` — ведущие по R4/уточнению 7: своя компания и компании по обмену (`OrgAccess::identifiableCompanyIds`) с именами по `nameFor`; прочие не показываются. `closed` — последний закрытый объект этого `listing_id` с причиной `won|rented_elsewhere|fake`, если открытого нет.
- «Взять в работу» видна, если объект не открыт **или** у смотрящего нет своего пина (`Pins::mine`), и направление доступно.
- `transferPin` и `recipients()` удаляются.
- `markUnavailable` — модалка с `Select reason` (обязательный, `UnavailableReason`), под ним подсказка охвата (`helperText`: «закроет объект для всех ведущих» / «закроет только вашу работу»).
- После открытия сделки: `Deals::othersOnObject` не пуст → предупреждение (`Notification::warning`) «Этот клиент уже в сделке по этой квартире у :names» — сделка всё равно открыта.

- [ ] **Step 1: Тесты (Livewire)** — `test_second_agent_sees_take_button_and_holder_list`, `test_unavailable_requires_reason_and_fake_closes_for_all`, `test_no_transfer_action`, `test_removed_listing_still_finds_my_pin` (API 404 → кнопки «Освободить»/«Недоступен» видны), `test_closed_object_banner_shows_who_rented`, `test_warning_when_client_in_other_agents_deal`.
- [ ] **Step 2–4:** FAIL → реализация → PASS; `composer analyse`. **Step 5: Commit** `feat(listing): several holders on the card; unavailable with reason`.

### Task 7: Подбор пар и ночной снимок — по объекту

**Files:**
- Modify: `app/Crm/Matching.php` (SQL: `JOIN listing_objects o ON o.id = p.object_id AND o.status='open'`; `p.price_usd|rooms|subdistrict_id` → `o.*`), `app/Crm/MatchRows.php` (`$pin->price_usd`/`title` → `$pin->object`), `app/Console/Commands/SnapshotPins.php` (обходит открытые объекты аренды, один запрос API на объект, `ListingObjects::snapshot`; перед снимком `findOpen` по свежему объявлению — склейка), `routes/console.php` (без изменений)
- Test: `tests/Feature/Crm/MatchingTest.php`, `tests/Feature/Console/SnapshotPinsTest.php`

**Interfaces:**
- `Matching::pinsFor(Client $client, User $viewer, int $limit)` — одна строка на объект: `DISTINCT ON (p.object_id)` с порядком `(p.user_id = :viewer) DESC, p.id` — предпочтения своего участия, иначе старшего (R7).
- `Matching::clientsFor(ListingPin $pin)` — без изменений логики (предпочтения своего пина).

- [ ] **Step 1: Тесты** — `test_client_sees_one_row_per_object_with_two_agents`, `test_viewer_own_preferences_preferred`, `test_snapshot_one_api_call_per_object` (два пина → один запрос к `RealEstateFake`).
- [ ] **Step 2–4.** **Step 5: Commit** `feat(matching): match and snapshot per object`.

### Task 8: Демо, документы, полный прогон, PR

**Files:**
- Modify: `app/Crm/CrmDemo.php:390,429,453` (`activeFor` → `ListingObjects::findOpen` + «нет открытого объекта»), `tests/Feature/Crm/CrmDemoSeederTest.php`
- Modify docs: `docs/GSHome/02_Решения.md` (запись 26.11: несколько агентов на объекте; отменяет «одно закрепление на группу» и передачу; R1–R10 как временные до ответа директора; чем платим), `docs/GSHome/11_CRM_альфа.md` (таблица «где что»: `ListingObjects`, нет передачи), `docs/GSHome/10_Модули_системы.md` (пометки у разделов про передачу), `docs/GSHome/08_Объявления.md` если упоминает держателя
- Lang: удалить `crm.pins.transfer*`, `already_taken`, `in_work_by/other`; добавить `crm.objects.*`

- [ ] **Step 1:** `CrmDemoSeederTest` PASS.
- [ ] **Step 2:** `docker compose run --rm test composer test` — весь сьют зелёный; `docker compose exec app composer analyse` — 0 ошибок.
- [ ] **Step 3:** инспекции PhpStorm по каждому изменённому PHP-файлу (`mcp__phpstorm__get_file_problems`, errorsOnly) — чисто.
- [ ] **Step 4:** ручная проверка в браузере на `http://gshome.localhost:8088`: два агента одной команды берут одну квартиру, оба видят «Ведут: …»; один закрывает сделку — у второго уведомление и пин закрыт.
- [ ] **Step 5:** `bin/ship.sh --pr "Несколько агентов на объекте"`.
- [ ] **Step 6 (после слияния, с подтверждения пользователя):** дамп прода, `bin/deploy.sh`, проверка: 6 объектов, карточки открываются, Bugsnag без новых ошибок.

---

# PR 2 — курс НБГ (укрупнённо)

- Таблица `exchange_rates` (`date`, `currency`, `rate numeric(12,6)` ₾ за 1 $, `source`, `fetched_at`; UNIQUE(date, currency)); `App\Money\Rates::for(CarbonInterface $date): ?ExchangeRate` (таблица → API НБГ с таймаутом 3 с, деление на `quantity`, дата по Тбилиси); джоба `FetchNbgRate(string $date)` с повторами, проставляет `rate_id` записям этой даты без курса; расписание — ежедневно утром.
- `deals.rate_id`; `Deals::win` берёт курс синхронно, без курса — ставит джобу. Миграция: курс всем `won` без курса по `closed_at` (на проде — сделка 16).
- Формы сделки: валюта по умолчанию по направлению (₾ аренда, $ продажа).
- Тесты: `Http::fake` ответа НБГ (в т.ч. `quantity ≠ 1`, таймаут → джоба, повтор), идемпотентность таблицы.

# PR 3 — договор с собственником и сравнение для руководителя (укрупнённо)

- Колонки договора на `listing_pins` по спецификации (+`agreement_rate_id`), CHECK «целиком или никак», enum `CommissionOption` (аренда/продажа), `Pins::saveAgreement(ListingPin, array, User)` (заменяет `savePreferences`; `agreed_at`/`agreed_by` при первом сохранении), курс как в PR 2.
- Кнопка «Добавить договор» / «Договор с собственником», окно по превью (набросок 25.11), сводка «Ваш договор …» на карточке.
- Блок «Договоры по объекту» для руководителя: `OwnerAgreements::forObject(ListingObject, User $viewer)`, ★ — наибольшая ожидаемая комиссия в ₾ (`OwnerAgreements::expectedCommission(ListingPin): ?string`), `other` и без курса не участвуют, при равенстве — ранний.
- Тесты: права, CHECK, `agreed_at` не меняется, формула, видимость блока (агент не видит).

# PR 4 — отчёт «Работа с собственниками» и ₾ / $ (укрупнённо)

- `App\Crm\OwnerWorkReport::rows(User $viewer, from, to, string $currency)` по таблице показателей спецификации; переключатель валюты на `AgentReportPage` для обоих отчётов; пересчёт по курсу записи, «≈» для записей без курса; разбивка по районам; итог — уникальные объекты.
- Тесты агрегатов на фикстурах (каждый показатель), пересчёт ₾↔$.

# PR 5 — демо для директора (укрупнённо)

- `gshome:owner-agreement-demo {--fresh}`: только компания `Demo`; добавляет `Demo Agent 2`; ~60 участий за 8 недель на реальных объявлениях аренды, ~45 договоров с распределением журнала, «сдан другим», «недоступен» по причинам, курсы НБГ реальные; учёт в `crm_settings['owner_agreement_demo']`.
- Прогон на проде после PR 4, проверка отчёта под `max+cl.demo.lead@absoluteweb.com`.
