# Публикация объекта на MyHome и ss.ge — план реализации

> **Для агентов-исполнителей:** обязательный навык — `superpowers:subagent-driven-development`
> (рекомендуется) или `superpowers:executing-plans`. Шаги отмечаются чекбоксами (`- [ ]`).
> **Статус: план, разработка НЕ начата** (решение владельца 26.11.2026: сначала документ).

**Цель.** После «Взять в работу» у агента появляется кнопка «Опубликовать на порталах». По нажатию
GS Home публикует объект на MyHome и ss.ge с контактами **агента**, а не собственника. Дизайн,
проверенные запросы и риски — в `docs/superpowers/specs/2026-11-26-portal-publishing-design.md`;
сырые снимки — `docs/GSHome/14_API_листингов_myhome.md`, `15_API_ss.ge.md`. **Читать их до начала.**

**Архитектура.** Новое пространство `App\Portals`: `ListingDraft` (нормализованное объявление) строится из
`Listing` и агента; два клиента (`MyHomeClient`, `SsGeClient`) за интерфейсом `PortalClient` общаются с
внутренними API порталов (без официальных); `Publications` пишет состояние в `portal_publications`;
публикует джоба `PublishToPortal` в очереди `portals` с одним воркером. Экран — действие в `ListingView`.

**Стек.** PHP 8.5, Laravel 13, Filament 5, PostgreSQL 17, Redis + Horizon, PHPUnit 12, Larastan 5.

**Глобальные ограничения (действуют во всех задачах):**

- Порядок работы — `CLAUDE.md`: `bin/preflight.sh` в начале; `composer analyse` после каждой пачки; тесты
  только `docker compose run --rm test composer test`; перед сдачей — `bin/ship.sh`; ветка от `main`.
- Права — **только через `OrgAccess`** и `Pins::canManage`. Направление объекта — как у пина.
- Секреты — `.env` → `config('services.myhome|ssge')`. В git, логи, Bugsnag, `payload` — не попадают.
- Портальные запросы **всегда** с браузерным `User-Agent` (иначе Cloudflare `1010`). Таймауты: connect 5 с,
  общий 30 с, на upload фото 60 с. Тесты — только `Http::fake()` с записанными ответами; реальные порталы
  в тестах не вызываются.
- Тексты — в `lang/{ru,en,ka}/gshome.php` (три языка сразу).
- Ничего не удаляется физически (`SoftDeletes`); важные действия — в `Journal::record`.
- Любое решение по архитектуре, API, правам — в `docs/GSHome/02_Решения.md` (дата, что, почему, чем платим).
- Любая находка спайка — в `14_`/`15_` документы и в спецификацию, а не только в коде.
- Файлы `bin/*` и скрипты `~/.cursor/script-library/*` не входят в репозиторий; новый скрипт — по правилу
  библиотеки (`README.md` + строка в индексе).

**Что решает владелец до старта разработки (из спецификации, «Открытые вопросы»):**
чьи аккаунты на порталах (1), подойдёт ли номер агента (2, проверяется спайком), что публикуем первым
(3), какой номер, если их несколько (4), нужно ли подтверждение согласия собственника (5), что делать
при закрытии пина (6). Задачи 1–5 от ответов не зависят; задача 6 (джоба) и 7 (экран) — зависят.

## Карта файлов

