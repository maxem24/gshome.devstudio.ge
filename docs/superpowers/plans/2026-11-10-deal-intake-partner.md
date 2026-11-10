# Анкета клиента, цена и партнёр по сделке — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** В сделке CRM-альфы — анкета клиента по направлению, цена, второй агент (партнёр) с долей
комиссии суммой; отчёт делит заработок.

**Architecture:** Пять новых колонок в `deals` с CHECK-ами в PostgreSQL. Белый список вопросов
анкеты — класс `App\Crm\DealIntake`; все изменения — через сервис `App\Crm\Deals` под блокировкой
и с записью в журнал. Партнёр — только чтение: права на просмотр расширяются в `DealPolicy` и
`DealResource`, `Deals::canManage` не меняется.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, PostgreSQL 17, PHPUnit 12, Larastan 5.

**Spec:** `docs/superpowers/specs/2026-11-10-deal-intake-partner-design.md` (читать вместе с
планом), контекст — `docs/GSHome/13_Процесс_агентов.md`, правила кода — `docs/GSHome/11_CRM_альфа.md`.

## Global Constraints

- Ветка от `main`: `feat/deal-intake-partner`. PR — через `bin/ship.sh --pr "…"`.
- Тесты — только `docker compose run --rm test …`; в `app` не запускать.
- Artisan, composer — в контейнере `app` (`docker compose exec app …`).
- После каждой задачи — `docker compose exec app composer analyse` (уровень 5, без baseline) — чисто.
- Права — только через `App\Access\OrgAccess`; ресурс и политика правил не придумывают.
- Журнал — только `App\Crm\Journal::record()`; прямой `activity()` не писать.
- Кнопка — не защита: каждый action проверяет право на сервере (`authorizeFor` в `ViewDeal`).
- Ничего не удаляется; закрытая сделка не правится (`Deals::lockOpen`).
- Деньги — строки `^\d{1,12}(\.\d{1,2})?$`, `> 0`; валюты — `config('crm.currencies')` (`USD`, `GEL`).
- Подписи — `lang/{ru,en,ka}/gshome.php`; грузинский — машинный, с комментарием `// проверить у носителя`.
- Перед сдачей — `mcp__phpstorm__get_file_problems` по каждому изменённому PHP-файлу.

## Review Focus

1. **Направление сменилось после анкеты** (пин передали — направление то же, но анкету правят на
   сделке со старыми ключами): `validate()` для направления пина отбрасывает чужие ключи только
   при сохранении, чтение старой анкеты не падает. Тест в задаче 2.
2. **Наличные → ипотека в анкете:** `bank_help` не должен остаться в jsonb. Тест в задаче 2.
3. **Партнёр становится держателем объекта при передаче:** партнёр снимается, а не остаётся
   «партнёром самого себя» (CHECK бы упал и откатил передачу). Тест в задаче 5.
4. **Отчёт для тимлида, который видит только партнёра, но не агента сделки:** доля партнёра в его
   строке есть, сумма агента — нет; двойного счёта нет. Тест в задаче 6.
5. **Закрытие без доли у сделки с партнёром и с долей ≥ комиссии** — отказ, сделка открыта. Тест в задаче 5.

---

### Task 1: Колонки и CHECK-и

**Files:**
- Create: `database/migrations/2026_11_10_100000_add_intake_and_partner_to_deals.php`
- Modify: `app/Models/Deal.php`
- Test: `tests/Feature/Crm/DealColumnsTest.php`

**Interfaces:**
- Produces: `deals.intake jsonb not null default '{}'`, `deals.price numeric(14,2) null`,
  `deals.price_currency varchar(3) null`, `deals.partner_user_id bigint null FK users`,
  `deals.partner_share numeric(14,2) null`; индекс `(partner_user_id, status)`.
  `Deal`: `@property array<string, string|bool> $intake`, `?string $price`, `?string $price_currency`,
  `?int $partner_user_id`, `?string $partner_share`; cast `intake => 'array'`, `partner_user_id => 'integer'`;
  в `#[Fillable]` — пять новых полей; `partner(): BelongsTo<User, $this>` (`partner_user_id`).

- [ ] **Step 1: Failing test** — `DealColumnsTest`, все вставки через `DB::table('deals')->update(...)`
  на сделке из фабрик (как в `DealsTest::setUp`), каждая ждёт `QueryException`:
  - `test_partner_cannot_be_deal_agent` — `partner_user_id = user_id`;
  - `test_share_requires_amount_and_is_below_it` — `partner_share=100, amount=null`; `partner_share=100, amount=100`; `partner_share=0, amount=100`;
  - `test_price_and_currency_together` — `price=1000, price_currency=null` и наоборот;
  - `test_defaults` — новая сделка: `intake === []`, остальные четыре `null`.
- [ ] **Step 2:** `docker compose run --rm test php artisan test --filter=DealColumnsTest` → FAIL (нет колонок).
- [ ] **Step 3: Миграция и модель.** CHECK-и raw SQL (как в миграциях оргструктуры):
  `deals_partner_not_agent: partner_user_id is null or partner_user_id <> user_id`;
  `deals_partner_share: partner_share is null or (partner_share > 0 and amount is not null and partner_share < amount)`;
  `deals_price_pair: (price is null) = (price_currency is null)`. `down()` снимает всё.
- [ ] **Step 4:** тот же фильтр → PASS; `composer analyse` → чисто; `docker compose exec app php artisan migrate`.
- [ ] **Step 5:** commit `feat(deals): intake, price and partner columns with checks`.

### Task 2: Анкета и цена

**Files:**
- Create: `app/Crm/DealIntake.php`
- Modify: `app/Crm/Deals.php`, `app/Crm/Journal.php`
- Test: `tests/Feature/Crm/DealIntakeTest.php`

**Interfaces:**
- Consumes: колонки задачи 1.
- Produces:
  - `DealIntake::fields(Direction $d): list<string>` — `Direction::Rent`: `occupants, term, prepay, pets, deposit_ok, notes`; `Direction::Sale`: `purpose, payment, bank_help, down_payment, down_payment_currency, wants_discount, notes`.
  - `DealIntake::options(string $key): ?list<string>` — `term: 6m, 1y, other`; `prepay: 1, 1.5, 2, 3, first_last`;
    `deposit_ok: yes, no, not_discussed`; `purpose: living, investment`; `payment: cash, mortgage`;
    `down_payment_currency: config('crm.currencies')`; булевы (`pets, bank_help, wants_discount`) и текст — `null`.
  - `DealIntake::clean(Direction $d, array $input): array<string, string|bool>` — бросает `CrmForbidden` на
    ключ не из `fields($d)`, значение не из `options`, текст длиннее (`occupants` 255, `notes` 2000), не-bool у булевых,
    `down_payment` не по денежному правилу. Убирает `null` и `''`. Убирает `bank_help`, если `payment !== 'cash'`;
    `down_payment*`, если `payment !== 'mortgage'`. Обрезает пробелы в тексте.
  - `DealIntake::label(string $key, string|bool|null $value = null): string` — `__('gshome.crm.intake.'.$key)` / `…'.$key.'_'.$value`, булевы — `gshome.crm.intake.yes|no`.
  - `Journal::DEAL_INTAKE_CHANGED = 'deal.intake_changed'`.
  - `Deals::updateIntake(Deal $deal, array $intake, ?string $price, ?string $priceCurrency, User $by): void` —
    в транзакции `lockOpen`; `clean()` по `$deal->pin->direction`; цена — денежное правило, валюта из списка,
    оба `null` или оба заданы, иначе `CrmForbidden`. Ничего не изменилось — ни записи, ни журнала.
    Иначе `update` и журнал с `props + ['changed' => [key => ['from' => …, 'to' => …]]]` (ключи анкеты и `price`, `price_currency`); `pins->touch()`.