| файл | назначение |
|---|---|
| `config/services.php` | `myhome` и `ssge`: `username`, `password`, `enabled`, `timeout` |
| `config/portals/myhome.php`, `ssge.php` | статические соответствия: тип, сделка, состояние, статус дома, удобства |
| `config/horizon.php` | supervisor `portals` (один воркер) |
| `app/Portals/Portal.php` | enum `myhome`, `ssge` |
| `app/Portals/PortalClient.php` | интерфейс |
| `app/Portals/ListingDraft.php` | DTO объявления к публикации |
| `app/Portals/DraftBuilder.php`, `PhoneScrubber.php`, `LocationResolver.php` | построение и очистка |
| `app/Portals/MyHome/{MyHomeAuth,MyHomeClient,MyHomeMapper}.php` | клиент MyHome |
| `app/Portals/SsGe/{SsGeAuth,SsGeClient,SsGeMapper}.php` | клиент ss.ge |
| `app/Portals/Publications.php` | сервис состояния |
| `app/Portals/Exceptions/*.php` | `PortalException`, `PortalAuthFailed`, `PortalRejected`, `PortalUnavailable` |
| `database/migrations/2026_11_27_000000_create_portal_publications_table.php` | таблица |
| `app/Models/PortalPublication.php` | модель |
| `app/Jobs/PublishToPortal.php` | джоба |
| `app/Notifications/PortalPublished.php`, `PortalPublishFailed.php` | колокольчик |
| `app/Filament/Pages/ListingView.php` + `resources/views/filament/pages/listing-view/portals.blade.php` | действие и секция |
| `app/Console/Commands/PortalsProbe.php` | `portals:probe` — проверка входа и справочников |
| `app/Crm/Journal.php` | события `portal.*` |
| `tests/Feature/Portals/…`, `tests/Unit/Portals/…`, `tests/Fixtures/portals/*.json` | тесты и записанные ответы |

## Задача 0. Спайки (без интерфейса; результат — записи в документах)

Цель — снять неизвестное **до** кода. Исполняется под тестовым аккаунтом; тестовые объявления удалять.
Каждый пункт кончается записью в `14_`/`15_` и строкой в спецификации. Скрипты — в
`~/.cursor/script-library/`.

**Спайки.**

- [ ] **S1. Номер агента.** MyHome: `GET /v1/users/validation/check?number=<чужой номер>`, затем create с этим
  номером в `phone_number` (с последующим удалением). ss.ge: `create-draft` с неподтверждённым номером
  в `phoneNumbers` (ожидаем 400 или тихую подмену). Записать, какие номера принимаются.
- [ ] **S2. Прод-сервер.** С сервера, где будет работать Horizon: `curl` с браузерным UA к
  `accounts.tnet.ge/api/ka/user/auth`, `api-statements.tnet.ge/v1/locations/cities`,
  `account.ss.ge/ka/account/Login`, `api-gateway.ss.ge`. Ответ Cloudflare (1010 / challenge / 200) — в запись.
- [ ] **S3. MyHome: ответы.** Снять тело ответа `upload-image` и `create` (id нового объявления!) и
  эндпоинт снятия/удаления/скрытия (кнопка «удалить» в кабинете, `DELETE` или `PUT … status`).
- [ ] **S4. Справочники.** MyHome: `street_id`, `location_id`, `rent_period`, смысл `price_type_id=3`,
  `websites[]`, типы `living_room_type_id`, `storeroom_type_id`. ss.ge: `realEstateDealTypeId` для продажи,
  `state` и `status` (полные списки), булевы удобства. Сверить с `SourceMappingSeeder` проекта Real Estate.
- [ ] **S5. Модерация.** Править цену, описание и фото на обоих порталах и смотреть статус (кабинет и
  `GET …/{id}`): остаётся ли объявление на выдаче.
- [ ] **S6. Лимиты.** Максимум фото, размер, формат; `GET /v1/users/statements/monthly-count`; бесплатные
  объявления у брокера ss.ge в месяц.
- [ ] **S7. Повторный вход ss.ge.** 5 входов подряд, параллельный вход из двух процессов, поведение
  при неверном пароле (ошибка, а не блок аккаунта).
- [ ] **S8. Согласованность с Real Estate.** После публикации дождаться, пока Real Estate подтянет объявление,
  и убедиться, что оно появляется в группе дублей (`clusterId` оригинала) и в «Мои» агента. Если нет —
  решить, как связывать `portal_publications` с группой дублей.

**Результат:** в спецификацию добавлен раздел «Итоги спайков»; вопросы 1–4 закрыты или поставлены владельцу.

**Проверка:** `docs/GSHome/14_`/`15_` обновлены, тестовые объявления удалены, список оставленных —
в записи.

## Задача 1. Настройки и секреты

**Файлы:** `config/services.php`, `.env.example`, `docs/GSHome/03_Среда_разработки.md`.