- [ ] **Step 1: Failing tests** в `DealIntakeTest` (сетап как `DealsTest`; для аренды — пин с `'deal_type' => ['id' => 2, 'slug' => 'rent', 'title' => 'rent']`):
  - `test_saves_rent_answers_and_price` — `['occupants'=>'семья с ребёнком','term'=>'1y','prepay'=>'first_last','pets'=>true,'deposit_ok'=>'yes']`, `'1200','USD'` → в базе ровно это, `price === '1200.00'`;
  - `test_rejects_sale_key_on_rent_deal` (`purpose`) и `test_rejects_value_outside_list` (`term => '2y'`) → `CrmForbidden`, запись не изменилась;
  - `test_cash_to_mortgage_drops_bank_help` — сначала `payment=cash, bank_help=true`, потом `payment=mortgage, down_payment='20000', down_payment_currency='USD'` → `bank_help` нет в `intake`;
  - `test_price_without_currency_rejected`;
  - `test_closed_deal_rejected` (после `lose`) и `test_foreign_agent_rejected` (агент другого тимлида);
  - `test_journal_lists_changes_and_noop_writes_nothing` — второй одинаковый вызов не добавляет `Activity`; первый пишет `changed.term.to === '1y'`;
  - `test_old_keys_read_without_error` — `intake` с `purpose` на арендной сделке (через `DB::table`) → `DealIntake::label('purpose','living')` строка, `updateIntake` с новым набором без `purpose` проходит.
- [ ] **Step 2:** `--filter=DealIntakeTest` → FAIL.
- [ ] **Step 3:** реализовать `DealIntake`, константу и `Deals::updateIntake` по интерфейсам выше.
- [ ] **Step 4:** `--filter=DealIntakeTest` → PASS; `--filter=DealsTest` → PASS; `composer analyse` → чисто.
- [ ] **Step 5:** commit `feat(deals): client intake and price on a deal`.

### Task 3: Кто может быть партнёром, добавить и снять

**Files:**
- Modify: `app/Access/OrgAccess.php`, `app/Crm/Deals.php`, `app/Crm/Journal.php`
- Test: `tests/Feature/Crm/DealPartnerTest.php`, `tests/Feature/Access/OrgAccessTest.php` (если есть; иначе в `DealPartnerTest`)

**Interfaces:**
- Produces:
  - `OrgAccess::canPartner(User $agent, User $candidate): bool` — `$candidate->isActive()`,
    `$candidate->role->hasTeam()`, `$candidate->id !== $agent->id`,
    `in_array($candidate->company_id, self::identifiableCompanyIds($agent), true)`.
    Агент сделки без команды (владелец) — список компаний из `identifiableCompanyIds` (все), это нормально.
  - `Deals::setPartner(Deal $deal, ?User $partner, User $by): void` — `lockOpen`; `null` снимает;
    `canPartner($deal->agent, $partner)` иначе `CrmForbidden`; тот же партнёр — ничего.
    Журнал: `DEAL_PARTNER_SET = 'deal.partner_set'` (`props + ['partner_id' => …, 'previous_id' => …|null]`),
    `DEAL_PARTNER_REMOVED = 'deal.partner_removed'` (`props + ['partner_id' => …, 'reason' => 'manual'|'became_holder']`).
  - `Deals::isPartner(Deal $deal, User $user): bool`.

- [ ] **Step 1: Failing tests:**
  - `test_colleague_of_same_company_can_be_partner` — агент другого тимлида той же компании (другое направление тоже) → `partner_user_id` записан, журнал `deal.partner_set`;
  - `test_other_company_only_with_exchange` — без `CompanyExchange` → `CrmForbidden`; с `CompanyExchange::create(['company_a_id'=>min,'company_b_id'=>max,'enabled'=>true])` → проходит;
  - `test_blocked_or_archived_or_owner_role_rejected` (три кандидата: `blocked()`, `archived()`, `companyOwner`);
  - `test_agent_himself_rejected`;
  - `test_replace_and_remove_journal` — смена партнёра пишет `previous_id`; `null` пишет `deal.partner_removed` с `reason=manual`;
  - `test_partner_cannot_set_partner` — партнёр вызывает `setPartner` → `CrmForbidden` (`canManage` его не включает).