- [ ] **Шаг 1. Тест.** `tests/Unit/Portals/ConfigTest`: `config('services.myhome.username')` читается из
  `MYHOME_USERNAME`; `enabled` по умолчанию `false`.
- [ ] **Шаг 2. Реализация.**

```php
'myhome' => [
    'username' => env('MYHOME_USERNAME'),
    'password' => env('MYHOME_PASSWORD'),
    'enabled' => (bool) env('PORTALS_ENABLED', false),
    'timeout' => 30,
],
'ssge' => [
    'username' => env('SS_USERNAME'),
    'password' => env('SS_PASSWORD'),
    'enabled' => (bool) env('PORTALS_ENABLED', false),
    'timeout' => 30,
],
```

- [ ] **Шаг 3.** В `.env.example` — `MYHOME_USERNAME=`, `MYHOME_PASSWORD=`, `SS_USERNAME=`, `SS_PASSWORD=`,
  `PORTALS_ENABLED=false` (значений нет). В `config/bugsnag.php` → `filters` добавить `password`,
  `authorization`, `global-authorization`, `access_token`, `refresh_token`.
- [ ] **Шаг 4.** `03_Среда_разработки.md`: раздел «Порталы» — имена переменных, где лежат пароли на сервере.
- [ ] **Шаг 5.** `composer analyse`; точечный тест; коммит.

## Задача 2. MyHome: вход и клиент

**Файлы:** `app/Portals/Portal.php`, `PortalClient.php`, `Exceptions/*`, `MyHome/MyHomeAuth.php`,
`MyHome/MyHomeClient.php`, `tests/Fixtures/portals/myhome/*.json`, `tests/Feature/Portals/MyHomeClientTest.php`.

**Интерфейсы:**

```php
interface PortalClient
{
    public function portal(): Portal;
    /** Создать объявление; вернуть ссылку на него. Бросает PortalException. */
    public function publish(ListingDraft $draft): PublishedRef;
    public function update(string $externalId, ListingDraft $draft): void;
    public function remove(string $externalId): void;
    /** Вход и лёгкий запрос; без побочных эффектов. */
    public function health(): bool;
}
final readonly class PublishedRef { public function __construct(public string $externalId, public string $url, public array $response) {} }
```

- [ ] **Шаг 1. Фикстуры.** Из спайков 3 и из записей `14_` в `tests/Fixtures/portals/myhome/`: `auth.json`,
  `upload.json`, `create.json`, `edit.json`, `update.json`. Без токенов и реальных номеров.
- [ ] **Шаг 2. Тесты (`Http::fake`).**
  - вход → `POST accounts.tnet.ge/api/ka/user/auth` с телом `{Email, Password, token:"", Continue}`;
    токен кешируется на 9 минут (второй вызов `publish` не ходит за токеном);
  - все запросы к `api-statements.tnet.ge` идут с `Global-Authorization`, `x-website-key: myhome`,
    `x-referrer-key: myhome`, `locale: ka`, браузерным UA;
  - 401 от API → один повторный вход и повтор запроса; второй 401 → `PortalAuthFailed`;
  - ответ Cloudflare 403 `1010` → `PortalUnavailable` с понятным текстом;
  - `publish` грузит каждое фото в `static-api-statements.tnet.ge/v1/files/upload-image` (multipart `file`),
    потом один `POST /v1/statements/create` (multipart; поля из спецификации; `images[n][image_id|url]`);
  - `update` читает `GET /v1/statements/edit/{id}`, накладывает изменения и шлёт `PUT /v1/statements/update/{id}`
    полным телом (57 ключей, `duration_id` = 1 по умолчанию, `bedroom_type_id` явно);
  - 422 → `PortalRejected` с полем и текстом ошибки.
- [ ] **Шаг 3. Реализация.** `MyHomeAuth` (кеш Redis `portals:myhome:token`, TTL 540 с, замок на вход),
  `MyHomeClient` (Http-макрос с заголовками и UA), `MyHomeMapper::toFormFields(ListingDraft): array`.
  Образец — `~/.cursor/script-library/myhome-bearer-token/get_token.py` и
  `myhome-statement-update/update_statement.py`.
- [ ] **Шаг 4.** `composer analyse`; тесты `--filter MyHome`; коммит.