- [ ] **Step 2:** `--filter=DealPartnerTest` → FAIL.
- [ ] **Step 3:** реализовать по интерфейсам.
- [ ] **Step 4:** `--filter=DealPartnerTest` → PASS; `composer analyse` → чисто.
- [ ] **Step 5:** commit `feat(deals): partner agent on a deal`.

### Task 4: Партнёр видит сделку, но не управляет

**Files:**
- Modify: `app/Policies/DealPolicy.php`, `app/Filament/Resources/Deals/DealResource.php`
- Test: `tests/Feature/Filament/DealResourceTest.php`

**Interfaces:**
- Consumes: `deals.partner_user_id`.
- Produces: `DealPolicy::view` — `OrgAccess::visibleUsers($user)->whereIn('id', array_filter([$deal->user_id, $deal->partner_user_id]))->exists()`.
  `DealResource::getEloquentQuery` — `where(fn ($q) => $q->whereIn('user_id', $visible)->orWhereIn('partner_user_id', $visible))` + `Archive::scope`.
  `manage` и `archive` не меняются: они уже требуют `Deals::canManage`.

- [ ] **Step 1: Failing tests** (Livewire, как соседние тесты в файле):
  - `test_partner_sees_deal_in_list_and_card` — `ListDeals` содержит сделку; `ViewDeal` открывается;
  - `test_partner_has_no_manage_actions` — `assertActionHidden` для `nextStage`, `win`, `lose`, `editIntake`, `setPartner`; `callAction('win', …)` → 403 / сделка открыта;
  - `test_partner_lead_sees_deal` — тимлид партнёра видит сделку в списке;
  - `test_unrelated_agent_does_not_see` — агент третьего тимлида → 404/403 на карточке.
- [ ] **Step 2:** `--filter=DealResourceTest` → FAIL.
- [ ] **Step 3:** правки политики и запроса.
- [ ] **Step 4:** `--filter=DealResourceTest` → PASS; `--filter=ViewingsTest` → PASS (показы партнёру — только чтение); `composer analyse` → чисто.
- [ ] **Step 5:** commit `feat(deals): partner can view a shared deal`.

> Действия `editIntake` и `setPartner` появятся в задаче 7; до неё `assertActionHidden` для них
> проходит тривиально — тест остаётся и защищает их потом.

### Task 5: Закрытие с долей и передача объекта

**Files:**
- Modify: `app/Crm/Deals.php`, `app/Listeners/ReassignDealsOnPinTransferred.php`, `app/Crm/Events/PinTransferred.php`, `app/Crm/Pins.php`
- Test: `tests/Feature/Crm/DealPartnerTest.php`, `tests/Feature/Crm/PinsTest.php`

**Interfaces:**
- Produces: `Deals::win(Deal $deal, string $amount, string $currency, User $by, ?string $partnerShare = null): void` —
  после `lockOpen`: партнёр есть → доля обязательна, денежное правило, `bccomp($share, $amount, 2) < 0`; партнёра нет → доля
  должна быть `null`. Иначе `CrmForbidden`. В журнал `DEAL_WON` добавить `partner_share`.
  Слушатель: до переназначения — открытые сделки пина, у которых `partner_user_id = $event->to->id`, получают
  `partner_user_id = null` и запись `DEAL_PARTNER_REMOVED` с `reason = 'became_holder'`, causer — `$event->by`.
  `App\Crm\Events\PinTransferred` получает четвёртое поле `public readonly User $by`; `Pins::transfer()` (`app/Crm/Pins.php:91`) передаёт его в `dispatch`.

- [ ] **Step 1: Failing tests:**
  - `test_win_with_partner_requires_share` — без доли, `'0'`, равная сумме `'300'` при `amount='300'`, больше суммы → `CrmForbidden`, `status` открыт;
  - `test_win_with_share_saves_it` — `amount='300', share='150'` → `partner_share === '150.00'`, журнал содержит `partner_share`;
  - `test_share_without_partner_rejected`;
  - `test_transfer_to_partner_removes_partner` (в `PinsTest`) — передать пин партнёру → `user_id = партнёр`, `partner_user_id = null`, журнал `deal.partner_removed` с `became_holder`; передача не откатилась;
  - `test_transfer_to_third_agent_keeps_partner`.