## Задача 3. ss.ge: вход и клиент

**Файлы:** `app/Portals/SsGe/SsGeAuth.php`, `SsGeClient.php`, `SsGeMapper.php`,
`tests/Fixtures/portals/ssge/*.json`, `tests/Feature/Portals/SsGeClientTest.php`.

- [ ] **Шаг 1. Фикстуры.** `csrf.json`, `signin.json`, `login-page.html` (усечённый, с формой), `login-ajax.json`,
  `session.json`, `get-draft-empty.json` (500 «Draft application not found»), `create-draft.json`,
  `upload-image.json`, `create-application.json`, `get-edit.json`, `delete.json`.
- [ ] **Шаг 2. Тесты (`Http::fake`).**
  - вход — 5 запросов в порядке спецификации; куки между ними сохраняются (`Http::withOptions(['cookies' => …])`);
    `returnUrl` берётся из страницы входа; токен кешируется на 55 минут;
  - вход не прошёл (200 без `redirectUrl`, 4xx) → `PortalAuthFailed` без текста пароля в сообщении;
  - `publish`: сначала `get-draft`; если слот занят (200) → `PortalRejected('draft slot busy')`, ничего не пишется;
    свободен (500 «not found») → `create-draft` → `upload-image` × N (base64 `data:image/jpeg`,
    `applicationId`) → `create-application` с `paidServices: []`, `userType: "Broker"`;
  - сбой после `create-draft` → вызывается `delete-draft` (слот освобождён), ошибка пробрасывается;
  - `update`: `get-edit-application?isEdit=true` → `create-edit-application` с `isEdit: true`;
  - `remove`: `change-application-status` с `status: 3`;
  - фото большого размера сжимаются до лимита (см. спайк S6) перед отправкой.
- [ ] **Шаг 3. Реализация.** `SsGeAuth` разбирает страницу входа через `DOMDocument`/`DOMXPath`, а не
  регулярками там, где это возможно; при изменении страницы падает с ясным сообщением. Образец —
  `~/.cursor/script-library/ss-ge-bearer-token/get_token.py` и `ss-ge-create-listing/create_listing.py`.
- [ ] **Шаг 4.** `composer analyse`; тесты `--filter SsGe`; коммит.

## Задача 4. `ListingDraft`, соответствия, контакты агента

**Файлы:** `app/Portals/ListingDraft.php`, `DraftBuilder.php`, `PhoneScrubber.php`, `LocationResolver.php`,
`config/portals/*.php`, `tests/Unit/Portals/*`.

**Интерфейс:**

```php
final readonly class ListingDraft
{
    /** @param list<string> $photoUrls  @param list<string> $amenitySlugs */
    public function __construct(
        public string $dealType,        // 'sale' | 'rent'
        public string $propertyType,    // 'apartment'
        public string $city, public ?string $district, public ?string $subdistrict,
        public ?string $street, public ?string $streetNumber, public ?float $lat, public ?float $lng,
        public ?float $areaTotal, public ?int $rooms, public ?int $bedrooms,
        public ?int $floor, public ?int $floorsTotal,
        public ?string $condition, public ?string $buildingStatus,
        public ?float $priceGel, public ?float $priceUsd, public string $currency, // 'GEL' | 'USD'
        public string $description,     // уже очищено от номеров
        public array $photoUrls, public array $amenitySlugs,
        public string $contactName, public string $contactPhone, // 9 цифр без +995
    ) {}
}
```

- [ ] **Шаг 1. Тесты `DraftBuilder`.** `Listing` с контактом собственника → `ListingDraft` с контактом агента;
  номеров собственника нет ни в одном поле; пустые поля остаются `null`; `priceUsd` и `priceGel`
  берутся как есть; фото — только `url`, без превью.
- [ ] **Шаг 2. Тесты `PhoneScrubber`.** Вырезает `+995 555 12 34 56`, `555123456`, `555-12-34-56`,
  `(995) 555 123 456`; не трогает «3 комнаты», «60 м²», «1300 лари», «5 этаж»; идемпотентен.
- [ ] **Шаг 3. Тесты `LocationResolver`.** Однозначное совпадение → id; несколько → `null` + список
  кандидатов; ответ кешируется на сутки; ошибка портала → `null`, не исключение.
- [ ] **Шаг 4. Реализация.** Статические таблицы в `config/portals/myhome.php` и `ssge.php`; значения из
  спецификации (раздел «Откуда берём данные») и спайка S4. Для телефона — `PhoneNumber` проекта
  (E.164 → 9 цифр).
- [ ] **Шаг 5.** `composer analyse`; тесты `--filter Portals`; коммит.

## Задача 5. База: `portal_publications`

**Файлы:** миграция, `app/Models/PortalPublication.php`, `app/Portals/Publications.php`,
`app/Crm/Journal.php`, `tests/Feature/Portals/PublicationsTest.php`.

- [ ] **Шаг 1. Тесты.** `Publications::start($pin, $user, [Portal::MyHome, Portal::SsGe], $draft)` создаёт
  две записи `queued` и пишет событие `portal.publish_requested`; повтор на тот же портал, пока предыдущая
  `queued|publishing|published`, — исключение `AlreadyPublished`; после `removed` или `failed` — можно;
  `markPublished` сохраняет `external_id`, `external_url`, `published_at`, `expires_at` (MyHome +30 дней);
  `markFailed` пишет `error` и событие; `payload` не содержит ключей `password`, `token`, `authorization`.
- [ ] **Шаг 2. Миграция.** Колонки — из спецификации; `jsonb` для `payload`, `response`;
  `CREATE UNIQUE INDEX portal_publications_active ON portal_publications (listing_pin_id, portal)
  WHERE status IN ('queued','publishing','published') AND deleted_at IS NULL`.
- [ ] **Шаг 3. Реализация.** Модель `PortalPublication` (касты `portal`, `status`, даты), связи
  `pin()`, `user()`; в `Journal` — `PORTAL_PUBLISH_REQUESTED`, `PORTAL_PUBLISHED`, `PORTAL_PUBLISH_FAILED`,
  `PORTAL_UPDATED`, `PORTAL_REMOVED`; `Publications` — сервис с методами `start`, `markPublishing`,
  `markPublished`, `markFailed`, `markRemoved`.
- [ ] **Шаг 4.** `docker compose exec app php artisan migrate`; `composer analyse`; тесты; коммит.

## Задача 6. Джоба и очередь

**Файлы:** `app/Jobs/PublishToPortal.php`, `config/horizon.php`, `app/Notifications/PortalPublished.php`,
`PortalPublishFailed.php`, `lang/*/gshome.php`, `tests/Feature/Portals/PublishToPortalTest.php`.

**Зависит от:** решения по вопросам 1 и 2.

- [ ] **Шаг 1. Тесты.** Клиент подменён фейком через контейнер: успех → запись `published`,
  `PortalPublished` в колокольчик держателю, событие в журнал; `PortalRejected` → `failed`, текст причины;
  `PortalAuthFailed` → `failed` и уведомление владельцу (роль из `OrgAccess`); `tries = 1`; `middleware`
  содержит `WithoutOverlapping('portal:'.$portal)`; при `PORTALS_ENABLED=false` джоба не запускает клиент и
  пишет `failed` с понятным текстом; перед повтором проверяется `external_id`.
- [ ] **Шаг 2. Реализация.** `public int $tries = 1; public int $timeout = 300;`,
  `onQueue('portals')`; в `config/horizon.php` supervisor `portals` (`maxProcesses = 1`) и в окружения.
- [ ] **Шаг 3. Деплой.** `docker compose restart horizon`; на проде — `bin/deploy.sh` перезапускает Horizon;
  добавить переменные на сервер.
- [ ] **Шаг 4.** `composer analyse`; тесты; коммит.

## Задача 7. Экран: кнопка, окно, секция

**Файлы:** `app/Filament/Pages/ListingView.php`, `resources/views/filament/pages/listing-view/portals.blade.php`,
`lang/{ru,en,ka}/gshome.php`, `tests/Feature/Portals/ListingViewPublishTest.php`.

**Зависит от:** вопросов 3, 4, 5.