- [ ] **Step 2:** `--filter='DealPartnerTest|PinsTest'` → FAIL.
- [ ] **Step 3:** реализовать.
- [ ] **Step 4:** `--filter='DealPartnerTest|PinsTest|DealsTest'` → PASS; `composer analyse` → чисто.
- [ ] **Step 5:** commit `feat(deals): partner share on close, partner dropped when becoming holder`.

### Task 6: Отчёт делит заработок

**Files:**
- Modify: `app/Crm/AgentReport.php`, `app/Crm/AgentReportRow.php`, страница `App\Filament\Pages\AgentReportPage` и её blade (колонка)
- Test: `tests/Feature/Crm/AgentReportTest.php`

**Interfaces:**
- Produces: `AgentReportRow::$dealsWonAsPartner` (`int`, новый параметр конструктора после `dealsWon`).
  Заработок агента: `sum(amount - coalesce(partner_share, 0))` по его выигранным сделкам. Заработок партнёра:
  отдельный запрос `deals where partner_user_id in $ids and status = won and closed_at in период`,
  `group by partner_user_id, currency`, `sum(partner_share)`, `count(*)`. Суммы по валюте складывать `bcadd(…, 2)`.
  Подпись колонки — `gshome.crm.report.won_as_partner`.

- [ ] **Step 1: Failing tests:**
  - `test_earnings_split_between_agent_and_partner` — `amount=300 USD, share=100` → агент `200.00`, партнёр `100.00`, сумма по двоим = 300;
  - `test_partner_counted_separately` — `dealsWon` агента 1, `dealsWonAsPartner` партнёра 1, `dealsWon` партнёра 0;
  - `test_lead_seeing_only_partner_gets_only_share` — тимлид партнёра: в его отчёте строка партнёра со `100.00`, строки агента нет;
  - `test_partner_with_own_win_sums_same_currency` — у партнёра своя сделка `50 USD` + доля `100 USD` → `150.00`.
- [ ] **Step 2:** `--filter=AgentReportTest` → FAIL.
- [ ] **Step 3:** реализовать; существующие тесты отчёта поправить на новый параметр конструктора.
- [ ] **Step 4:** `--filter=AgentReportTest` → PASS; `composer analyse` → чисто.
- [ ] **Step 5:** commit `feat(report): split earnings with deal partner`.

### Task 7: Карточка сделки и подписи

**Files:**
- Modify: `app/Filament/Resources/Deals/Pages/ViewDeal.php`, `app/Filament/Resources/Deals/Schemas/DealInfolist.php`,
  `app/Filament/Resources/Deals/Tables/DealsTable.php`, `lang/{ru,en,ka}/gshome.php`
- Test: `tests/Feature/Filament/DealResourceTest.php`

**Interfaces:**
- Consumes: `DealIntake::fields/options/label`, `Deals::updateIntake`, `Deals::setPartner`, `Deals::win(…, $partnerShare)`, `OrgAccess::canPartner`.
- Produces:
  - Действие `editIntake` («Изменить анкету»): поля из `DealIntake::fields($record->pin->direction)` —
    `options` → `Select`, булевы → `Toggle` (`deposit_ok` — `Select`), текст → `TextInput`/`Textarea`; `bank_help` видно при `payment=cash`, `down_payment*` — при `mortgage` (`->visible(fn (Get $get) …)`, `->live()` на `payment`);
    `price` + `price_currency`, подпись цены — `gshome.crm.deals.price` или `price_monthly` для аренды; плейсхолдер — цена объявления, если загружена на странице (иначе без него). `fillForm` текущими значениями. Видимость и `perform` — `manage`, как у `win`.
  - Действие `setPartner` («Партнёр»): `Select` с поиском по имени среди `User` с `canPartner($record->agent, $u)` (кандидаты — запрос по `identifiableCompanyIds`, активные, `hasTeam`, затем фильтр `canPartner`); пустое значение — снять. Только `manage`.
  - `win`: поле `amount` с подписью `gshome.crm.deals.commission`; поле `partner_share` видно и обязательно, если у сделки партнёр; подсказка «из комиссии, суммой».
  - Инфолист: `partner.name` (подпись «Партнёр», видно если есть), `price` с валютой, `partner_share` (если есть), секция «Анкета клиента» — список «вопрос: ответ» через `DealIntake::label`, пусто — «—».
  - Таблица: колонка «Партнёр» (toggleable).
  - Подписи: `crm.intake.*` (все ключи и значения из задачи 2, `yes`, `no`, `title`, `edit`), `crm.deals.partner`, `partner_share`, `set_partner`, `price`, `price_monthly`, `commission`. Ключ `amount` оставить (используется в отчёте), подпись не менять.
    ru — по спецификации; ka — термины агентства: `ბე`, `დეპოზიტი`, `იპოთეკა`, `ქეში`, `პირველი და ბოლო თვე`, `საინვესტიციოდ`, `საცხოვრებლად`.