- [ ] **Шаг 1. Тесты (Livewire).**
  - объект не в работе → действия нет; пин закрыт → нет; чужой пин без прав → нет; держатель и руководитель
    с `canManage` → есть;
  - в окне предзаполнены цена, описание **без номеров собственника**, контакт — имя и телефон держателя;
    нет номера у агента → окно блокирует отправку с подсказкой «добавьте номер в профиль»;
  - отправка с двумя порталами → две записи `queued`, две джобы в `portals`, окно закрылось;
  - только один портал отмечен → одна запись;
  - есть активная публикация на портале → этот портал недоступен в окне, показана ссылка;
  - секция «Опубликовано» показывает статусы, ссылку, `error`, кнопку «Повторить» (только `failed`);
  - язык `ka`, `ru`, `en` — подписи на месте.
- [ ] **Шаг 2. Реализация.** Действие `publishToPortals` рядом с `pinActions()`; форма окна — Filament `Action`
  с `form()`; `visible()` = `$pin && $pin->isActive() && Pins::canManage($pin, $viewer)`; секция — Blade-partial.
  Дизайн — по навыку `friendly-frontend` (мобильный первым, спокойный тон).
- [ ] **Шаг 3.** `docker compose run --rm test composer test --filter Portals`; `composer analyse`;
  инспекции PhpStorm по изменённым файлам; коммит.

## Задача 8. Правка, снятие, продление

**Файлы:** `ListingView.php`, `Publications.php`, `app/Jobs/{UpdateOnPortal,RemoveFromPortal}.php`,
`app/Console/Commands/PortalsRenewReminder.php`.

- [ ] **Шаг 1. Тесты.** «Обновить на порталах» шлёт `update` (MyHome — `PUT` полным телом; ss.ge —
  `create-edit-application` с `isEdit: true`) и пишет `portal.updated`; «Снять» — `remove`, статус `removed`;
  закрытие пина (`Pins::close`/`release`) — по решению вопроса 6 либо снимает, либо предлагает снять;
  `portals:renew-reminder` присылает уведомление за 3 дня до `expires_at` MyHome.
- [ ] **Шаг 2. Реализация** — по образцу задач 6–7; расписание — в `routes/console.php`.
- [ ] **Шаг 3.** `composer analyse`; тесты; коммит.

## Задача 9. Проверка и выкладка

- [ ] **Шаг 1.** `portals:probe` (новая команда): вход на оба портала, `health()` каждого, печать «ок / ошибка»
  без токенов; запускать на сервере перед включением флага.
- [ ] **Шаг 2.** `bin/preflight.sh`, `bin/ship.sh --pr "Публикация на MyHome и ss.ge"`; PR — на ревью владельца.
- [ ] **Шаг 3. Ручная проверка на тесте (под тестовым аккаунтом).** Взять в работу объект → «Опубликовать на
  порталах» → проверить: объявления на обоих порталах, контакты агента, фото, цена, описание без номеров
  собственника; «Обновить»; «Снять»; через сутки — объявление агента появилось в Real Estate и в «Мои».
- [ ] **Шаг 4. Выкладка.** После слияния в `main` — `bin/deploy.sh`; `.env` на сервере; `PORTALS_ENABLED=true`
  включить на тестовой компании, потом на всех.
- [ ] **Шаг 5.** Обновить `docs/GSHome/02_Решения.md` (итог), `14_`/`15_` (находки), `00_Навигатор.md`
  (новый документ `16_Публикация_на_порталах.md` с описанием как работает; план остаётся в `docs/superpowers`).

## Критерии готовности

- Агент публикует объект на MyHome и ss.ge из карточки одной кнопкой; в объявлениях — **его** имя и номер,
  номеров собственника нет нигде (поля, описание).
- Все четыре сценария покрыты тестами с записанными ответами: вход, публикация, правка, снятие.
- Сбой одного портала не мешает другому; причина видна агенту; повтор — кнопкой.
- Ни пароль, ни токен не видны в логах, Bugsnag, `payload`, базе.
- `portals:probe` проходит на сервере; Cloudflare не блокирует.
- Документация: `02_Решения.md`, `14_`/`15_`, навигатор.