- [ ] **Step 1: Failing tests:**
  - `test_agent_edits_intake_on_rent_deal` — `callAction('editIntake', ['term'=>'1y','pets'=>true,'price'=>'1200','price_currency'=>'USD'])` → в базе;
  - `test_sale_form_has_no_rent_fields` — на сделке продажи формы нет поля `term` (`assertFormFieldDoesNotExist` в action-форме или проверка схемы);
  - `test_set_partner_from_card` и `test_partner_select_excludes_other_company_without_exchange`;
  - `test_win_with_partner_share_from_card`;
  - `test_card_shows_intake_labels` — `assertSee('Семья…')`/подпись срока на ru.
- [ ] **Step 2:** `--filter=DealResourceTest` → FAIL.
- [ ] **Step 3:** реализовать.
- [ ] **Step 4:** `--filter=DealResourceTest` → PASS; `composer analyse` → чисто. Глазами — http://gshome.localhost:8088/admin (см. память «GS Home: проверка в браузере»): анкета аренды и продажи, партнёр, закрытие с долей.
- [ ] **Step 5:** commit `feat(deals): intake, price and partner on the deal card`.

### Task 8: Демо, документация, сдача

**Files:**
- Modify: `app/Crm/CrmDemo.php`, `docs/GSHome/11_CRM_альфа.md`, `docs/GSHome/02_Решения.md`, `docs/GSHome/10_Модули_системы.md`, `docs/GSHome/13_Процесс_агентов.md`
- Test: `tests/Feature/Crm/CrmDemoSeederTest.php`

- [ ] **Step 1: Failing test** — `test_demo_has_intake_and_partner_deals`: после демо есть сделка с непустой анкетой в каждом направлении, открытая сделка с партнёром и выигранная с `partner_share`.
- [ ] **Step 2:** `--filter=CrmDemoSeederTest` → FAIL.
- [ ] **Step 3:** дополнить `CrmDemo` через сервисы (`updateIntake`, `setPartner`, `win`), не через `DB::table`.
- [ ] **Step 4:** `--filter=CrmDemoSeederTest` → PASS.
- [ ] **Step 5: Документы.** `11_CRM_альфа.md` — строки «Где что» (`DealIntake`, `canPartner`) и правило «партнёр только видит; доля < комиссии — CHECK». `02_Решения.md` — запись от 10.11.2026: анкета на сделке, партнёр, доля суммой (что, почему, чем платим — из «Решения, которые я принял» спецификации). `10_Модули_системы.md` — «Вопрос руководителю агентства» закрыт вариантом б со ссылкой на 13. `13_Процесс_агентов.md` — пункты 2 (частично: комиссия суммой), 3 и 5 отметить «сделано, ветка feat/deal-intake-partner».
- [ ] **Step 6:** инспекции PhpStorm по всем изменённым PHP-файлам; `bin/ship.sh` → зелёный.
- [ ] **Step 7:** commit `docs(deals): intake and partner` и `bin/ship.sh --pr "Анкета клиента, цена и партнёр по сделке"`.
